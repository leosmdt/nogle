<?php

declare(strict_types=1);

namespace Nogle\Tests\Api;

use Nogle\Ai\HttpClient;
use Nogle\Ai\LocalAi;
use Nogle\Ai\VisionServer;
use Nogle\App;
use Nogle\Clients\ClientRepository;
use Nogle\Database;
use Nogle\DatabaseUnavailable;
use Nogle\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Base dos testes da API: chama o App diretamente (sem servidor HTTP) sobre o banco nogle_test,
 * esvaziado antes de cada teste. Requer o MySQL do docker compose no ar.
 */
abstract class ApiTestCase extends TestCase
{
    protected const VALID = [
        'nome' => 'Mariana Teixeira', 'cpf' => '529.982.247-25', 'telefone' => '(11) 98765-4321',
        'cep' => '01310-200', 'cidade' => 'São Paulo', 'estado' => 'sp', 'endereco' => 'Avenida Paulista',
        'bairro' => 'Bela Vista', 'numero' => '1578', 'complemento' => '',
    ];
    protected const OTHER_CPF = '111.444.777-35';
    protected const HEADER = 'nome;cpf;telefone;cep;cidade;estado;endereco;bairro;numero;complemento';

    protected static Database $db;
    protected App $app;

    public static function setUpBeforeClass(): void
    {
        self::$db = Database::fromEnvironment();
        try {
            self::$db->migrate();
        } catch (DatabaseUnavailable $error) {
            self::fail($error->getMessage() . ' Suba o MySQL dos testes com: docker compose up -d db');
        }
    }

    protected function setUp(): void
    {
        self::$db->pdo()->exec('TRUNCATE TABLE clientes');
        $this->app = self::makeApp(new ClientRepository(self::$db));
    }

    protected static function makeApp(ClientRepository $clients): App
    {
        return new App($clients, self::offlineAi(), dirname(__DIR__, 2) . '/public', static function (): void {
            // Logger mudo: os erros 500 provocados de propósito nos testes não poluem a saída do PHPUnit.
        });
    }

    /** IA apontando para uma porta fechada: responde como se os modelos não estivessem rodando. */
    protected static function offlineAi(): LocalAi
    {
        $http = new HttpClient();
        $vision = new VisionServer($http, 'http://127.0.0.1:9', '/nao/instalado', [], sys_get_temp_dir());
        return new LocalAi($http, $vision, 'http://127.0.0.1:9', 'http://127.0.0.1:9');
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: mixed} status e corpo (JSON decodificado ou texto)
     */
    protected function call(string $method, string $uri, mixed $body = null, string $contentType = 'application/json', array $headers = []): array
    {
        // Texto vai como está (permite enviar JSON quebrado ou CSV); arrays e objetos viram JSON.
        $raw = $body === null || is_string($body) ? $body : json_encode($body);
        $headers = [
            'host' => '127.0.0.1:8000',
            ...($raw !== null ? ['content-type' => $contentType, 'content-length' => (string) strlen($raw)] : []),
            ...array_change_key_case($headers),
        ];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = new Request($method, (string) parse_url($uri, PHP_URL_PATH), $query, $headers, static fn (): string => (string) $raw);
        $response = $this->app->handle($request);
        $decoded = str_starts_with($response->contentType, 'application/json') ? json_decode($response->body, true) : $response->body;
        return [$response->status, $decoded];
    }

    /** @param array<string, string> $changes */
    protected function create(array $changes = []): array
    {
        [$status, $body] = $this->call('POST', '/api/clientes', [...self::VALID, ...$changes]);
        $this->assertSame(201, $status, (string) json_encode($body));
        return $body['cliente'];
    }

    protected function total(string $status = 'ativos'): int
    {
        return $this->call('GET', "/api/clientes?status=$status")[1]['total'];
    }
}
