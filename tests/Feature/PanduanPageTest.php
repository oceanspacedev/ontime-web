<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Filament\Pages\Panduan;
use App\Filament\Resources\Users\UserResource;
use App\Http\Controllers\PanduanVideoController;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Panduan page: open to every panel user, with a video and synced steps per guide, and buttons
 * only to pages the user may open. Its videos are served only to signed-in panel users.
 */
class PanduanPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function signInAs(SystemRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate($role->value, 'web'));
        $this->actingAs($user);

        return $user;
    }

    public function test_any_panel_user_can_open_the_guide_from_the_navigation(): void
    {
        $this->signInAs(SystemRole::Employee);

        $this->get(Panduan::getUrl())
            ->assertOk()
            ->assertSee('Panduan')
            ->assertSee('Pilih topik');
        $this->get(Dashboard::getUrl())->assertOk()->assertSee(Panduan::getUrl(), false);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(Panduan::getUrl())->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
    }

    public function test_every_guide_has_its_video_poster_and_steps(): void
    {
        $this->signInAs(SystemRole::Superadmin);

        $page = Livewire::test(Panduan::class);
        $guides = $page->instance()->guides();

        $this->assertCount(29, $guides);
        $this->assertSame(array_unique(array_column($guides, 'id')), array_column($guides, 'id'));
        $this->assertSame(
            ['Mulai di sini', 'Panel web · PIC', 'Panel web · Koordinator', 'Aplikasi HP'],
            array_values(array_unique(array_column($guides, 'group'))),
        );

        foreach ($guides as $guide) {
            $this->assertFileExists(PanduanVideoController::path("{$guide['id']}.mp4"));
            $this->assertFileExists(PanduanVideoController::path("{$guide['id']}.jpg"));
            $this->assertNotEmpty($guide['steps']);
            if ($guide['id'] !== '00-ringkasan-alur-ontime') {
                // The summary opens with a title card; every other video starts on its first step.
                $this->assertSame(0.0, $guide['steps'][0]['at']);
            }

            // Steps run forward through the video, so the highlighted step follows playback.
            $times = array_column($guide['steps'], 'at');
            $sorted = $times;
            sort($sorted);
            $this->assertSame($sorted, $times, "Steps of {$guide['id']} are out of order.");

            $page->assertSeeHtml('id="panduan-'.$guide['id'].'"')
                ->assertSeeHtml('src="'.$guide['video'].'"')
                ->assertSee($guide['title'])
                ->assertSee($guide['steps'][0]['text']);
        }
    }

    public function test_the_app_guide_uses_the_pic_menu_name(): void
    {
        $this->signInAs(SystemRole::Superadmin);

        Livewire::test(Panduan::class)
            ->assertSee('Buka Profil → PIC')
            ->assertDontSee('Kelola Klien');
    }

    public function test_buttons_only_point_to_pages_the_user_may_open(): void
    {
        $this->signInAs(SystemRole::Employee);
        $guides = collect(Livewire::test(Panduan::class)->instance()->guides())->keyBy('id');
        $this->assertNull($guides['pic-04-akun-koordinator']['action']);
        $this->assertNotNull($guides['pic-01-masuk-dasbor']['action']);

        $this->signInAs(SystemRole::Superadmin);
        $guides = collect(Livewire::test(Panduan::class)->instance()->guides())->keyBy('id');
        $this->assertSame(UserResource::getUrl(), $guides['pic-04-akun-koordinator']['action']['url']);
    }

    public function test_videos_are_served_to_panel_users_with_range_support(): void
    {
        $this->signInAs(SystemRole::Employee);

        $this->get(route('panduan.video', ['file' => 'pic-01-masuk-dasbor.mp4']))
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4');

        $this->get(route('panduan.video', ['file' => 'pic-01-masuk-dasbor.mp4']), ['Range' => 'bytes=0-99'])
            ->assertStatus(206)
            ->assertHeader('Content-Length', '100');

        $this->get(route('panduan.video', ['file' => 'pic-01-masuk-dasbor.jpg']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_videos_are_not_served_to_guests_or_accounts_without_panel_access(): void
    {
        $url = route('panduan.video', ['file' => 'pic-01-masuk-dasbor.mp4']);

        $this->get($url)->assertForbidden();

        $this->actingAs(User::factory()->create());
        $this->get($url)->assertForbidden();

        $inactive = $this->signInAs(SystemRole::Employee);
        $inactive->update(['is_active' => false]);
        $this->get($url)->assertForbidden();
    }

    public function test_only_files_of_the_guide_folder_can_be_requested(): void
    {
        $this->signInAs(SystemRole::Employee);

        $this->get(route('panduan.video', ['file' => 'tidak-ada.mp4']))->assertNotFound();
        $this->get('/admin/panduan/video/..%2F..%2F.env')->assertNotFound();
        $this->get('/admin/panduan/video/panduan.php')->assertNotFound();
    }
}
