<?php
use PHPUnit\Framework\TestCase;
use WhatsappBot\Utils\PayloadHelper;

class PayloadHelperTest extends TestCase
{
    /**
     * @dataProvider whatsappIdProvider
     */
    public function testNormalizesWhatsappId(string $input, string $expected): void
    {
        $data = ['message' => ['from' => $input, 'body' => ' hola ']];
        $payload = PayloadHelper::adaptWebhookPayload($data);
        $this->assertNotNull($payload);
        $this->assertSame($expected . '@c.us', $payload['chat_id']);
        $this->assertSame((int)$expected, $payload['whatsapp_id']);
        $this->assertSame('hola', $payload['text']);
    }

    public static function whatsappIdProvider(): array
    {
        return [
            ['521234567890@c.us', '521234567890'],
            ['+52 123 456 7890', '521234567890'],
            ['521234567890', '521234567890'],
            ['52-1234-567890', '521234567890'],
        ];
    }
}
