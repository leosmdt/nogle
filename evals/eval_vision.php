<?php

// Mede o acerto da leitura de fotos. Requer a IA instalada (bin/setup_local_ai.sh).
// Uso: php evals/eval_vision.php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Nogle\Ai\LocalAi;

const MARIANA = [
    'nome' => 'Mariana Albuquerque Teixeira', 'cpf' => '52998224725', 'telefone' => '11987654321', 'cep' => '01310200',
    'cidade' => 'São Paulo', 'estado' => 'SP', 'endereco' => 'Avenida Paulista', 'bairro' => 'Bela Vista',
    'numero' => '1578', 'complemento' => 'Apto 42',
];
// Campos ausentes da imagem devem vir vazios; os demais são comparados sem diferença de maiúsculas.
const CASES = [
    'ficha.png' => MARIANA,
    'foto.png' => MARIANA,
    'nota.png' => [
        'nome' => 'Rafael Duarte Lins', 'cpf' => '11144477735', 'telefone' => '51998123344', 'cep' => '90020061',
        'cidade' => 'Porto Alegre', 'estado' => 'RS', 'endereco' => 'Rua Marechal Floriano', 'bairro' => 'Centro',
        'numero' => '455', 'complemento' => '',
    ],
    'manuscrito.png' => [
        'nome' => 'Juliana Prates Moreira', 'cpf' => '93541134780', 'telefone' => '21976543210', 'cep' => '22240003',
        'cidade' => 'Rio de Janeiro', 'estado' => 'RJ', 'endereco' => 'Rua das Laranjeiras', 'bairro' => 'Laranjeiras',
        'numero' => '312', 'complemento' => 'casa 2',
    ],
];

function fold(string $value): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
}

$ai = LocalAi::fromEnvironment();
$total = $correct = $imagesOk = 0;
try {
    foreach (CASES as $name => $expected) {
        $started = microtime(true);
        $fields = $ai->readClientImage((string) file_get_contents(__DIR__ . "/fixtures/$name"));
        $wrong = [];
        foreach (LocalAi::FIELDS as $field) {
            if (fold($fields[$field] ?? '') !== fold($expected[$field])) {
                $wrong[] = "$field: esperado '{$expected[$field]}', veio '" . ($fields[$field] ?? '') . "'";
            }
        }
        $total += count(LocalAi::FIELDS);
        $correct += count(LocalAi::FIELDS) - count($wrong);
        $imagesOk += (int) !$wrong;
        printf("%s  %s (%.1fs)\n", $wrong ? 'FALHOU' : 'OK    ', $name, microtime(true) - $started);
        foreach ($wrong as $line) {
            echo "        $line\n";
        }
    }
} finally {
    $ai->vision()->stop();
}
printf("\nCampos corretos: %d/%d · Imagens sem erro: %d/%d\n", $correct, $total, $imagesOk, count(CASES));
exit($correct === $total ? 0 : 1);
