<?php

declare(strict_types=1);

namespace Nogle\Tests\Ai;

use Nogle\Ai\HttpClient;
use Nogle\Ai\HttpFailure;

/** Cliente HTTP falso: devolve respostas prontas, em ordem, e guarda o que foi enviado. */
final class FakeHttp extends HttpClient
{
    /** @var list<array{method: string, url: string, body: ?string}> */
    public array $requests = [];

    /** @param list<string> $responses */
    public function __construct(private array $responses = [])
    {
    }

    public function request(string $method, string $url, ?string $body = null, string $contentType = '', float $timeout = 1): string
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
        if (!$this->responses) {
            throw new HttpFailure('sem resposta');
        }
        return array_shift($this->responses);
    }
}
