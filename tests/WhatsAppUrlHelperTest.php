<?php
use PHPUnit\Framework\TestCase;
use Shared\WhatsAppUrlHelper;

class WhatsAppUrlHelperTest extends TestCase
{
    public function testRemovesMessagesSend()
    {
        $warning = null;
        $result = WhatsAppUrlHelper::sanitizeBaseUrl('https://example.com/messages/send', $warning);
        $this->assertSame('https://example.com', $result);
        $this->assertNotNull($warning);
    }

    public function testWarnsOnExtraPath()
    {
        $warning = null;
        $result = WhatsAppUrlHelper::sanitizeBaseUrl('https://example.com/foo/bar', $warning);
        $this->assertSame('https://example.com', $result);
        $this->assertNotNull($warning);
    }

    public function testAcceptsValidBase()
    {
        $warning = null;
        $result = WhatsAppUrlHelper::sanitizeBaseUrl('https://example.com', $warning);
        $this->assertSame('https://example.com', $result);
        $this->assertNull($warning);
    }
}
