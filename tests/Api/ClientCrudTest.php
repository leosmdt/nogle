<?php

declare(strict_types=1);

namespace Nogle\Tests\Api;

use Nogle\Clients\ClientRepository;
use Nogle\Database;

final class ClientCrudTest extends ApiTestCase
{
    public function testCreateStoresDigitsAndUppercaseState(): void
    {
        $client = $this->create();
        $this->assertSame(['52998224725', '11987654321', '01310200'], [$client['cpf'], $client['telefone'], $client['cep']]);
        $this->assertSame('SP', $client['estado']);
        $this->assertSame(1, $client['ativo']);
    }

    public function testCreateRejectsInvalidData(): void
    {
        $cases = [
            'cpf' => ['123.456.789-00', 'CPF válido'],
            'telefone' => ['1234', 'telefone'],
            'cep' => ['123', 'CEP'],
            'estado' => ['São Paulo', 'sigla'],
            'nome' => ['  ', 'Preencha o campo nome'],
        ];
        foreach ($cases as $field => [$value, $message]) {
            [$status, $body] = $this->call('POST', '/api/clientes', [...self::VALID, $field => $value]);
            $this->assertSame(400, $status, $field);
            $this->assertStringContainsString($message, $body['erro'], $field);
        }
    }

    public function testCreateRejectsNonTextFieldsAndJsonLists(): void
    {
        $this->assertSame(400, $this->call('POST', '/api/clientes', [...self::VALID, 'nome' => 123])[0]);
        [$status, $body] = $this->call('POST', '/api/clientes', [1]);
        $this->assertSame([400, 'Envie os dados do cliente em um objeto JSON.'], [$status, $body['erro']]);
        $this->assertSame(400, $this->call('POST', '/api/clientes', '{', 'application/json')[0]);
    }

    public function testCreateRejectsRepeatedCpfEvenIfInactive(): void
    {
        $client = $this->create();
        $this->call('PATCH', "/api/clientes/{$client['id']}/inativar");
        [$status, $body] = $this->call('POST', '/api/clientes', [...self::VALID, 'nome' => 'Outra Pessoa']);
        $this->assertSame(409, $status);
        $this->assertStringContainsString('CPF já está cadastrado para um cliente inativo', $body['erro']);
    }

    public function testRepeatedCpfOfActiveClientHasPlainMessage(): void
    {
        $this->create();
        [$status, $body] = $this->call('POST', '/api/clientes', [...self::VALID, 'nome' => 'Outra Pessoa']);
        $this->assertSame([409, 'Este CPF já está cadastrado.'], [$status, $body['erro']]);
    }

    public function testCreateRequiresJson(): void
    {
        $this->assertSame(400, $this->call('POST', '/api/clientes', 'nome=x', 'application/x-www-form-urlencoded')[0]);
    }

    public function testUpdateActiveClient(): void
    {
        $client = $this->create();
        [$status, $body] = $this->call('PUT', "/api/clientes/{$client['id']}", [...self::VALID, 'telefone' => '11912345678']);
        $this->assertSame(200, $status);
        $this->assertSame('11912345678', $body['cliente']['telefone']);
    }

    public function testUpdateKeepingOwnCpf(): void
    {
        $client = $this->create();
        [$status, $body] = $this->call('PUT', "/api/clientes/{$client['id']}", [...self::VALID, 'nome' => 'Novo Nome']);
        $this->assertSame([200, 'Novo Nome'], [$status, $body['cliente']['nome']]);
    }

    public function testUpdateInactiveOrMissingClientFails(): void
    {
        $client = $this->create();
        $this->call('PATCH', "/api/clientes/{$client['id']}/inativar");
        $this->assertSame(404, $this->call('PUT', "/api/clientes/{$client['id']}", self::VALID)[0]);
        $this->assertSame(404, $this->call('PUT', '/api/clientes/999', self::VALID)[0]);
    }

    public function testUpdateToCpfOfAnotherClientConflicts(): void
    {
        $this->create();
        $other = $this->create(['cpf' => self::OTHER_CPF]);
        $this->assertSame(409, $this->call('PUT', "/api/clientes/{$other['id']}", self::VALID)[0]);
    }

    public function testInactivateAndReactivate(): void
    {
        $client = $this->create();
        $this->assertSame(200, $this->call('PATCH', "/api/clientes/{$client['id']}/inativar")[0]);
        $this->assertSame(404, $this->call('PATCH', "/api/clientes/{$client['id']}/inativar")[0]);
        $this->assertSame(0, $this->total('ativos'));
        $this->assertSame(1, $this->total('inativos'));
        $this->assertSame(200, $this->call('PATCH', "/api/clientes/{$client['id']}/reativar")[0]);
        $this->assertSame(1, $this->total('ativos'));
    }

    public function testDeleteOnlyInactiveClient(): void
    {
        $client = $this->create();
        $this->assertSame(404, $this->call('DELETE', "/api/clientes/{$client['id']}")[0]);
        $this->call('PATCH', "/api/clientes/{$client['id']}/inativar");
        $this->assertSame(200, $this->call('DELETE', "/api/clientes/{$client['id']}")[0]);
        $this->assertSame(0, $this->total('inativos'));
        $this->assertSame(404, $this->call('DELETE', "/api/clientes/{$client['id']}")[0]);
    }

    public function testSeedLoadsExamplesOnlyIntoEmptyDatabase(): void
    {
        $this->assertTrue(self::$db->seed());
        $this->assertFalse(self::$db->seed());
        $this->assertSame(10, $this->total('ativos'));
    }

    public function testBulkInactivateAndReactivate(): void
    {
        $this->create();
        $this->create(['nome' => 'Outra Pessoa', 'cpf' => self::OTHER_CPF]);
        [$status, $body] = $this->call('PATCH', '/api/clientes/inativar', ['confirmar' => true]);
        $this->assertSame([200, 2], [$status, $body['quantidade']]);
        $this->assertSame(0, $this->total('ativos'));
        [$status, $body] = $this->call('PATCH', '/api/clientes/reativar', ['confirmar' => true]);
        $this->assertSame([200, 2], [$status, $body['quantidade']]);
        $this->assertSame(2, $this->total('ativos'));
    }

    public function testBulkRequiresExplicitConfirmation(): void
    {
        $this->create();
        foreach ([null, new \stdClass(), ['confirmar' => 'sim'], [true]] as $body) {
            $this->assertSame(400, $this->call('PATCH', '/api/clientes/inativar', $body)[0], (string) json_encode($body));
        }
        $this->assertSame(1, $this->total('ativos'));
    }

    public function testOversizedIdIsNotFound(): void
    {
        $huge = str_repeat('9', 25);
        $this->assertSame(404, $this->call('PUT', "/api/clientes/$huge", self::VALID)[0]);
        $this->assertSame(404, $this->call('PATCH', "/api/clientes/$huge/inativar")[0]);
        $this->assertSame(404, $this->call('DELETE', "/api/clientes/$huge")[0]);
    }

    public function testUnavailableDatabaseReturns503(): void
    {
        $offline = new Database([...self::$db->config, 'port' => '9']);
        $this->app = self::makeApp(new ClientRepository($offline));
        [$status, $body] = $this->call('GET', '/api/clientes');
        $this->assertSame(503, $status);
        $this->assertStringContainsString('Banco de dados indisponível', $body['erro']);
    }
}
