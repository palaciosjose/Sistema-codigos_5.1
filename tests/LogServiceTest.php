<?php
namespace Tests;

use PHPUnit\Framework\TestCase;
use WhatsappBot\Services\LogService;

class LogServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/whatsapp_logs_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        putenv('WHATSAPP_LOG_PATH');
        putenv('WHATSAPP_NEW_LOG_LEVEL');
    }

    public function testUsesEnvLogPathAndLevel(): void
    {
        $logPath = $this->tempDir . '/custom.log';
        putenv('WHATSAPP_LOG_PATH=' . $logPath);
        putenv('WHATSAPP_NEW_LOG_LEVEL=error');

        $service = new LogService(1);
        $service->error('fail');

        $expectedFile = $this->tempDir . '/custom-' . date('Y-m-d') . '.log';
        $this->assertFileExists($expectedFile);
        $this->assertSame('0644', substr(sprintf('%o', fileperms($expectedFile)), -4));

        $ref = new \ReflectionProperty(LogService::class, 'logger');
        $ref->setAccessible(true);
        $logger = $ref->getValue($service);
        $handler = $logger->getHandlers()[0];
        $this->assertSame('ERROR', $handler->getLevel()->getName());
    }
}
