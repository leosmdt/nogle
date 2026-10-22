<?php

declare(strict_types=1);

// Autoload das classes Nogle\* sem depender do Composer; o Composer só é usado para rodar os testes.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Nogle\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
