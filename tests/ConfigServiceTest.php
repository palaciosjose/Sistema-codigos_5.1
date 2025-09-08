<?php
namespace Tests;

use PHPUnit\Framework\TestCase;
use Shared\ConfigService;
use Shared\DatabaseManager;

class CSFakeResult
{
    private array $rows;
    private int $index = 0;
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }
    public function fetch_assoc(): ?array
    {
        return $this->rows[$this->index++] ?? null;
    }
}

class CSFakeInsertStmt
{
    private CSFakeMysqli $db;
    private $key;
    private $value;
    public function __construct(CSFakeMysqli $db)
    {
        $this->db = $db;
    }
    public function bind_param(string $types, &...$vars): void
    {
        $this->key = &$vars[0];
        $this->value = &$vars[1];
    }
    public function execute(): bool
    {
        $this->db->data[$this->key] = $this->value;
        return true;
    }
    public function close(): void {}
}

class CSFakeSelectStmt
{
    private CSFakeMysqli $db;
    public function __construct(CSFakeMysqli $db)
    {
        $this->db = $db;
    }
    public function execute(): bool { return true; }
    public function get_result(): CSFakeResult
    {
        $rows = [];
        foreach ($this->db->data as $k => $v) {
            $rows[] = ['name' => $k, 'value' => $v];
        }
        return new CSFakeResult($rows);
    }
    public function bind_param(string $types, &...$vars): void {}
    public function close(): void {}
}

class CSFakeMysqli extends \mysqli
{
    public array $data = [];
    public function __construct() {}
    #[\ReturnTypeWillChange]
    public function prepare(string $query)
    {
        if (strpos($query, 'INSERT INTO settings') === 0) {
            return new CSFakeInsertStmt($this);
        }
        if (strpos($query, 'SELECT name, value FROM settings') === 0) {
            return new CSFakeSelectStmt($this);
        }
        return false;
    }
}

class CSFakeDatabaseManager extends DatabaseManager
{
    private CSFakeMysqli $conn;
    public function __construct(CSFakeMysqli $conn)
    {
        $this->conn = $conn;
    }
    public function getConnection(): \mysqli
    {
        return $this->conn;
    }
}

class ConfigServiceTest extends TestCase
{
    private CSFakeMysqli $fakeDb;

    protected function setUp(): void
    {
        $_ENV['CRYPTO_KEY'] = 'testkey';

        $this->fakeDb = new CSFakeMysqli();
        $manager = new CSFakeDatabaseManager($this->fakeDb);
        $ref = new \ReflectionProperty(DatabaseManager::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $manager);

        $refCfg = new \ReflectionProperty(ConfigService::class, 'instance');
        $refCfg->setAccessible(true);
        $refCfg->setValue(null, null);
    }

    public function testSetPersistsAndGetRetrieves(): void
    {
        $service = ConfigService::getInstance();
        $service->set('TEST_KEY', 'value');

        $this->assertSame('value', $this->fakeDb->data['TEST_KEY']);
        $this->assertFileExists(CACHE_DIR . '/data/settings.json');
        $cache = json_decode(file_get_contents(CACHE_DIR . '/data/settings.json'), true);
        $this->assertSame('value', $cache['TEST_KEY']);

        $service->reload();
        $this->assertSame('value', $service->get('TEST_KEY'));
    }

    public function testEncryptsWhatsAppSecrets(): void
    {
        $service = ConfigService::getInstance();
        $plain = 'super-secret';

        $service->set('WHATSAPP_NEW_SEND_SECRET', $plain);

        $stored = $this->fakeDb->data['WHATSAPP_NEW_SEND_SECRET'] ?? '';
        $this->assertNotSame($plain, $stored);
        $this->assertSame($plain, \Shared\Crypto::decrypt($stored));
        $this->assertSame($plain, $service->get('WHATSAPP_NEW_SEND_SECRET'));
    }

    public function testEnvEmptyStringFallsBackToDb(): void
    {
        $_ENV['TEST_KEY'] = '';

        $service = ConfigService::getInstance();
        $service->set('TEST_KEY', 'db_value');
        $service->reload();

        $this->assertSame('db_value', $service->get('TEST_KEY'));

        unset($_ENV['TEST_KEY']);
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
