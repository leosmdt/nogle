<?php

declare(strict_types=1);

namespace Nogle\Tests\Api;

final class ClientListTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create(['nome' => 'José Álvares']);
        $this->create(['nome' => 'Maria Souza', 'cpf' => self::OTHER_CPF, 'cidade' => 'Porto Alegre', 'estado' => 'RS']);
    }

    /** @return list<string> */
    private function names(string $query): array
    {
        [$status, $body] = $this->call('GET', "/api/clientes?$query");
        $this->assertSame(200, $status);
        return array_column($body['clientes'], 'nome');
    }

    public function testNameFilterIgnoresAccentsAndCase(): void
    {
        $this->assertSame(['José Álvares'], $this->names('nome=jose%20ALV'));
    }

    public function testCpfFilterAcceptsPartialMaskedValue(): void
    {
        $this->assertSame(['Maria Souza'], $this->names('cpf=111.444'));
    }

    public function testCityFilter(): void
    {
        $this->assertSame(['Maria Souza'], $this->names('cidade=porto'));
    }

    public function testLikeWildcardsAreLiteral(): void
    {
        $this->assertSame([], $this->names('nome=%25'));
        $this->assertSame([], $this->names('nome=_'));
    }

    public function testListIsSortedByName(): void
    {
        $this->assertSame(['José Álvares', 'Maria Souza'], $this->names(''));
    }

    public function testTotalIgnoresFilters(): void
    {
        $this->assertSame(2, $this->call('GET', '/api/clientes?nome=maria')[1]['total']);
    }

    public function testInvalidStatus(): void
    {
        $this->assertSame(400, $this->call('GET', '/api/clientes?status=todos')[0]);
        $this->assertSame(200, $this->call('GET', '/api/clientes?nome[]=x')[0]);
    }
}
