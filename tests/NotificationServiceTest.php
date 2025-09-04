<?php
namespace WhatsappBot\Utils;
class WhatsappAPI
{
    public static array $sent = [];
    public static function sendMessage(string $number, string $text): array
    {
        self::$sent[] = ['number' => $number, 'text' => $text];
        return ['status' => 'ok'];
    }
}

namespace Tests;

use PHPUnit\Framework\TestCase;
use WhatsappBot\Services\NotificationService;

class FakeResult
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

class FakeStmt
{
    private array $rows;
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }
    public function execute(): void {}
    public function get_result(): FakeResult
    {
        return new FakeResult($this->rows);
    }
    public function bind_param(string $types, &...$vars): void {}
    public function close(): void {}
}

class FakeMysqli extends \mysqli
{
    private array $rows;
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }
    #[\ReturnTypeWillChange]
    public function prepare(string $query)
    {
        return new FakeStmt($this->rows);
    }
}

class NotificationServiceTest extends TestCase
{
    public function testNotifyAdminsSendsMessages(): void
    {
        $db = new FakeMysqli([
            ['whatsapp_id' => '1111'],
            ['whatsapp_id' => '2222'],
        ]);
        $service = new NotificationService($db);
        $service->notifyAdmins('Sistema actualizado');

        $this->assertCount(2, \WhatsappBot\Utils\WhatsappAPI::$sent);
        $this->assertSame('1111', \WhatsappBot\Utils\WhatsappAPI::$sent[0]['number']);
        $this->assertStringContainsString('Sistema actualizado', \WhatsappBot\Utils\WhatsappAPI::$sent[0]['text']);
    }
}
