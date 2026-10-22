<?php

declare(strict_types=1);

namespace Nogle\Clients;

use Nogle\BadRequest;

/** Leitura de CSV de importação e geração do CSV de exportação. */
final class Csv
{
    public const MAX_BYTES = 512_000;
    public const MAX_ROWS = 500;

    /**
     * Valida o arquivo inteiro antes de devolver qualquer registro: uma linha errada invalida a importação.
     *
     * @return list<array<string, string>>
     */
    public static function parse(string $content): array
    {
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new BadRequest('Salve o CSV em UTF-8.');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (trim($content) === '') {
            throw new BadRequest('O CSV está vazio.');
        }
        $firstLine = strtok($content, "\r\n");
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = array_map(static fn (?string $name): string => strtolower(trim((string) $name)), fgetcsv($stream, null, $delimiter, '"', ''));
        $missing = array_diff(array_slice(ClientRepository::FIELDS, 0, -1), $header);
        if ($missing) {
            sort($missing);
            throw new BadRequest('Colunas obrigatórias ausentes: ' . implode(', ', $missing) . '.');
        }
        $records = [];
        $line = 1;
        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($row === [null] || implode('', array_map('trim', $row)) === '') {
                continue;
            }
            if (count($records) >= self::MAX_ROWS) {
                throw new BadRequest('Importe no máximo ' . self::MAX_ROWS . ' clientes por arquivo.');
            }
            if (count($row) !== count($header)) {
                throw new BadRequest("Linha $line: quantidade de colunas inválida.");
            }
            try {
                $records[] = Validation::normalize(array_combine($header, $row));
            } catch (BadRequest $error) {
                throw new BadRequest("Linha $line: {$error->getMessage()}");
            }
        }
        fclose($stream);
        return $records;
    }

    /** @param list<array<string, mixed>> $clients */
    public static function export(array $clients): string
    {
        $stream = fopen('php://memory', 'r+');
        fputcsv($stream, ClientRepository::FIELDS, ';', '"', '', "\r\n");
        foreach ($clients as $client) {
            // Prefixo neutraliza fórmulas ao abrir o CSV em planilhas.
            $row = array_map(
                static fn (string $field): string => preg_match('/^\s*[=+\-@]/', $client[$field]) ? "'" . $client[$field] : $client[$field],
                ClientRepository::FIELDS,
            );
            fputcsv($stream, $row, ';', '"', '', "\r\n");
        }
        rewind($stream);
        // BOM para as planilhas reconhecerem o UTF-8.
        return "\u{FEFF}" . stream_get_contents($stream);
    }
}
