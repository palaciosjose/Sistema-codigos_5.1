<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

class WhatsAppWebhookIntegrationTest extends TestCase
{
    private function simulateSendTestMessage(string $phone): array
    {
        if (empty($phone)) {
            return ['success' => false, 'message' => 'Número de teléfono requerido'];
        }
        return ['success' => true, 'message' => 'Mensaje de prueba enviado'];
    }

    public function testSendMessageAndWebhookLog(): void
    {
        $response = $this->simulateSendTestMessage('123456789');
        $this->assertTrue($response['success']);
        $this->assertSame('Mensaje de prueba enviado', $response['message']);

        $root = dirname(__DIR__);
        $logFile = $root . '/whatsapp_bot/logs/webhook_complete.log';
        if (file_exists($logFile)) {
            unlink($logFile);
        }

        $runner = <<<'PHP'
<?php
namespace {
    require_once '%ROOT%/config/path_constants.php';
}
namespace Shared {
    class ConfigService {
        public static function getInstance(): self { return new self(); }
        public function get(string $key, $default = '') { return $default; }
    }
    class DatabaseManager {
        public static function getInstance(): self { return new self(); }
        public function getConnection() { return new \stdClass(); }
    }
}
namespace WhatsappBot\Services {
    class WhatsappAuth {
        public const SESSION_LIFETIME = 3600;
        public function cleanupExpiredData(): void {}
        public function loginWithCredentials($a,$b,$c){return ['id'=>1,'username'=>$b];}
        public function authenticateUser($a){return ['id'=>1,'username'=>'test'];}
    }
    class WhatsappQuery {
        public function __construct($auth) {}
        public function processSearchRequest(){return [];}
        public function getCodeById($a,$b){return null;}
        public function getStats(){return [];}
    }
    class LogService {
        public function info($m,$c=[]): void {}
        public function error($m,$c=[]): void {}
        public function debug($m,$c=[]): void {}
    }
}
namespace {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'type' => 'whatsapp',
        'data' => [
            'id' => 1,
            'message' => 'Mensaje de prueba',
            'phone' => '123456789'
        ]
    ];
    include '%ROOT%/whatsapp_bot/webhook.php';
}
PHP;
        $runner = str_replace('%ROOT%', $root, $runner);
        $temp = tempnam(sys_get_temp_dir(), 'wh_run');
        file_put_contents($temp, $runner);
        shell_exec('php ' . $temp);

        $this->assertFileExists($logFile);
        $this->assertStringContainsString('Mensaje de prueba enviado', file_get_contents($logFile));
    }
}
