<?php

declare(strict_types=1);

namespace Nogle\Tests\Api;

use Nogle\Clients\ClientRepository;

final class StaticAndAssistantTest extends ApiTestCase
{
    public function testStaticAssets(): void
    {
        foreach (['/', '/app.js', '/dashboard.js', '/assistant.js', '/styles.css'] as $path) {
            $this->assertSame(200, $this->call('GET', $path)[0], $path);
        }
        $this->assertSame(404, $this->call('GET', '/index.php')[0]);
        $this->assertSame(404, $this->call('GET', '/../src/App.php')[0]);
    }

    public function testOnboardingIsAvailableFromTheInterface(): void
    {
        $html = $this->call('GET', '/')[1];
        $assistant = $this->call('GET', '/assistant.js')[1];
        $styles = $this->call('GET', '/styles.css')[1];
        $this->assertStringContainsString('id="restart-onboarding"', $html);
        $this->assertStringContainsString('ONBOARDING_KEY', $assistant);
        $this->assertStringContainsString('onboarding-popover', $styles);
        $this->assertStringContainsString("getComputedStyle(onboardingTarget).position === 'static'", $assistant);
        $this->assertStringNotContainsString('.onboarding-highlight { position: relative', $styles);
    }

    public function testUnknownApiRoute(): void
    {
        $this->assertSame(404, $this->call('POST', '/api/outra', [])[0]);
        $this->assertSame(404, $this->call('PATCH', '/api/clientes/1/apagar')[0]);
        $this->assertSame(404, $this->call('OPTIONS', '/api/clientes')[0]);
    }

    public function testUnexpectedErrorReturnsJson500(): void
    {
        $this->app = self::makeApp(new class (self::$db) extends ClientRepository {
            public function list(bool $active, string $nome = '', string $cpf = '', string $cidade = ''): array
            {
                throw new \RuntimeException('falha simulada');
            }
        });
        [$status, $body] = $this->call('GET', '/api/clientes');
        $this->assertSame(500, $status);
        $this->assertStringContainsString('Erro interno', $body['erro']);
    }

    public function testRejectsForeignHostHeader(): void
    {
        [$status, $body] = $this->call('GET', '/api/clientes', null, 'application/json', ['Host' => 'evil.example:8000']);
        $this->assertSame([403, 'Host não permitido.'], [$status, $body['erro']]);
        $this->assertSame(200, $this->call('GET', '/api/clientes', null, 'application/json', ['Host' => 'localhost:8000'])[0]);
    }

    public function testAssistantReportsModelOffline(): void
    {
        [$status, $body] = $this->call('POST', '/api/assistant', ['message' => 'procure a Maria']);
        $this->assertSame(503, $status);
        $this->assertStringContainsString('IA local', $body['erro']);
        $status = $this->call('GET', '/api/assistant/status')[1];
        $this->assertSame([false, false, false], [$status['available'], $status['speech'], $status['vision']]);
    }

    public function testAssistantValidatesInput(): void
    {
        $this->assertSame(400, $this->call('POST', '/api/assistant', ['message' => ''])[0]);
        $this->assertSame(400, $this->call('POST', '/api/assistant/image', 'abc', 'image/gif')[0]);
        $this->assertSame(400, $this->call('POST', '/api/assistant/image', 'not an image', 'image/png')[0]);
        $this->assertSame(400, $this->call('POST', '/api/assistant/audio', 'abc', 'audio/webm')[0]);
        $this->assertSame(400, $this->call('POST', '/api/assistant/audio', 'abc', 'audio/wav')[0]);
    }

    public function testRequestBodyLimits(): void
    {
        $big = str_repeat('x', 16_385);
        [$status, $body] = $this->call('POST', '/api/clientes', $big, 'application/json');
        $this->assertSame([400, 'O corpo da requisição está vazio ou é muito grande.'], [$status, $body['erro']]);
        [$status, $body] = $this->call('POST', '/api/clientes', '', 'application/json', ['Content-Length' => 'abc']);
        $this->assertSame([400, 'Tamanho da requisição inválido.'], [$status, $body['erro']]);
    }
}
