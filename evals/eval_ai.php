<?php

// Mede o acerto da IA local em pedidos reais. Requer o modelo rodando (bin/start_local.sh).
// Uso: php evals/eval_ai.php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Nogle\Ai\LocalAi;

// [pedido anterior, pedido, campos esperados]. Campos ausentes não são conferidos.
const CASES = [
    ['', 'procure a Maria', ['intent' => 'search', 'query' => 'Maria']],
    ['', 'buscar cliente João Pedro', ['intent' => 'search', 'query' => 'João Pedro']],
    ['', 'me traga todos os dados da nathalia Yasmin', ['intent' => 'search', 'query' => 'nathalia Yasmin', 'ask' => []]],
    ['', 'qual o telefone do Leonardo?', ['intent' => 'search', 'query' => 'Leonardo', 'ask' => ['telefone']]],
    ['', 'me passa o CPF da Nathalia', ['intent' => 'search', 'query' => 'Nathalia', 'ask' => ['cpf']]],
    ['', 'onde mora o Leonardo Schmidt?', ['intent' => 'search', 'query' => 'Leonardo Schmidt', 'ask' => ['endereco']]],
    ['', 'telefone e endereço da Nathalia', ['intent' => 'search', 'query' => 'Nathalia', 'ask' => ['telefone', 'endereco']]],
    ['', 'dados do cliente com CPF 529.982.247-25', ['intent' => 'search', 'query' => '52998224725']],
    ['', 'tem algum cliente chamado Carlos?', ['intent' => 'search', 'query' => 'Carlos']],
    ['', 'Leonardo', ['intent' => 'search', 'query' => 'Leonardo']],
    ['', 'quais são os nomes dos clientes?', ['intent' => 'list', 'query' => '']],
    ['', 'me traga os contatos dos clientes', ['intent' => 'list', 'query' => '', 'ask' => ['telefone']]],
    ['', 'mostre os inativos', ['intent' => 'list', 'status' => 'inativos']],
    ['', 'listar clientes ativos', ['intent' => 'list', 'status' => 'ativos']],
    ['', 'quem mora em Santa Cruz do Sul?', ['intent' => 'list', 'query' => '', 'cidade' => 'Santa Cruz do Sul']],
    ['', 'clientes de Porto Alegre', ['intent' => 'list', 'query' => '', 'cidade' => 'Porto Alegre']],
    ['', 'quantos clientes temos?', ['intent' => 'count', 'status' => '']],
    ['', 'quantos clientes inativos?', ['intent' => 'count', 'status' => 'inativos']],
    ['', 'quantos clientes moram em Santa Cruz do Sul?', ['intent' => 'count', 'cidade' => 'Santa Cruz do Sul']],
    ['', 'cadastre Ana Souza com CPF 52998224725 e CEP 01310100', ['intent' => 'new', 'fields' => ['nome' => 'Ana Souza', 'cpf' => '52998224725', 'cep' => '01310100']]],
    ['', 'novo cliente Pedro Lima telefone (51) 99999-8888', ['intent' => 'new', 'fields' => ['nome' => 'Pedro Lima', 'telefone' => '51999998888']]],
    ['', 'quero cadastrar um cliente', ['intent' => 'new', 'fields' => []]],
    ['', 'mude o telefone de João para 11988887777', ['intent' => 'edit', 'query' => 'João', 'fields' => ['telefone' => '11988887777']]],
    ['', 'edite o cadastro da Nathalia', ['intent' => 'edit', 'query' => 'Nathalia']],
    ['', 'o número da casa do Leonardo agora é 120', ['intent' => 'edit', 'query' => 'Leonardo', 'fields' => ['numero' => '120']]],
    ['', 'inative Maria', ['intent' => 'inactivate', 'query' => 'Maria']],
    ['', 'desativar o cliente Leonardo Schmidt', ['intent' => 'inactivate', 'query' => 'Leonardo Schmidt']],
    ['', 'reative o Carlos', ['intent' => 'reactivate', 'query' => 'Carlos']],
    ['', 'ativar novamente a Nathalia', ['intent' => 'reactivate', 'query' => 'Nathalia']],
    ['', 'exporta a lista', ['intent' => 'export']],
    ['', 'baixar csv dos clientes inativos', ['intent' => 'export', 'status' => 'inativos']],
    ['', 'importar clientes de um arquivo', ['intent' => 'import']],
    ['', 'limpa a busca', ['intent' => 'clear']],
    ['', 'o que você sabe fazer?', ['intent' => 'help']],
    ['', 'qual a previsão do tempo?', ['intent' => 'unknown']],
    ['', 'me conta uma piada', ['intent' => 'unknown']],
    ['procure a Nathalia', 'e o endereço dela?', ['intent' => 'search', 'query' => '', 'ref' => true, 'ask' => ['endereco']]],
    ['qual o telefone do Leonardo?', 'inative ele', ['intent' => 'inactivate', 'query' => '', 'ref' => true]],
    ['procure a Nathalia', 'edite ela', ['intent' => 'edit', 'query' => '', 'ref' => true]],
    ['procure a Nathalia', 'e o CPF?', ['intent' => 'search', 'query' => '', 'ask' => ['cpf']]],
    ['procure a Nathalia', 'agora procure o Leonardo', ['intent' => 'search', 'query' => 'Leonardo', 'ref' => false]],
    ['', 'qual é o endereço completo da Nathalia Yasmin?', ['intent' => 'search', 'query' => 'Nathalia Yasmin', 'ask' => ['endereco']]],
    ['', 'preciso do cep do Leonardo', ['intent' => 'search', 'query' => 'Leonardo', 'ask' => ['cep']]],
    ['', 'lista de telefones de todo mundo', ['intent' => 'list', 'query' => '', 'ask' => ['telefone']]],
    ['', 'clientes que moram em Porto Alegre e estão inativos', ['intent' => 'list', 'status' => 'inativos', 'cidade' => 'Porto Alegre']],
    ['', 'tem quantos cadastrados ao todo?', ['intent' => 'count']],
    ['', 'apaga o Leonardo', ['intent' => 'inactivate', 'query' => 'Leonardo']],
    ['', 'quero ver os desativados', ['intent' => 'list', 'status' => 'inativos']],
    ['', 'troca o celular da Nathalia pra 51 98765-4321', ['intent' => 'edit', 'query' => 'Nathalia', 'fields' => ['telefone' => '51987654321']]],
    ['', 'adiciona a cliente Fernanda Costa, cpf 111.444.777-35', ['intent' => 'new', 'fields' => ['nome' => 'Fernanda Costa', 'cpf' => '11144477735']]],
    ['', 'pesquisa pelo cpf 52998224725', ['intent' => 'search', 'query' => '52998224725', 'ask' => []]],
    ['', 'volta tudo ao normal, tira os filtros', ['intent' => 'clear']],
    ['procure o Leonardo', 'qual o celular?', ['intent' => 'search', 'query' => '', 'ref' => true, 'ask' => ['telefone']]],
    ['qual o telefone do Leonardo?', 'e o CPF?', ['intent' => 'search', 'ref' => true, 'ask' => ['cpf']]],
    ['e o cep dela?', 'edite ela', ['intent' => 'edit', 'query' => '', 'ref' => true]],
    ['', 'edite ela', ['intent' => 'edit', 'query' => '', 'ref' => true]],
    ['procure o Leonardo', 'desativa', ['intent' => 'inactivate', 'query' => '', 'ref' => true]],
    ['procure a Nathalia', 'mostre todos os clientes', ['intent' => 'list', 'ref' => false]],
    ['mostre os inativos', 'reative a Nathalia', ['intent' => 'reactivate', 'query' => 'Nathalia']],
    ['me passa o telefone da Nathalia', 'muda pra 51988887777', ['intent' => 'edit', 'ref' => true, 'fields' => ['telefone' => '51988887777']]],
];

