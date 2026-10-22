<?php

declare(strict_types=1);

namespace Nogle;

use PDO;
use PDOException;

/** Conexão MySQL (aberta só quando a primeira consulta precisa dela), esquema e carga de exemplo. */
final class Database
{
    public const ROOT = __DIR__ . '/..';
    private ?PDO $pdo = null;

    /** @param array{host: string, port: string, name: string, user: string, password: string} $config */
    public function __construct(public readonly array $config)
    {
    }

    public static function fromEnvironment(): self
    {
        $env = static fn (string $name, string $default): string => getenv($name) !== false ? (string) getenv($name) : $default;
        return new self([
            'host' => $env('DB_HOST', '127.0.0.1'),
            'port' => $env('DB_PORT', '3307'),
            'name' => $env('DB_NAME', 'nogle'),
            'user' => $env('DB_USER', 'nogle'),
            'password' => $env('DB_PASSWORD', 'nogle'),
        ]);
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            ['host' => $host, 'port' => $port, 'name' => $name] = $this->config;
            try {
                $this->pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $this->config['user'], $this->config['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Prepared statements nativos: inteiros voltam como int, não como texto.
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $error) {
                throw new DatabaseUnavailable("Banco de dados indisponível em $host:$port.", 0, $error);
            }
        }
        return $this->pdo;
    }

    /**
     * Executa $work numa transação: confirma ao final ou desfaz tudo se algo falhar.
     *
     * @template T
     * @param callable(PDO): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    public function migrate(): void
    {
        foreach (self::statements(self::ROOT . '/database/schema.sql') as $statement) {
            $this->pdo()->exec($statement);
        }
    }

    /** Carrega os clientes de exemplo apenas se o banco estiver vazio. */
    public function seed(): bool
    {
        if ((int) $this->pdo()->query('SELECT COUNT(*) FROM clientes')->fetchColumn() > 0) {
            return false;
        }
        foreach (self::statements(self::ROOT . '/database/seed.sql') as $statement) {
            $this->pdo()->exec($statement);
        }
        return true;
    }

    /** @return list<string> comandos de um arquivo .sql, sem comentários de linha */
    private static function statements(string $file): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
        return array_values(array_filter(array_map('trim', preg_split('/;\s*$/m', $sql)), 'strlen'));
    }
}
