<?php

declare(strict_types=1);

namespace Nogle\Tests\Ai;

use Nogle\Ai\ModelUnavailable;
use PHPUnit\Framework\TestCase;

final class VisionServerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nogle-vision-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("$this->dir/*"));
        rmdir($this->dir);
    }

    public function testStartsOnDemandRecordsPidAndStops(): void
    {
        $vision = new FakeVisionServer($this->dir, [false, false, true]);
        $this->assertSame('pronto', $vision->run(static fn (): string => 'pronto'));
        $this->assertSame(1, $vision->launches);
        $this->assertSame('4242', file_get_contents("$this->dir/vision.pid"));
        $this->assertFileExists("$this->dir/vision.last");

        $vision->stop();
        $this->assertSame([15], $vision->signals);
        $this->assertFileDoesNotExist("$this->dir/vision.pid");
    }

    public function testReusesRunningProcess(): void
    {
        $vision = new FakeVisionServer($this->dir, [false, false, true, false, true]);
        $vision->ensure();
        $vision->ensure();
        $this->assertSame(1, $vision->launches);
    }

    public function testReportsMissingInstallation(): void
    {
        $this->expectException(ModelUnavailable::class);
        (new FakeVisionServer($this->dir, [], false))->ensure();
    }

    public function testProcessThatDiesDuringStartupIsReported(): void
    {
        $vision = new class ($this->dir, []) extends FakeVisionServer {
            protected function launch(): int
            {
                return 1; // PID que alive() nunca reconhece: o processo morreu ao iniciar.
            }
        };
        $this->expectExceptionMessage('não conseguiu iniciar');
        $vision->ensure();
    }
}
