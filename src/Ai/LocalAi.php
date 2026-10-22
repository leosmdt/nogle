<?php

declare(strict_types=1);

namespace Nogle\Ai;

use JsonException;
use Nogle\BadRequest;
use Nogle\Clients\ClientRepository;

/** Interpretação de pedidos pelos modelos locais servidos no loopback e validação do que eles devolvem. */
final class LocalAi
{
    public const FIELDS = ClientRepository::FIELDS;
    public const INTENTS = [
        'search', 'new', 'edit', 'inactivate', 'reactivate', 'list',
        'count', 'export', 'import', 'clear', 'help', 'unknown',
    ];
    private const MODEL = 'qwen3-local';
    private const ASKABLE = ['telefone', 'cpf', 'endereco', 'cep', 'cidade'];
    // Nomes que o modelo às vezes usa no lugar dos campos reais.
    private const FIELD_ALIASES = [
        'celular' => 'telefone', 'fone' => 'telefone', 'whatsapp' => 'telefone',
        'rua' => 'endereco', 'logradouro' => 'endereco', 'uf' => 'estado',
        'numero_da_casa' => 'numero', 'numero_casa' => 'numero', 'num' => 'numero',
    ];
    private const SYSTEM_PROMPT = <<<'PROMPT'
        Você interpreta pedidos em português para um cadastro de clientes.
        Responda SOMENTE com JSON no esquema solicitado. Não invente dados.
        Intents: search=buscar ou ver dados/contato de cliente citado por nome/CPF; new=abrir cadastro e preencher campos citados;
        edit=alterar dados de cliente existente; inactivate=inativar/desativar; reactivate=reativar;
        list=mostrar vários clientes, sem pessoa específica; count=contar; export=exportar/baixar CSV;
        import=importar CSV; clear=limpar filtros; help=ajuda sobre o assistente; unknown=pedido fora do cadastro.
        query: só o nome ou CPF da pessoa alvo, sem verbos; vazio se não houver pessoa citada ou se for ele/ela.
        status: ativos/inativos se citado, senão vazio.
        cidade: cidade citada como filtro ("quem mora em X", "clientes de X"), senão vazio.
        ask: dados que o usuário quer ver (telefone, cpf, endereco, cep, cidade); "contato" = telefone; "onde mora" = endereco; vazio se pediu todos os dados.
        fields: só dados novos fornecidos para new/edit, sem máscaras.
        Pode vir um "Pedido anterior" só para dar contexto; interprete o "Pedido atual".
        Exemplo: 'procure a Beatriz' -> {"intent":"search","query":"Beatriz","status":"","cidade":"","ask":[],"fields":{}}
        Exemplo: 'me traga todos os dados do Paulo Reis' -> {"intent":"search","query":"Paulo Reis","status":"","cidade":"","ask":[],"fields":{}}
        Exemplo: 'qual o celular e o CEP da Beatriz?' -> {"intent":"search","query":"Beatriz","status":"","cidade":"","ask":["telefone","cep"],"fields":{}}
        Exemplo: 'quais são os nomes dos clientes?' -> {"intent":"list","query":"","status":"","cidade":"","ask":[],"fields":{}}
        Exemplo: 'contatos dos clientes inativos' -> {"intent":"list","query":"","status":"inativos","cidade":"","ask":["telefone"],"fields":{}}
        Exemplo: 'quem é de Curitiba?' -> {"intent":"list","query":"","status":"","cidade":"Curitiba","ask":[],"fields":{}}
        Exemplo: 'quantos clientes em Curitiba?' -> {"intent":"count","query":"","status":"","cidade":"Curitiba","ask":[],"fields":{}}
        Exemplo: 'cadastre Paulo telefone 11999999999' -> {"intent":"new","query":"","status":"","cidade":"","ask":[],"fields":{"nome":"Paulo","telefone":"11999999999"}}
        Exemplo: 'troca o bairro da Beatriz para Centro' -> {"intent":"edit","query":"Beatriz","status":"","cidade":"","ask":[],"fields":{"bairro":"Centro"}}
        Exemplo: 'Pedido anterior: procure o Paulo / Pedido atual: e o telefone dele?' -> {"intent":"search","query":"","status":"","cidade":"","ask":["telefone"],"fields":{}}
        Exemplo: 'me conta uma piada' -> {"intent":"unknown","query":"","status":"","cidade":"","ask":[],"fields":{}}
        Exemplo: 'exporta a lista' -> {"intent":"export","query":"","status":"","cidade":"","ask":[],"fields":{}}
        Exemplo: 'quero importar uma planilha' -> {"intent":"import","query":"","status":"","cidade":"","ask":[],"fields":{}}

