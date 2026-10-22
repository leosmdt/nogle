<?php

declare(strict_types=1);

namespace Nogle\Tests\Ai;

use Nogle\Ai\HttpClient;
use Nogle\Ai\VisionServer;

/** Modelo de visão simulado: registra inícios e sinais em vez de criar processos. */
class FakeVisionServer extends VisionServer
{
    public int $launches = 0;
    /** @var list<int> */
    public array $signals = [];
    public bool $running = false;

    /** @param list<bool> $readiness respostas sucessivas do /health */
    public function __construct(string $dataDir, private array $readiness, private readonly bool $isInstalled = true)
    {
        parent::__construct(new HttpClient(), 'http://127.0.0.1:9', '', [], $dataDir);
    }

    public function installed(): bool
    {
        return $this->isInstalled;
    }

    public function ready(): bool
    {
        return $this->readiness ? array_shift($this->readiness) : false;
    }

    protected function launch(): int
    {
        $this->launches++;
        $this->running = true;
        return 4242;
    }

    protected function alive(int $pid): bool
    {
        return $pid === 4242 && $this->running;
    }

    protected function signal(int $pid, int $signal): void
    {
        $this->signals[] = $signal;
        $this->running = false;
    }

    protected function wait(): void
    {
    }
}
