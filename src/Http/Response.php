<?php

declare(strict_types=1);

namespace Nogle\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $contentType,
        public readonly array $headers = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function json(int $status, array $payload): self
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;
        return new self($status, (string) json_encode($payload, $flags), 'application/json; charset=utf-8');
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        header('Content-Length: ' . strlen($this->body));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }
}
