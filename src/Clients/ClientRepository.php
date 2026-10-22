<?php

declare(strict_types=1);

namespace Nogle\Clients;

use Nogle\Database;
use PDO;
use PDOStatement;

/** Todas as consultas de clientes. */
class ClientRepository
{
    public const FIELDS = [
        'nome', 'cpf', 'telefone', 'cep', 'cidade', 'estado',
        'endereco', 'bairro', 'numero', 'complemento',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} clientes filtrados e total do status, sem filtros */
    public function list(bool $active, string $nome = '', string $cpf = '', string $cidade = ''): array
    {
        // A collation da tabela já ignora acentos e maiúsculas no LIKE e na ordenação.
        $rows = $this->query(
            'SELECT * FROM clientes WHERE ativo = ? AND nome LIKE ? AND cpf LIKE ? AND cidade LIKE ? ORDER BY nome, id',
            [(int) $active, self::contains($nome), self::contains($cpf), self::contains($cidade)],
        )->fetchAll();
        $total = (int) $this->query('SELECT COUNT(*) FROM clientes WHERE ativo = ?', [(int) $active])->fetchColumn();
        return [$rows, $total];
    }

    /** @param array<string, string> $data */
    public function create(array $data): array
    {
        return $this->db->transaction(function (PDO $pdo) use ($data): array {
            $this->ensureCpfAvailable($data['cpf']);
            $this->insert($data);
            return $this->find((int) $pdo->lastInsertId());
        });
    }

    /**
     * Atualiza um cliente ativo; devolve null se ele não existir ou estiver inativo.
     *
     * @param array<string, string> $data
     */
    public function update(int $id, array $data): ?array
    {
        return $this->db->transaction(function () use ($id, $data): ?array {
            if (!$this->query('SELECT 1 FROM clientes WHERE id = ? AND ativo = 1 FOR UPDATE', [$id])->fetchColumn()) {
                return null;
            }
            $this->ensureCpfAvailable($data['cpf'], $id);
            $assignments = implode(', ', array_map(static fn (string $field): string => "$field = ?", self::FIELDS));
            $this->query("UPDATE clientes SET $assignments WHERE id = ?", [...self::values($data), $id]);
            return $this->find($id);
        });
    }

    public function setActive(int $id, bool $active): bool
    {
        return $this->query('UPDATE clientes SET ativo = ? WHERE id = ? AND ativo <> ?', [(int) $active, $id, (int) $active])->rowCount() > 0;
    }

    public function setAllActive(bool $active): int
    {
        return $this->query('UPDATE clientes SET ativo = ? WHERE ativo <> ?', [(int) $active, (int) $active])->rowCount();
    }

    public function delete(int $id): bool
    {
        // Exclusão definitiva só depois da inativação, que é reversível.
        return $this->query('DELETE FROM clientes WHERE id = ? AND ativo = 0', [$id])->rowCount() > 0;
    }

    /**
     * Grava os registros numa transação só; CPFs já existentes são ignorados.
     *
     * @param list<array<string, string>> $records
     * @return array{0: int, 1: int} adicionados e ignorados
     */
    public function import(array $records): array
    {
        return $this->db->transaction(function () use ($records): array {
            $added = $skipped = 0;
            foreach ($records as $record) {
                if ($this->query('SELECT 1 FROM clientes WHERE cpf = ?', [$record['cpf']])->fetchColumn()) {
                    $skipped++;
                    continue;
                }
                $this->insert($record);
                $added++;
            }
            return [$added, $skipped];
        });
    }

    /** @return list<string> */
    public function recentNames(int $limit = 40): array
    {
        return $this->query("SELECT nome FROM clientes ORDER BY ativo DESC, atualizado_em DESC LIMIT $limit")->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $totals = $this->query(
            "SELECT COUNT(*) AS total, COALESCE(SUM(ativo), 0) AS ativos,
                COALESCE(SUM(criado_em >= DATE_FORMAT(NOW(), '%Y-%m-01')), 0) AS novos_mes,
                COALESCE(SUM(criado_em >= DATE_FORMAT(NOW() - INTERVAL 1 MONTH, '%Y-%m-01')
                    AND criado_em < DATE_FORMAT(NOW(), '%Y-%m-01')), 0) AS novos_mes_anterior
             FROM clientes",
        )->fetch();
        [$total, $ativos] = [(int) $totals['total'], (int) $totals['ativos']];
        $porEstado = $this->query(
            'SELECT estado, COUNT(*) AS total FROM clientes WHERE ativo = 1 GROUP BY estado ORDER BY total DESC, estado',
        )->fetchAll();
        // A mesma cidade digitada com ou sem acento conta junto (a collation agrupa as grafias);
        // exibe a grafia mais usada, preferindo a que tem maiúsculas e acentos.
        $topCidades = $this->query(
            'WITH grafias AS (
                SELECT cidade COLLATE utf8mb4_bin AS grafia, estado, COUNT(*) AS vezes
                FROM clientes WHERE ativo = 1 GROUP BY grafia, estado
            ), cidades AS (
                SELECT grafia, estado, SUM(vezes) OVER cidade AS total,
                    ROW_NUMBER() OVER (cidade ORDER BY vezes DESC, grafia <> LOWER(grafia) DESC, LENGTH(grafia) DESC, grafia DESC) AS ordem
                FROM grafias
                WINDOW cidade AS (PARTITION BY grafia COLLATE utf8mb4_0900_ai_ci, estado)
            )
            SELECT grafia AS cidade, estado, total FROM cidades WHERE ordem = 1
            ORDER BY total DESC, cidade COLLATE utf8mb4_0900_ai_ci LIMIT 8',
        )->fetchAll();
        return [
            'total' => $total,
            'ativos' => $ativos,
            'inativos' => $total - $ativos,
            'taxa_inativacao' => $total ? round(($total - $ativos) * 100 / $total, 1) : 0,
            'novos_mes' => (int) $totals['novos_mes'],
            'novos_mes_anterior' => (int) $totals['novos_mes_anterior'],
            'por_estado' => $porEstado,
            'top_cidades' => array_map(static fn (array $city): array => [...$city, 'total' => (int) $city['total']], $topCidades),
        ];
    }

    private function ensureCpfAvailable(string $cpf, ?int $id = null): void
    {
        $active = $this->query('SELECT ativo FROM clientes WHERE cpf = ? AND NOT (id <=> ?)', [$cpf, $id])->fetchColumn();
        if ($active === false) {
            return;
        }
        throw new Conflict($active ? 'Este CPF já está cadastrado.'
            : 'Este CPF já está cadastrado para um cliente inativo. Reative-o na aba Inativos.');
    }

    /** @param array<string, string> $data */
    private function insert(array $data): void
    {
        $columns = implode(', ', self::FIELDS);
        $placeholders = implode(', ', array_fill(0, count(self::FIELDS), '?'));
        $this->query("INSERT INTO clientes ($columns) VALUES ($placeholders)", self::values($data));
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        return $this->query('SELECT * FROM clientes WHERE id = ?', [$id])->fetch();
    }

    /** @param list<mixed> $params */
    private function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    /** @param array<string, string> $data */
    private static function values(array $data): array
    {
        return array_map(static fn (string $field): string => $data[$field], self::FIELDS);
    }

    /** Padrão LIKE "contém", com %, _ e \ do texto tratados como caracteres comuns. */
    private static function contains(string $value): string
    {
        return '%' . addcslashes($value, '%_\\') . '%';
    }
}
