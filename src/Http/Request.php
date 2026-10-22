<?php

declare(strict_types=1);

namespace Nogle\Http;

use Nogle\BadRequest;

/** Requisição HTTP já decomposta; o corpo só é lido depois de conferidos tipo e tamanho. */
final class Request
{
    private ?string $body = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers nomes em minúsculas
     * @param \Closure(): string $readBody
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $headers,
        private readonly \Closure $readBody,
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key])) {
                $headers[$name] = (string) $_SERVER[$key];
            }
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            is_string($path) ? $path : '/',
            $query,
            $headers,
            static fn (): string => (string) file_get_contents('php://input'),
        );
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function hostname(): string
    {
        return strtolower(preg_replace('/:\d+$/', '', $this->header('host')));
    }

    /** Parâmetro da query string; valores que não são texto (como nome[]=x) viram o padrão. */
    public function param(string $name, string $default = ''): string
    {
        $value = $this->query[$name] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /** @param list<string> $contentTypes */
    public function body(array $contentTypes, int $limit, string $invalidType, string $tooLarge): string
    {
        $type = strtolower(trim(explode(';', $this->header('content-type'), 2)[0]));
        if (!in_array($type, $contentTypes, true)) {
            throw new BadRequest($invalidType);
        }
        $length = $this->header('content-length') ?: '0';
        if (!preg_match('/^\d{1,12}$/', $length)) {
            throw new BadRequest('Tamanho da requisição inválido.');
        }
        if ((int) $length <= 0 || (int) $length > $limit) {
            throw new BadRequest($tooLarge);
        }
        $this->body ??= ($this->readBody)();
        if ($this->body === '' || strlen($this->body) > $limit) {
            throw new BadRequest($tooLarge);
        }
        return $this->body;
    }

    /** Corpo JSON decodificado; objetos viram stdClass para distinguir {} de []. */
    public function json(int $limit): mixed
    {
        $body = $this->body(['application/json'], $limit, 'Envie Content-Type: application/json.',
            'O corpo da requisição está vazio ou é muito grande.');
        try {
            return json_decode($body, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequest('JSON inválido.');
        }
    }
}
