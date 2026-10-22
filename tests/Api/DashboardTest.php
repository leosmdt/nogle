<?php

declare(strict_types=1);

namespace Nogle\Tests\Api;

final class DashboardTest extends ApiTestCase
{
    private function setCreated(int $id, string $sqlDate): void
    {
        self::$db->pdo()->prepare("UPDATE clientes SET criado_em = $sqlDate WHERE id = ?")->execute([$id]);
    }

    public function testEmptyDatabase(): void
    {
        [$status, $body] = $this->call('GET', '/api/dashboard');
        $this->assertSame(200, $status);
        $this->assertSame([0, 0, 0, 0], [$body['total'], $body['ativos'], $body['inativos'], $body['taxa_inativacao']]);
        $this->assertSame([[], []], [$body['por_estado'], $body['top_cidades']]);
    }

    public function testIndicatorsAndGeography(): void
    {
        $sp = $this->create();
        $this->create(['nome' => 'Ana', 'cpf' => self::OTHER_CPF, 'cidade' => 'sao paulo', 'estado' => 'SP']);
        $rs = $this->create(['nome' => 'Bruno', 'cpf' => '935.411.347-80', 'cidade' => 'Porto Alegre', 'estado' => 'RS']);
        $inactive = $this->create(['nome' => 'Carla', 'cpf' => '526.018.159-06', 'cidade' => 'Curitiba', 'estado' => 'PR']);
        $this->call('PATCH', "/api/clientes/{$inactive['id']}/inativar");
        $this->setCreated($sp['id'], "DATE_FORMAT(NOW(), '%Y-%m-01') - INTERVAL 10 DAY");
        $this->setCreated($rs['id'], 'NOW() - INTERVAL 400 DAY');

        $body = $this->call('GET', '/api/dashboard')[1];
        $this->assertSame([4, 3, 1], [$body['total'], $body['ativos'], $body['inativos']]);
        $this->assertSame(25.0, $body['taxa_inativacao']);
        $this->assertSame([2, 1], [$body['novos_mes'], $body['novos_mes_anterior']]);
        // Só ativos entram na distribuição; cidades com grafias diferentes de acento se somam.
        $this->assertSame([['estado' => 'SP', 'total' => 2], ['estado' => 'RS', 'total' => 1]], $body['por_estado']);
        $this->assertSame(['cidade' => 'São Paulo', 'estado' => 'SP', 'total' => 2], $body['top_cidades'][0]);
        $this->assertCount(2, $body['top_cidades']);
    }
}
