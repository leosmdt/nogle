<?php

declare(strict_types=1);

namespace Nogle\Tests\Ai;

use Nogle\Ai\LocalAi;
use Nogle\Ai\ModelUnavailable;
use Nogle\Ai\VisionServer;
use Nogle\BadRequest;
use PHPUnit\Framework\TestCase;

/** Regras que validam e corrigem a saída dos modelos locais, sem precisar dos modelos rodando. */
final class LocalAiTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n00000000000000000000000000000000";
    private const WAV = "RIFF\x24\x00\x00\x00WAVEfmt 00000000000000000000000000000000";

    private static function clean(string $message, string $context = '', array $values = []): array
    {
        $plan = ['intent' => 'search', 'query' => '', 'status' => '', 'cidade' => '', 'ask' => [], 'fields' => [], ...$values];
        return LocalAi::cleanPlan($plan, $message, $context);
    }

    private static function chat(array $content): string
    {
        return (string) json_encode(['choices' => [['message' => ['content' => json_encode($content)]]]]);
    }

    private static function ai(FakeHttp $http, ?VisionServer $vision = null): LocalAi
    {
        $vision ??= new class ($http, 'http://visao', '', [], sys_get_temp_dir()) extends VisionServer {
            public function ensure(): void
            {
            }
        };
        return new LocalAi($http, $vision, 'http://texto', 'http://voz');
    }

    public function testListWithNamedPersonBecomesSearch(): void
    {
        $plan = self::clean('me traga todos os dados da Nathalia', '', ['intent' => 'list', 'query' => 'Nathalia']);
        $this->assertSame(['search', 'Nathalia'], [$plan['intent'], $plan['query']]);
    }

    public function testSearchForGenericWordBecomesList(): void
    {
        $plan = self::clean('me traga os contatos dos clientes', '', ['query' => 'clientes']);
        $this->assertSame(['list', '', ['telefone']], [$plan['intent'], $plan['query'], $plan['ask']]);
    }

    public function testInventedNameIsDiscarded(): void
    {
        $plan = self::clean('edite ela', 'e o cep dela?', ['intent' => 'edit', 'query' => 'Beatriz']);
        $this->assertSame(['', true], [$plan['query'], $plan['ref']]);
    }

    public function testNameFromPreviousRequestBecomesReference(): void
    {
        $plan = self::clean('e o endereço dela?', 'procure a Nathalia', ['query' => 'Nathalia']);
        $this->assertSame(['search', '', true, ['endereco']], [$plan['intent'], $plan['query'], $plan['ref'], $plan['ask']]);
    }

    public function testNewListAfterSearchIsNotAReference(): void
    {
        $plan = self::clean('mostre todos os clientes', 'procure a Nathalia', ['query' => 'todos os clientes']);
        $this->assertSame(['list', false], [$plan['intent'], $plan['ref']]);
    }

    public function testKeywordsOfCurrentRequestWinOverModel(): void
    {
        $plan = self::clean('e o CPF?', 'qual o telefone do Leonardo?', ['ask' => ['telefone', 'cpf']]);
        $this->assertSame(['cpf'], $plan['ask']);
    }

    public function testCityMustAppearAfterPreposition(): void
    {
        $plan = self::clean('onde mora o Leonardo Schmidt?', '', ['query' => 'Leonardo Schmidt', 'cidade' => 'Leonardo Schmidt']);
        $this->assertSame(['search', 'Leonardo Schmidt', ''], [$plan['intent'], $plan['query'], $plan['cidade']]);
        $plan = self::clean('quem mora em Santa Cruz do Sul?', '', ['intent' => 'list', 'query' => 'Santa Cruz do Sul', 'cidade' => 'Santa Cruz do Sul']);
        $this->assertSame(['list', '', 'Santa Cruz do Sul'], [$plan['intent'], $plan['query'], $plan['cidade']]);
    }

    public function testCityWithAccentsMatchesFoldedMessage(): void
    {
        $plan = self::clean('quem mora em São Paulo?', '', ['intent' => 'list', 'cidade' => 'São Paulo']);
        $this->assertSame('São Paulo', $plan['cidade']);
    }

    public function testCpfInTextIsUsedAsQuery(): void
    {
        $plan = self::clean('pesquisa pelo cpf 529.982.247-25', '', ['query' => 'cpf', 'ask' => ['cpf']]);
        $this->assertSame(['52998224725', []], [$plan['query'], $plan['ask']]);
    }

    public function testFieldsOnlyForNewOrEdit(): void
    {
        $plan = self::clean('inative Maria', '', ['intent' => 'inactivate', 'query' => 'Maria', 'fields' => ['nome' => 'Maria']]);
        $this->assertSame([], $plan['fields']);
    }

    public function testTargetQueryDropsLeadingArticle(): void
    {
        $plan = self::clean('inative o Leonardo Schmidt', '', ['intent' => 'inactivate', 'query' => 'o Leonardo Schmidt']);
        $this->assertSame(['Leonardo Schmidt', false], [$plan['query'], $plan['all']]);
    }

    public function testCollectiveStatusChangeIsExplicit(): void
    {
        $plan = self::clean('inative todos os clientes', '', ['intent' => 'inactivate', 'query' => 'todos os clientes']);
        $this->assertSame(['', false, true], [$plan['query'], $plan['ref'], $plan['all']]);
    }

    public function testFieldAliasesDigitsAndLeakedJson(): void
    {
        $fields = LocalAi::cleanFields([
            'numero_da_casa' => '120', 'celular' => '(51) 99999-8888', 'email' => 'a@b.c',
            'complemento' => '","cep":"","email":""}', 'nome' => ' Ana ',
        ]);
        $this->assertSame(['numero' => '120', 'telefone' => '51999998888', 'nome' => 'Ana'], $fields);
    }

    public function testParseCommandValidatesLength(): void
    {
        $ai = self::ai(new FakeHttp());
        foreach (['', '  ', str_repeat('x', 501), null] as $message) {
            try {
                $ai->parseCommand($message);
                $this->fail('Pedido inválido aceito: ' . var_export($message, true));
            } catch (BadRequest) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testParseCommandSendsContextAndCleansPlan(): void
    {
        $http = new FakeHttp([self::chat(['intent' => 'inactivate', 'query' => 'ele', 'status' => '', 'cidade' => '', 'ask' => [], 'fields' => []])]);
        $plan = self::ai($http)->parseCommand(' inative ele ', 'qual o telefone do Leonardo?');
        $this->assertSame(['inactivate', '', true], [$plan['intent'], $plan['query'], $plan['ref']]);
        $this->assertStringContainsString('Pedido anterior: qual o telefone do Leonardo?\nPedido atual: inative ele', $http->requests[0]['body']);
    }

    public function testInvalidModelAnswerIsUnavailable(): void
    {
        foreach (['não é json', self::chat(['intent' => 'apagar tudo'])] as $answer) {
            try {
                self::ai(new FakeHttp([$answer]))->parseCommand('procure a Maria');
                $this->fail('Resposta inválida aceita.');
            } catch (ModelUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function readImage(array $extracted): array
    {
        $empty = array_fill_keys(LocalAi::FIELDS, '');
        return self::ai(new FakeHttp([self::chat([...$empty, ...$extracted])]))->readClientImage(self::PNG);
    }

    public function testSplitsNumberAndComplementFromStreet(): void
    {
        $fields = $this->readImage(['endereco' => 'Avenida Paulista, nº 1578 - Apto 42', 'numero' => '42']);
        $this->assertSame(['Avenida Paulista', '1578', 'Apto 42'], [$fields['endereco'], $fields['numero'], $fields['complemento']]);
    }

    public function testKeepsNumbersThatArePartOfStreetName(): void
    {
        $fields = $this->readImage(['endereco' => 'Rua 25 de Março', 'numero' => '10']);
        $this->assertSame(['Rua 25 de Março', '10'], [$fields['endereco'], $fields['numero']]);
    }

    public function testNormalizesStateAndDropsInvalidCep(): void
    {
        $this->assertSame(['estado' => 'RS'], $this->readImage(['estado' => 'rs', 'cep' => '9002']));
        $this->assertArrayNotHasKey('estado', $this->readImage(['estado' => 'Rio Grande do Sul']));
    }

    public function testRejectsNonImages(): void
    {
        foreach (['GIF89a....', "RIFF\x00\x00\x00\x00WAVE"] as $bytes) {
            try {
                self::ai(new FakeHttp())->readClientImage($bytes);
                $this->fail('Arquivo que não é imagem aceito.');
            } catch (BadRequest) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRemovesNoiseMarkersAndSendsNames(): void
    {
        $http = new FakeHttp([(string) json_encode(['text' => " [BLANK_AUDIO] Procure a (música) Nathalia.\n"])]);
        $this->assertSame('Procure a Nathalia.', self::ai($http)->transcribe(self::WAV, ['Nathalia Yasmin']));
        $this->assertStringContainsString('Clientes: Nathalia Yasmin.', $http->requests[0]['body']);
    }

    public function testRejectsNonWav(): void
    {
        $this->expectException(BadRequest::class);
        self::ai(new FakeHttp())->transcribe('OggS' . str_repeat('0', 60));
    }
}
