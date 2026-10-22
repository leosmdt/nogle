<?php

// Ponto de entrada: php bin/serve.php [--seed] [--host=127.0.0.1] [--port=8000]
// Espera o MySQL, aplica database/schema.sql e sobe o servidor embutido do PHP.

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Nogle\Database;
use Nogle\DatabaseUnavailable;

$options = getopt('', ['seed', 'host:', 'port:', 'help']);
if (isset($options['help'])) {
    echo "Uso: php bin/serve.php [--seed] [--host=127.0.0.1] [--port=8000]\n"
        . "  --seed  carrega 10 clientes de exemplo se o banco estiver vazio\n";
    exit(0);
}
$host = $options['host'] ?? (getenv('HOST') ?: '127.0.0.1');
$port = $options['port'] ?? (getenv('PORT') ?: '8000');

$db = Database::fromEnvironment();
for ($attempt = 1; ; $attempt++) {
    try {
        $db->pdo();
        break;
    } catch (DatabaseUnavailable $error) {
        if ($attempt === 30) {
            fwrite(STDERR, $error->getMessage() . " Suba o MySQL com: docker compose up -d db\n");
            exit(1);
        }
        sleep(1);
    }
}
$db->migrate();
if (isset($options['seed']) && $db->seed()) {
    echo "Clientes de exemplo carregados.\n";
}

// Vários workers: uma leitura de foto pela IA (até 45 s) não trava a tela para as outras requisições.
putenv('PHP_CLI_SERVER_WORKERS=4');
$root = dirname(__DIR__);
echo "Nogle Clientes: http://" . ($host === '0.0.0.0' ? '127.0.0.1' : $host) . ":$port\n";
$server = proc_open([PHP_BINARY, '-S', "$host:$port", '-t', "$root/public", "$root/public/index.php"], [STDIN, STDOUT, STDERR], $pipes);
exit(proc_close($server));
