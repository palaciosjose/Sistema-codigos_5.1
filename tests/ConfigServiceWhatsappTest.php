<?php
namespace Tests;

require_once __DIR__ . '/ConfigServiceTest.php';

use PHPUnit\Framework\TestCase;
use Shared\ConfigService;
use Shared\DatabaseManager;

class ConfigServiceWhatsappTest extends TestCase
{
    private CSFakeMysqli $fakeDb;

    protected function setUp(): void
    {
        $_ENV['CRYPTO_KEY'] = 'testkey';
        putenv('CRYPTO_KEY=testkey');

        $this->fakeDb = new CSFakeMysqli();
        $manager = new CSFakeDatabaseManager($this->fakeDb);
        $ref = new \ReflectionProperty(DatabaseManager::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $manager);

        $refCfg = new \ReflectionProperty(ConfigService::class, 'instance');
        $refCfg->setAccessible(true);
        $refCfg->setValue(null, null);
    }

    public function testWhatsappConfigSetGetAndCache(): void
    {
        $service = ConfigService::getInstance();

        $values = [
            'WHATSAPP_NEW_SEND_SECRET' => 'send-secret',
            'WHATSAPP_NEW_WEBHOOK_SECRET' => 'webhook-secret',
        ];

        foreach ($values as $key => $plain) {
            $service->set($key, $plain);
            $stored = $this->fakeDb->data[$key] ?? '';
            $this->assertNotSame($plain, $stored);
            $this->assertSame($plain, \Shared\Crypto::decrypt($stored));
            $this->assertSame($plain, $service->get($key));
        }

        $cacheFile = CACHE_DIR . '/data/settings.json';
        $this->assertFileExists($cacheFile);
        $cache = json_decode(file_get_contents($cacheFile), true);

        foreach ($values as $key => $plain) {
            $this->assertSame($this->fakeDb->data[$key], $cache[$key]);
        }
    }

    protected function tearDown(): void
    {
        $refCfg = new \ReflectionProperty(ConfigService::class, 'instance');
        $refCfg->setAccessible(true);
        $refCfg->setValue(null, null);

        $refDM = new \ReflectionProperty(DatabaseManager::class, 'instance');
        $refDM->setAccessible(true);
        $refDM->setValue(null, null);

        $cacheFile = CACHE_DIR . '/data/settings.json';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
            @rmdir(dirname($cacheFile));
        }
    }
}
