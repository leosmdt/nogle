<?php

declare(strict_types=1);

namespace Nogle;

use Nogle\Ai\LocalAi;
use Nogle\Ai\ModelUnavailable;
use Nogle\Clients\ClientRepository;
use Nogle\Clients\Conflict;
use Nogle\Clients\Csv;
use Nogle\Clients\Validation;
use Nogle\Http\Request;
use Nogle\Http\Response;
use PDOException;
use Throwable;
use stdClass;

/** Rotas da API e arquivos da interface: recebe uma Request e devolve uma Response. */
final class App
{
    public const MAX_BODY = 16_384;
    public const MAX_IMAGE_BODY = 4_000_000;
    public const MAX_AUDIO_BODY = 600_000; // cerca de 18 s de WAV mono 16 kHz; o navegador limita a gravação a 15 s
    // O servidor só atende pelo loopback; recusar outros nomes de host impede ataques de DNS rebinding.
    private const ALLOWED_HOSTS = ['127.0.0.1', 'localhost'];
    // IDs com mais de 18 dígitos não cabem num inteiro; nem chegam ao banco.
    private const CLIENT_ID = '(\d{1,18})';
    private const STATIC_FILES = [
        '/' => ['index.html', 'text/html; charset=utf-8'],
        '/styles.css' => ['styles.css', 'text/css; charset=utf-8'],
        '/app.js' => ['app.js', 'text/javascript; charset=utf-8'],
        '/dashboard.js' => ['dashboard.js', 'text/javascript; charset=utf-8'],
        '/assistant.js' => ['assistant.js', 'text/javascript; charset=utf-8'],
    ];

