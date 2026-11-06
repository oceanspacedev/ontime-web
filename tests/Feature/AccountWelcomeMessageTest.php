<?php

namespace Tests\Feature;

use App\Services\WhatsAppNotificationService;
use Tests\TestCase;

class AccountWelcomeMessageTest extends TestCase
{
    public function test_the_message_says_where_to_get_the_app_and_how_to_sign_in(): void
    {
        config([
            'services.mobile_app.play_store_url' => 'https://play.google.com/store/apps/details?id=co.id.oceanspace.ontime',
            'services.mobile_app.app_store_url' => 'https://apps.apple.com/id/app/ontime/id123',
        ]);

        $message = WhatsAppNotificationService::accountCredentialsMessage('Bayu', '081234567890', 'bayu@ontime.test', 'Rahasia123');

        $this->assertSame(implode("\n", [
            'Halo Bayu, akun OnTime Anda sudah aktif.',
            '',
            '*1. Pasang aplikasi OnTime*',
            'Android: https://play.google.com/store/apps/details?id=co.id.oceanspace.ontime',
            'iPhone: https://apps.apple.com/id/app/ontime/id123',
            '',
            '*2. Masuk*',
            'Email: bayu@ontime.test',
            'Kata sandi: Rahasia123',
            'Atau tekan tombol *WhatsApp* di halaman masuk dan pakai nomor ini.',
            '',
            'Jangan bagikan kata sandi ini ke siapa pun.',
        ]), $message);
    }

    public function test_a_store_without_a_link_is_left_out(): void
    {
        config(['services.mobile_app.app_store_url' => null]);

        $message = WhatsAppNotificationService::accountCredentialsMessage('Bayu', null, 'bayu@ontime.test', 'Rahasia123');

        $this->assertStringContainsString('Android: ', $message);
        $this->assertStringNotContainsString('iPhone', $message);
        $this->assertStringNotContainsString('tombol *WhatsApp*', $message, 'No number, so no WhatsApp sign-in hint.');
    }

    public function test_without_any_store_link_it_only_explains_how_to_sign_in(): void
    {
        config(['services.mobile_app.play_store_url' => null, 'services.mobile_app.app_store_url' => null]);

        $message = WhatsAppNotificationService::accountCredentialsMessage('Bayu', null, 'bayu@ontime.test', 'Rahasia123');

        $this->assertStringNotContainsString('Pasang aplikasi', $message);
        $this->assertStringContainsString('*Masuk ke aplikasi OnTime*', $message);
    }
}
