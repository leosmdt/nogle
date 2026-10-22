<?php

declare(strict_types=1);

namespace Nogle\Ai;

/** Cliente HTTP mínimo para os modelos locais, sem extensões além das de stream do PHP. */
class HttpClient
{
    /** Devolve o corpo da resposta; lança HttpFailure em erro de rede ou status fora de 2xx. */
    public function request(string $method, string $url, ?string $body = null, string $contentType = '', float $timeout = 1): string
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $body === null ? '' : "Content-Type: $contentType\r\n",
            'content' => $body ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? null);
        if ($response === false || !$headers) {
            throw new HttpFailure("Sem resposta de $url.");
        }
        $status = (int) (explode(' ', $headers[0])[1] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new HttpFailure("$url respondeu $status.");
        }
        return $response;
    }

    public function healthy(string $url): bool
    {
        try {
            $this->request('GET', "$url/health");
            return true;
        } catch (HttpFailure) {
            return false;
        }
    }
}