function fold(mixed $value): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)));
}

/** @return list<string> */
function mismatches(array $plan, array $expected): array
{
    $wrong = [];
    foreach ($expected as $key => $want) {
        $got = $plan[$key] ?? null;
        if ($key === 'query' && preg_match('/^[\d.\-\s]+$/', (string) $want)) {
            $ok = preg_replace('/\D/', '', (string) $got) === $want;
        } elseif ($key === 'ask') {
            $gotSorted = $got ?? [];
            $wantSorted = $want;
            sort($gotSorted);
            sort($wantSorted);
            $ok = $gotSorted === $wantSorted;
        } elseif ($key === 'fields') {
            $normalize = static fn (array $fields): array => array_map(
                static fn (string $field, string $value): string => in_array($field, ['cpf', 'telefone', 'cep'], true) ? preg_replace('/\D/', '', $value) : fold($value),
                array_keys($fields), $fields,
            );
            $gotFields = $got ?? [];
            ksort($gotFields);
            ksort($want);
            $ok = array_keys($gotFields) === array_keys($want) && $normalize($gotFields) === $normalize($want);
        } elseif (is_string($want)) {
            $ok = fold($got ?? '') === fold($want);
        } else {
            $ok = $got === $want;
        }
        if (!$ok) {
            $wrong[] = "$key: esperado " . json_encode($want, JSON_UNESCAPED_UNICODE) . ', veio ' . json_encode($got, JSON_UNESCAPED_UNICODE);
        }
    }
    return $wrong;
}

$ai = LocalAi::fromEnvironment();
$passed = $intentOk = 0;
$started = microtime(true);
foreach (CASES as [$context, $message, $expected]) {
    $plan = $ai->parseCommand($message, $context);
    $wrong = mismatches($plan, $expected);
    $intentOk += (int) ($plan['intent'] === $expected['intent']);
    $passed += (int) !$wrong;
    if ($wrong) {
        echo 'FALHOU  ' . ($context !== '' ? "[$context] → " : '') . "$message\n        " . implode("\n        ", $wrong) . "\n";
    }
}
$total = count(CASES);
printf("\nIntenção correta: %d/%d · Pedido inteiro correto: %d/%d · %.1fs por pedido\n", $intentOk, $total, $passed, $total, (microtime(true) - $started) / $total);
exit($passed === $total ? 0 : 1);
