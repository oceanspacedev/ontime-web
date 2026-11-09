<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OneSignalWebPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.onesignal.web_app_id' => 'web-app-id']);
        Role::findOrCreate('Superadmin', 'web');
    }

    public function test_the_panel_links_the_signed_in_user_and_offers_to_enable_notifications(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Superadmin');

        $this->actingAs($user)->get('/admin')
            ->assertOk()
            ->assertSee('https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js', false)
            ->assertSee("appId: 'web-app-id'", false)
            ->assertSee("OneSignal.login('{$user->id}')", false)
            ->assertSee('Aktifkan notifikasi');
    }

    public function test_the_login_page_unlinks_the_browser_and_asks_nothing(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('OneSignal.logout()', false)
            ->assertDontSee('OneSignal.login(', false)
            ->assertDontSee('Aktifkan notifikasi');
    }

    public function test_nothing_loads_without_an_app_id(): void
    {
        config(['services.onesignal.web_app_id' => null]);

        $this->get('/admin/login')->assertOk()->assertDontSee('OneSignalSDK.page.js', false);
    }

    public function test_the_service_worker_is_served_from_the_site_root(): void
    {
        $this->assertSame(
            "importScripts('https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.sw.js');\n",
            file_get_contents(public_path('OneSignalSDKWorker.js')),
        );
    }
}
