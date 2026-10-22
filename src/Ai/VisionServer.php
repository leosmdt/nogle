<?php

declare(strict_types=1);

namespace Nogle\Ai;

/**
 * Ciclo de vida do modelo de visão, que ocupa ~2,5 GB de RAM: sobe na primeira foto e roda em segundo plano.
 *
 * O PHP não guarda estado entre requisições, então o PID fica em data/vision.pid e o último uso em
 * data/vision.last; o bin/start_local.sh desliga o processo depois de 10 minutos sem uso.
 */
class VisionServer
{
    /** @param array<string, int> $files arquivos do modelo e tamanho esperado em bytes */
    public function __construct(
        private readonly HttpClient $http,
        public readonly string $url,
        private readonly string $llamaServer,
        private readonly array $files,
        private readonly string $dataDir,
    ) {
    }

    public function installed(): bool
    {
        if (!is_file($this->llamaServer)) {
            return false;
        }
        foreach ($this->files as $path => $size) {
            if (!is_file($path) || filesize($path) !== $size) {
                return false;
            }
        }
        return true;
    }

    public function ready(): bool
    {
        return $this->http->healthy($this->url);
    }

    /**
     * Executa $work com o modelo no ar, uma foto por vez (as requisições concorrentes esperam na trava).
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(callable $work): mixed
    {
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0775, true);
        }
        $lock = fopen("$this->dataDir/vision.lock", 'c');
        flock($lock, LOCK_EX);
        try {
            $this->ensure();
            return $work();
        } finally {
            touch("$this->dataDir/vision.last");
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function ensure(): void
    {
        if ($this->ready()) {
            return;
        }
        if (!$this->installed()) {
            throw new ModelUnavailable('A leitura de fotos não está instalada. Execute bin/setup_local_ai.sh.');
        }
        $pid = $this->pid();
        if ($pid === null || !$this->alive($pid)) {
            $pid = $this->launch();
            file_put_contents("$this->dataDir/vision.pid", (string) $pid);
            touch("$this->dataDir/vision.last");
        }
        $deadline = microtime(true) + 120;
        while (microtime(true) < $deadline) {
            if ($this->ready()) {
                return;
            }
            if (!$this->alive($pid)) {
                break;
            }
            $this->wait();
        }
        $this->stop();
        throw new ModelUnavailable('O modelo de visão não conseguiu iniciar. Veja data/vision.log.');
    }

    public function stop(): void
    {
        $pid = $this->pid();
        if ($pid !== null && $this->alive($pid)) {
            $this->signal($pid, 15);
            for ($i = 0; $i < 20 && $this->alive($pid); $i++) {
                $this->wait();
            }
            if ($this->alive($pid)) {
                $this->signal($pid, 9);
            }
        }
        @unlink("$this->dataDir/vision.pid");
    }

    /** Inicia o llama-server em segundo plano e devolve o PID; o processo sobrevive ao fim da requisição. */
    protected function launch(): int
    {
        [$model, $mmproj] = array_keys($this->files);
        $command = implode(' ', array_map('escapeshellarg', [
            $this->llamaServer, '--model', $model, '--mmproj', $mmproj, '--alias', 'qwen3-vl-local',
            '--host', '127.0.0.1', '--port', (string) parse_url($this->url, PHP_URL_PORT), '--ctx-size', '4096',
            '--threads', '8', '--parallel', '1', '--no-webui', '--image-max-tokens', '384',
        ]));
        $log = escapeshellarg("$this->dataDir/vision.log");
        // O filho herdaria os descritores abertos do PHP, inclusive o socket do servidor: o modelo prenderia
        // a porta depois que o PHP saísse. O bash fecha todos acima de 2 e vira o modelo (exec), mantendo o PID.
        $closeInherited = 'for fd in $(ls /dev/fd); do [ "$fd" -gt 2 ] && eval "exec $fd>&-"; done 2>/dev/null; ';
        return (int) shell_exec('bash -c ' . escapeshellarg("{$closeInherited}exec nohup $command >> $log 2>&1") . ' & echo $!');
    }

    protected function alive(int $pid): bool
    {
        return function_exists('posix_kill') ? posix_kill($pid, 0) : is_dir("/proc/$pid");
    }

    protected function signal(int $pid, int $signal): void
    {
        function_exists('posix_kill') ? posix_kill($pid, $signal) : exec("kill -$signal $pid");
    }

    protected function wait(): void
    {
        usleep(500_000);
    }

    private function pid(): ?int
    {
        $pid = (int) @file_get_contents("$this->dataDir/vision.pid");
        return $pid > 0 ? $pid : null;
    }
}
