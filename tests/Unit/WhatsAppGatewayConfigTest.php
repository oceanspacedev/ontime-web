<?php

namespace Tests\Unit;

use App\Services\WhatsAppNotificationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WhatsAppGatewayConfigTest extends TestCase
{
    /**
     * WAG_URL holds the WagHub base URL as in the other systems; older OnTime configs held the endpoint.
     */
    #[DataProvider('gatewayUrls')]
    public function test_messages_endpoint_is_built_from_wag_url(string $wagUrl, string $endpoint): void
    {
        $this->assertSame($endpoint, WhatsAppNotificationService::messagesEndpoint($wagUrl));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gatewayUrls(): array
    {
        return [
            'base url' => ['https://waghub.mekayastudio.com', 'https://waghub.mekayastudio.com/api/v1/messages'],
            'base url with slash' => ['https://waghub.mekayastudio.com/', 'https://waghub.mekayastudio.com/api/v1/messages'],
            'full endpoint' => ['https://waghub.mekayastudio.com/api/v1/messages', 'https://waghub.mekayastudio.com/api/v1/messages'],
            'older custom path' => ['https://wag.contoh.id/api/send', 'https://wag.contoh.id/api/send'],
        ];
    }
}
