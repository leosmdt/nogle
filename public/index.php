<?php

// Front controller do servidor embutido do PHP (php -S): toda requisição, inclusive de arquivos estáticos, passa por aqui.

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Nogle\Ai\LocalAi;
use Nogle\App;
use Nogle\Clients\ClientRepository;
use Nogle\Database;
use Nogle\Http\Request;

$app = new App(new ClientRepository(Database::fromEnvironment()), LocalAi::fromEnvironment(), __DIR__);
$app->handle(Request::fromGlobals())->send();