    /** @var \Closure(Throwable): void */
    private readonly \Closure $log;

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly LocalAi $ai,
        private readonly string $publicDir,
        ?\Closure $log = null,
    ) {
        $this->log = $log ?? static function (Throwable $error): void {
            error_log((string) $error);
        };
    }

    public function handle(Request $request): Response
    {
        if (!in_array($request->hostname(), self::ALLOWED_HOSTS, true)) {
            return Response::json(403, ['erro' => 'Host não permitido.']);
        }
        if ($request->method === 'GET' && isset(self::STATIC_FILES[$request->path])) {
            [$file, $type] = self::STATIC_FILES[$request->path];
            return new Response(200, (string) file_get_contents("$this->publicDir/$file"), $type);
        }
        $action = $this->route($request);
        if ($action === null) {
            return Response::json(404, ['erro' => 'Recurso não encontrado.']);
        }
        try {
            return $action();
        } catch (BadRequest $error) {
            return Response::json(400, ['erro' => $error->getMessage()]);
        } catch (Conflict $error) {
            return Response::json(409, ['erro' => $error->getMessage()]);
        } catch (ModelUnavailable | DatabaseUnavailable $error) {
            return Response::json(503, ['erro' => $error->getMessage()]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                // Corrida entre a verificação do CPF e a gravação: a chave única do banco decide.
                return Response::json(409, ['erro' => 'Este CPF já está cadastrado.']);
            }
            ($this->log)($error);
            return Response::json(500, ['erro' => 'Erro interno no servidor. Tente novamente.']);
        } catch (Throwable $error) {
            ($this->log)($error);
            return Response::json(500, ['erro' => 'Erro interno no servidor. Tente novamente.']);
        }
    }

    /** @return (\Closure(): Response)|null */
    private function route(Request $r): ?\Closure
    {
        $path = $r->path;
        switch ($r->method) {
            case 'GET':
                return match ($path) {
                    '/api/clientes' => fn () => $this->listClients($r),
                    '/api/clientes/export' => fn () => $this->exportClients($r),
                    '/api/dashboard' => fn () => Response::json(200, $this->clients->dashboard()),
                    '/api/assistant/status' => fn () => $this->assistantStatus(),
                    default => null,
                };
            case 'POST':
                return match ($path) {
                    '/api/clientes' => fn () => $this->createClient($r),
                    '/api/clientes/import' => fn () => $this->importClients($r),
                    '/api/assistant' => fn () => $this->assistantText($r),
                    '/api/assistant/audio' => fn () => $this->assistantAudio($r),
                    '/api/assistant/image' => fn () => $this->assistantImage($r),
                    default => null,
                };
            case 'PUT':
                if (preg_match('#^/api/clientes/' . self::CLIENT_ID . '$#', $path, $m)) {
                    return fn () => $this->updateClient($r, (int) $m[1]);
                }
                return null;
            case 'PATCH':
                if (preg_match('#^/api/clientes/(inativar|reativar)$#', $path, $m)) {
                    return fn () => $this->setAllActive($r, $m[1] === 'reativar');
                }
                if (preg_match('#^/api/clientes/' . self::CLIENT_ID . '/(inativar|reativar)$#', $path, $m)) {
                    return fn () => $this->setActive((int) $m[1], $m[2] === 'reativar');
                }
                return null;
            case 'DELETE':
                if (preg_match('#^/api/clientes/' . self::CLIENT_ID . '$#', $path, $m)) {
                    return fn () => $this->deleteClient((int) $m[1]);
                }
                return null;
        }
        return null;
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    private function filteredClients(Request $r): array
    {
        $status = $r->param('status', 'ativos');
        if (!in_array($status, ['ativos', 'inativos'], true)) {
            throw new BadRequest('Filtro de status inválido.');
        }
        return $this->clients->list(
            $status === 'ativos',
            mb_substr(trim($r->param('nome')), 0, 180),
            substr(preg_replace('/\D/', '', $r->param('cpf')), 0, 11),
            mb_substr(trim($r->param('cidade')), 0, 80),
        );
    }

    private function listClients(Request $r): Response
    {
        [$clients, $total] = $this->filteredClients($r);
        return Response::json(200, ['clientes' => $clients, 'total' => $total]);
    }

    private function exportClients(Request $r): Response
    {
        [$clients] = $this->filteredClients($r);
        return new Response(200, Csv::export($clients), 'text/csv; charset=utf-8',
            ['Content-Disposition' => 'attachment; filename="clientes.csv"']);
    }

    private function createClient(Request $r): Response
    {
        $client = $this->clients->create(Validation::normalize($this->jsonObject($r, 'Envie os dados do cliente em um objeto JSON.')));
        return Response::json(201, ['cliente' => $client]);
    }

    private function updateClient(Request $r, int $id): Response
    {
        $client = $this->clients->update($id, Validation::normalize($this->jsonObject($r, 'Envie os dados do cliente em um objeto JSON.')));
        if ($client === null) {
            return Response::json(404, ['erro' => 'Cliente ativo não encontrado.']);
        }
        return Response::json(200, ['cliente' => $client]);
    }

    private function setActive(int $id, bool $active): Response
    {
        if (!$this->clients->setActive($id, $active)) {
            return Response::json(404, ['erro' => 'Cliente não encontrado neste status.']);
        }
        return Response::json(200, ['mensagem' => $active ? 'Cliente reativado.' : 'Cliente inativado.']);
    }

    private function setAllActive(Request $r, bool $active): Response
    {
        // Ação em massa exige confirmação explícita no corpo, não só na interface.
        $body = $this->jsonObject($r, 'Confirme a operação em massa enviando {"confirmar": true}.');
        if (($body['confirmar'] ?? null) !== true) {
            throw new BadRequest('Confirme a operação em massa enviando {"confirmar": true}.');
        }
        return Response::json(200, [
            'mensagem' => $active ? 'Clientes reativados.' : 'Clientes inativados.',
            'quantidade' => $this->clients->setAllActive($active),
        ]);
    }

    private function deleteClient(int $id): Response
    {
        if (!$this->clients->delete($id)) {
            return Response::json(404, ['erro' => 'Cliente inativo não encontrado.']);
        }
        return Response::json(200, ['mensagem' => 'Cliente excluído definitivamente.']);
    }

    private function importClients(Request $r): Response
    {
        $content = $r->body(['text/csv'], Csv::MAX_BYTES, 'Envie um arquivo CSV.', 'O CSV está vazio ou excede 500 KB.');
        // Uma transação só: se qualquer linha falhar, nada é gravado.
        [$added, $skipped] = $this->clients->import(Csv::parse($content));
        return Response::json(200, ['adicionados' => $added, 'ignorados' => $skipped]);
    }

    private function assistantStatus(): Response
    {
        return Response::json(200, [
            'available' => $this->ai->modelReady(),
            'speech' => $this->ai->speechReady(),
            'vision' => $this->ai->vision()->installed(),
            'vision_loaded' => $this->ai->vision()->ready(),
            'model' => 'Qwen3 1.7B local',
        ]);
    }

    private function assistantText(Request $r): Response
    {
        $body = $this->jsonObject($r, 'Envie um pedido em JSON.');
        $plan = $this->ai->parseCommand($body['message'] ?? null, $body['context'] ?? '');
        return Response::json(200, ['plan' => self::planJson($plan), 'mode' => 'local-ai']);
    }

    private function assistantAudio(Request $r): Response
    {
        $wav = $r->body(['audio/wav'], self::MAX_AUDIO_BODY, 'Envie o áudio em formato WAV.', 'O áudio está vazio ou passa de 15 segundos.');
        $transcript = $this->ai->transcribe($wav, $this->clients->recentNames());
        if ($transcript === '') {
            return Response::json(422, ['erro' => 'Não consegui entender o áudio. Fale mais perto do microfone ou digite o pedido.']);
        }
        $plan = $this->ai->parseCommand($transcript, $r->param('context'));
        return Response::json(200, ['transcript' => $transcript, 'plan' => self::planJson($plan), 'mode' => 'local-ai']);
    }

    private function assistantImage(Request $r): Response
    {
        $image = $r->body(['image/jpeg', 'image/png', 'image/webp'], self::MAX_IMAGE_BODY,
            'Envie uma imagem JPEG, PNG ou WebP.', 'A imagem está vazia ou passa de 4 MB.');
        $fields = $this->ai->readClientImage($image);
        if (!$fields) {
            return Response::json(422, ['erro' => 'Não encontrei dados de cliente na foto. Tente uma imagem mais nítida e de perto.']);
        }
        $plan = ['intent' => 'new', 'query' => '', 'status' => '', 'cidade' => '', 'ask' => [], 'ref' => false, 'fields' => $fields];
        return Response::json(200, ['plan' => self::planJson($plan), 'mode' => 'local-ai']);
    }

    /** @return array<string, mixed> */
    private function jsonObject(Request $r, string $message): array
    {
        $body = $r->json(self::MAX_BODY);
        if (!$body instanceof stdClass) {
            throw new BadRequest($message);
        }
        return get_object_vars($body);
    }

    /** O navegador espera "fields" como objeto JSON, mesmo vazio. */
    private static function planJson(array $plan): array
    {
        return [...$plan, 'fields' => (object) $plan['fields']];
    }
}
