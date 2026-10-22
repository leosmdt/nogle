<?php

declare(strict_types=1);

namespace Nogle\Clients;

use Nogle\BadRequest;

/** Validação e normalização dos dados de um cliente. */
final class Validation
{
    public static function validCpf(string $cpf): bool
    {
        if (!preg_match('/^\d{11}$/', $cpf) || $cpf === str_repeat($cpf[0], 11)) {
            return false;
        }
        foreach ([9, 10] as $length) {
            $total = 0;
            for ($i = 0; $i < $length; $i++) {
                $total += (int) $cpf[$i] * ($length + 1 - $i);
            }
            $check = $total * 10 % 11;
            if ($check === 10) {
                $check = 0;
            }
            if ($check !== (int) $cpf[$length]) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<string, string>
     */
    public static function normalize(array $payload): array
    {
        $data = [];
        foreach (ClientRepository::FIELDS as $field) {
            $value = $payload[$field] ?? '';
            if (!is_string($value)) {
                throw new BadRequest("O campo $field deve ser texto.");
            }
            $data[$field] = trim($value);
            if ($field !== 'complemento' && $data[$field] === '') {
                throw new BadRequest("Preencha o campo $field.");
            }
            if (mb_strlen($data[$field]) > 180) {
                throw new BadRequest("O campo $field é muito longo.");
            }
        }
        foreach (['cpf', 'telefone', 'cep'] as $field) {
            $data[$field] = preg_replace('/\D/', '', $data[$field]);
        }
        $data['estado'] = mb_strtoupper($data['estado']);
        if (!self::validCpf($data['cpf'])) {
            throw new BadRequest('Informe um CPF válido.');
        }
        if (!in_array(strlen($data['telefone']), [10, 11], true)) {
            throw new BadRequest('Informe um telefone com DDD válido.');
        }
        if (strlen($data['cep']) !== 8) {
            throw new BadRequest('Informe um CEP com 8 dígitos.');
        }
        if (!preg_match('/^[A-Z]{2}$/', $data['estado'])) {
            throw new BadRequest('Informe a sigla do estado com 2 letras.');
        }
        return $data;
    }
}