        PROMPT;
    private const VISION_PROMPT = <<<'PROMPT'
        Esta imagem deve conter dados de um cliente (ficha, formulário, documento, cartão, anotação ou print).
        Copie para o JSON os dados que aparecem, exatamente como estão escritos. Não invente nada: campo que não aparece fica vazio.
        nome: nome completo da pessoa. cpf, telefone e cep: só os números. estado: sigla de 2 letras (UF).
        endereco: logradouro com o tipo (Rua, Avenida, Travessa...), sem o número. numero: número do imóvel. complemento: apto, bloco, casa etc.
        Se a imagem não tiver dados de cliente, deixe todos os campos vazios. Responda com JSON compacto, em uma linha.
        PROMPT;
    private const TARGETED = ['search', 'edit', 'inactivate', 'reactivate'];
    private const GENERIC_QUERY = '/^(?:(?:os|as|todos|todas|meus|minhas)\s+)*(?:clientes?|todos|todas|lista|cadastros?)$/u';
    private const ALL_CLIENTS = '/\b(?:todos?\s+(?:os?\s+)?clientes?|todas?\s+(?:as?\s+)?clientes?)\b/u';
    private const PRONOUN = '\b(?:ele|ela|dele|dela|nele|nela|esse|essa|este|esta|desse|dessa|deste|desta|mesmo|mesma)\b';
    private const ASK_KEYWORDS = [
        'telefone' => '/\b(?:telefones?|celular(?:es)?|contatos?|fones?|whats(?:app)?|numero de telefone)\b/u',
        'cpf' => '/\bcpfs?\b/u',
        'endereco' => '/\b(?:enderecos?|onde (?:ele |ela )?mora|rua|logradouro)\b/u',
        'cep' => '/\bceps?\b/u',
    ];
    private const CPF_IN_TEXT = '/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/';
    // "Av. Paulista, nº 1578 - Apto 42": número explícito e o que vem depois dele (complemento).
    private const ADDRESS_NUMBER = '/\s*(?:,\s*|\s+n[º°o]\.?\s*)(?:n[º°o]\.?\s*)?(\d+[a-zA-Z]?)\b\s*(?:[-–,]\s*(.*))?$/u';
    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ª' => 'a', 'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'º' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
    ];

    public function __construct(
        private readonly HttpClient $http,
        private readonly VisionServer $vision,
        private readonly string $llamaUrl,
        private readonly string $whisperUrl,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $root = dirname(__DIR__, 2);
        $env = static fn (string $name, string $default): string => getenv($name) !== false ? (string) getenv($name) : $default;
        $http = new HttpClient();
        // O start_local.sh informa o executável da plataforma; o padrão é o do Linux instalado pelo setup.
        $vision = new VisionServer(
            $http,
            $env('NOGLE_VISION_URL', 'http://127.0.0.1:8083'),
            $env('NOGLE_LLAMA_SERVER', "$root/.runtime/llama/llama-b11763/llama-server"),
            [
                "$root/data/models/Qwen3-VL-2B-Instruct-Q8_0.gguf" => 1834427296,
                "$root/data/models/mmproj-Qwen3-VL-2B-Instruct-Q8_0.gguf" => 445053056,
            ],
            "$root/data",
        );
        return new self($http, $vision, $env('NOGLE_LLAMA_URL', 'http://127.0.0.1:8081'), $env('NOGLE_WHISPER_URL', 'http://127.0.0.1:8082'));
    }

    public function vision(): VisionServer
    {
        return $this->vision;
    }

    public function modelReady(): bool
    {
        return $this->http->healthy($this->llamaUrl);
    }

    public function speechReady(): bool
    {
        return $this->http->healthy($this->whisperUrl);
    }

    /** @return array<string, mixed> */
    public function parseCommand(mixed $message, mixed $context = ''): array
    {
        if (!is_string($message) || mb_strlen(trim($message)) < 1 || mb_strlen(trim($message)) > 500) {
            throw new BadRequest('Digite um pedido de até 500 caracteres.');
        }
        $context = is_string($context) ? mb_substr(trim($context), 0, 500) : '';
        $message = trim($message);
        return self::cleanPlan($this->askModel($message, $context), $message, $context);
    }

    /**
     * Converte um WAV gravado no navegador em texto com o Whisper local.
     *
     * Os nomes cadastrados entram no prompt para o Whisper grafá-los certo; tudo fica nesta máquina.
     *
     * @param list<string> $names
     */
    public function transcribe(string $wav, array $names = []): string
    {
        if (strlen($wav) < 44 || !str_starts_with($wav, 'RIFF') || substr($wav, 8, 4) !== 'WAVE') {
            throw new BadRequest('Envie o áudio em formato WAV.');
        }
        $prompt = 'Pedido para o cadastro de clientes: buscar, cadastrar, editar, inativar, reativar, CPF, CEP, telefone.';
        if ($names) {
            $prompt = mb_substr("$prompt Clientes: " . implode(', ', $names) . '.', 0, 600);
        }
        $boundary = bin2hex(random_bytes(16));
        $body = "--$boundary\r\nContent-Disposition: form-data; name=\"response_format\"\r\n\r\njson\r\n"
            . "--$boundary\r\nContent-Disposition: form-data; name=\"prompt\"\r\n\r\n$prompt\r\n"
            . "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"pedido.wav\"\r\n"
            . "Content-Type: audio/wav\r\n\r\n$wav\r\n--$boundary--\r\n";
        try {
            $result = json_decode($this->http->request('POST', "$this->whisperUrl/inference", $body,
                "multipart/form-data; boundary=$boundary", 60), true, 512, JSON_THROW_ON_ERROR);
        } catch (HttpFailure | JsonException $error) {
            throw new ModelUnavailable('A transcrição local não respondeu. Tente de novo ou digite o pedido.', 0, $error);
        }
        // O Whisper marca silêncio e ruído entre colchetes ou parênteses, como [BLANK_AUDIO] ou (música).
        $spoken = preg_replace('/\[[^\]]*\]|\([^)]*\)/u', ' ', (string) (is_array($result) ? ($result['text'] ?? '') : ''));
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $spoken)), 0, 500);
    }

    /**
     * Extrai os campos de cadastro de uma foto com o modelo de visão local.
     *
     * @return array<string, string>
     */
    public function readClientImage(string $image): array
    {
        $mime = match (true) {
            str_starts_with($image, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($image, "\x89PNG") => 'image/png',
            str_starts_with($image, 'RIFF') && substr($image, 8, 4) === 'WEBP' => 'image/webp',
            default => throw new BadRequest('Envie uma imagem JPEG, PNG ou WebP.'),
        };
        $payload = [
            'model' => 'qwen3-vl-local',
            // Instruções antes da imagem: o servidor reaproveita esse prefixo já processado entre fotos.
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => self::VISION_PROMPT],
                ['type' => 'image_url', 'image_url' => ['url' => "data:$mime;base64," . base64_encode($image)]],
            ]]],
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'cliente', 'strict' => true, 'schema' => [
                'type' => 'object',
                'properties' => array_fill_keys(self::FIELDS, ['type' => 'string']),
                'required' => self::FIELDS,
                'additionalProperties' => false,
            ]]],
            'temperature' => 0,
            'max_tokens' => 400,
            'stream' => false,
        ];
        $raw = $this->vision->run(function () use ($payload): mixed {
            try {
                return self::chatJson($this->http->request('POST', "{$this->vision->url}/v1/chat/completions",
                    json_encode($payload, JSON_THROW_ON_ERROR), 'application/json', 180));
            } catch (HttpFailure | JsonException $error) {
                throw new ModelUnavailable('A leitura de fotos local não respondeu. Tente de novo ou digite o pedido.', 0, $error);
            }
        });
        $fields = self::cleanFields($raw);
        // O modelo às vezes deixa número e complemento dentro da rua, ou pega o número do apartamento.
        $street = $fields['endereco'] ?? '';
        if (preg_match(self::ADDRESS_NUMBER, $street, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
            $fields['endereco'] = trim(substr($street, 0, $match[0][1]));
            $fields['numero'] = $match[1][0];
            $complement = trim($match[2][0] ?? '');
            if ($complement !== '' && ($fields['complemento'] ?? '') === '') {
                $fields['complemento'] = $complement;
            }
        }
        if (isset($fields['estado'])) {
            $fields['estado'] = mb_strtoupper($fields['estado']);
            if (!preg_match('/^[A-Z]{2}$/', $fields['estado'])) {
                unset($fields['estado']);
            }
        }
        if (isset($fields['cep']) && strlen($fields['cep']) !== 8) {
            unset($fields['cep']);
        }
        return $fields;
    }

    /** @return array<string, mixed> */
    private function askModel(string $message, string $context): array
    {
        $payload = [
            'model' => self::MODEL,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $context !== '' ? "Pedido anterior: $context\nPedido atual: $message" : $message],
            ],
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'pedido', 'strict' => true, 'schema' => [
                'type' => 'object',
                'properties' => [
                    'intent' => ['type' => 'string', 'enum' => self::INTENTS],
                    'query' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => ['ativos', 'inativos', '']],
                    'cidade' => ['type' => 'string'],
                    'ask' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::ASKABLE]],
                    'fields' => [
                        'type' => 'object',
                        'properties' => array_fill_keys(self::FIELDS, ['type' => 'string']),
                        'additionalProperties' => false,
                    ],
                ],
                'required' => ['intent', 'query', 'status', 'cidade', 'ask', 'fields'],
                'additionalProperties' => false,
            ]]],
            'chat_template_kwargs' => ['enable_thinking' => false],
            'temperature' => 0,
            'max_tokens' => 260,
            'stream' => false,
        ];
        try {
            $plan = self::chatJson($this->http->request('POST', "$this->llamaUrl/v1/chat/completions",
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'application/json', 75));
        } catch (HttpFailure | JsonException $error) {
            throw new ModelUnavailable('A IA local não respondeu. Os comandos rápidos continuam disponíveis.', 0, $error);
        }
        if (!is_array($plan) || !in_array($plan['intent'] ?? null, self::INTENTS, true)) {
            throw new ModelUnavailable('A IA local retornou um pedido que não pôde ser interpretado.');
        }
        return $plan;
    }

    /** Conteúdo JSON da primeira resposta de uma chamada /v1/chat/completions. */
    private static function chatJson(string $response): mixed
    {
        $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        $content = is_array($result) ? ($result['choices'][0]['message']['content'] ?? null) : null;
        if (!is_string($content)) {
            throw new JsonException('Resposta sem conteúdo.');
        }
        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Valida a saída do modelo e corrige confusões recorrentes de um modelo pequeno.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public static function cleanPlan(array $plan, string $message, string $context): array
    {
        $intent = $plan['intent'];
        $folded = self::fold($message);
        $status = in_array($plan['status'] ?? null, ['ativos', 'inativos'], true) ? $plan['status'] : '';
        $cidade = self::text($plan['cidade'] ?? null, 80);
        if ($cidade !== '' && !preg_match('/\b(?:em|de|do|da|no|na)\s+' . preg_quote(self::fold($cidade), '/') . '\b/u', $folded)) {
            $cidade = '';
        }
        $fields = in_array($intent, ['new', 'edit'], true) ? self::cleanFields($plan['fields'] ?? null) : [];
        $ask = in_array($intent, ['search', 'list'], true) ? self::cleanAsk($plan['ask'] ?? null, $folded) : [];
        $query = self::cleanQuery(self::text($plan['query'] ?? null), $message, $cidade);
        $allClients = in_array($intent, ['inactivate', 'reactivate'], true) && preg_match(self::ALL_CLIENTS, $folded);
        if ($allClients) {
            $query = '';
        }
        if ($intent === 'search' && preg_match(self::CPF_IN_TEXT, $message, $cpf) && !preg_match('/\d/', $query)) {
            $query = preg_replace('/\D/', '', $cpf[0]);
        }
        if (ctype_digit($query) && $ask === ['cpf']) {
            $ask = [];
        }

        // Sem nome citado, pronomes ou continuações ("e o CPF?") apontam para o cliente do pedido anterior.
        $ref = in_array($intent, self::TARGETED, true) && $query === ''
            && (preg_match('/' . self::PRONOUN . '/u', $folded) || ($context !== '' && ($intent !== 'search' || $ask)));
        if ($intent === 'list' && $query !== '') {
            $intent = 'search';
        } elseif ($intent === 'search' && $query === '' && !$ref) {
            $intent = 'list';
        }

        return [
            'intent' => $intent,
            'query' => $query,
            'status' => $status,
            'cidade' => $cidade,
            'ask' => $ask,
            'ref' => $ref,
            'all' => $allClients,
            'fields' => $fields,
        ];
    }

    /** @return array<string, string> */
    public static function cleanFields(mixed $rawFields): array
    {
        $fields = [];
        foreach (is_array($rawFields) ? $rawFields : [] as $name => $value) {
            $name = self::fold((string) $name);
            $name = self::FIELD_ALIASES[$name] ?? $name;
            $value = self::text($value);
            // Aspas ou chaves num valor indicam JSON vazado do modelo, não um dado digitado.
            if (in_array($name, self::FIELDS, true) && $value !== '' && !preg_match('/["{}]/', $value)) {
                $fields[$name] = in_array($name, ['cpf', 'telefone', 'cep'], true) ? preg_replace('/\D/', '', $value) : $value;
            }
        }
        return $fields;
    }

    /** @return list<string> */
    private static function cleanAsk(mixed $rawAsk, string $folded): array
    {
        // Palavras do pedido atual são mais confiáveis; o modelo às vezes repete o que foi pedido antes.
        $ask = array_keys(array_filter(self::ASK_KEYWORDS, static fn (string $pattern): bool => (bool) preg_match($pattern, $folded)));
        if (!$ask) {
            $raw = is_array($rawAsk) ? $rawAsk : [];
            $ask = array_values(array_filter(self::ASKABLE, static fn (string $item): bool => in_array($item, $raw, true)));
        }
        return count($ask) === count(self::ASKABLE) ? [] : $ask;
    }

    private static function cleanQuery(string $query, string $message, string $cidade): string
    {
        // Modelos pequenos frequentemente mantêm o artigo do pedido ("inative o Leonardo")
        // mesmo quando o schema pede somente o nome.
        $query = trim(preg_replace('/^(?:o|a)\s+(?:cliente\s+)?/iu', '', $query));
        $query = trim(preg_replace('/^cliente\s+/iu', '', $query));
        $folded = self::fold($query);
        if (preg_match(self::GENERIC_QUERY, $folded) || ($cidade !== '' && $folded === self::fold($cidade))
            || preg_match('/^' . self::PRONOUN . '$/u', $folded)) {
            return '';
        }
        // O alvo precisa estar no pedido atual. Se veio do pedido anterior, é o cliente já mostrado e o navegador
        // sabe exatamente qual é; se não veio de lugar nenhum, o modelo inventou (às vezes copia nomes dos exemplos).
        $digits = preg_replace('/\D/', '', $query);
        if (!str_contains(self::fold($message), $folded) && !($digits !== '' && str_contains(preg_replace('/\D/', '', $message), $digits))) {
            return '';
        }
        return $query;
    }

    private static function text(mixed $value, int $limit = 180): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $limit) : '';
    }

    /** Minúsculas e sem acentos, para comparar o texto do pedido com o que o modelo devolveu. */
    public static function fold(string $value): string
    {
        return trim(strtr(mb_strtolower($value), self::ACCENTS));
    }
}
