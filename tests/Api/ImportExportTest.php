<?php

declare(strict_types=1);

namespace Nogle\Tests\Api;

use Nogle\Clients\ClientRepository;
use Nogle\Clients\Csv;

final class ImportExportTest extends ApiTestCase
{
    /** @param array<string, string> $changes */
    private function csvRow(array $changes = []): string
    {
        $data = [...self::VALID, ...$changes];
        return implode(';', array_map(static fn (string $field): string => $data[$field], ClientRepository::FIELDS));
    }

    private function import(string $content): array
    {
        return $this->call('POST', '/api/clientes/import', $content, 'text/csv');
    }

    public function testImportAddsNewAndSkipsExistingCpf(): void
    {
        $this->create();
        $content = implode("\n", [self::HEADER, $this->csvRow(), $this->csvRow(['nome' => 'Novo', 'cpf' => self::OTHER_CPF])]);
        $this->assertSame([200, ['adicionados' => 1, 'ignorados' => 1]], $this->import($content));
    }

    public function testImportAcceptsCommaBomAndBlankLines(): void
    {
        $content = "\u{FEFF}" . implode("\r\n", [str_replace(';', ',', self::HEADER), '', str_replace(';', ',', $this->csvRow()), '']);
        [$status, $body] = $this->import($content);
        $this->assertSame([200, 1], [$status, $body['adicionados']]);
    }

    public function testInvalidRowImportsNothing(): void
    {
        $content = implode("\n", [self::HEADER, $this->csvRow(), $this->csvRow(['nome' => 'Ruim', 'cpf' => '000'])]);
        [$status, $body] = $this->import($content);
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Linha 3', $body['erro']);
        $this->assertSame(0, $this->total());
    }

    public function testWrongColumnCount(): void
    {
        [$status, $body] = $this->import(self::HEADER . "\nA;B");
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Linha 2: quantidade de colunas inválida', $body['erro']);
    }

    public function testRepeatedCpfInsideFileKeepsFirst(): void
    {
        $content = implode("\n", [self::HEADER, $this->csvRow(), $this->csvRow(['nome' => 'Mesmo CPF'])]);
        $this->assertSame([200, ['adicionados' => 1, 'ignorados' => 1]], $this->import($content));
        $this->assertSame(self::VALID['nome'], $this->call('GET', '/api/clientes')[1]['clientes'][0]['nome']);
    }

    public function testMissingColumn(): void
    {
        [$status, $body] = $this->import("nome;cpf\nA;1");
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Colunas obrigatórias ausentes', $body['erro']);
    }

    public function testRejectsNonUtf8(): void
    {
        [$status, $body] = $this->import(mb_convert_encoding(self::HEADER . "\nJosé", 'ISO-8859-1', 'UTF-8'));
        $this->assertSame([400, 'Salve o CSV em UTF-8.'], [$status, $body['erro']]);
    }

    public function testRowLimit(): void
    {
        $rows = array_fill(0, Csv::MAX_ROWS + 1, $this->csvRow());
        [$status, $body] = $this->import(implode("\n", [self::HEADER, ...$rows]));
        $this->assertSame(400, $status);
        $this->assertStringContainsString('500', $body['erro']);
    }

    public function testExportHasBomHeaderAndNeutralizesFormulas(): void
    {
        $this->create(['complemento' => '=HYPERLINK(1)']);
        [$status, $text] = $this->call('GET', '/api/clientes/export?status=ativos');
        $this->assertSame(200, $status);
        $this->assertStringStartsWith("\u{FEFF}" . self::HEADER . "\r\n", $text);
        $this->assertStringContainsString("'=HYPERLINK(1)", $text);
    }

    public function testExportedFileCanBeImportedBack(): void
    {
        $this->create(['complemento' => 'Apto 4; bloco "B"']);
        [, $text] = $this->call('GET', '/api/clientes/export');
        self::$db->pdo()->exec('TRUNCATE TABLE clientes');
        $this->assertSame(1, $this->import($text)[1]['adicionados']);
        $this->assertSame('Apto 4; bloco "B"', $this->call('GET', '/api/clientes')[1]['clientes'][0]['complemento']);
    }
}
