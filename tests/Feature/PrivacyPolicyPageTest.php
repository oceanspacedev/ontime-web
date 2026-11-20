<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class PrivacyPolicyPageTest extends TestCase
{
    public function test_route_name_resolves_to_the_public_path(): void
    {
        $this->assertSame('/kebijakan-privasi', route('privacy-policy', absolute: false));
    }

    public function test_guest_can_read_the_privacy_policy(): void
    {
        $this->assertGuest();

        $this->get('/kebijakan-privasi')
            ->assertOk()
            ->assertSee('<title>Kebijakan Privasi — OnTime</title>', false)
            ->assertSeeInOrder([
                'Terakhir diperbarui: 20 November 2026',
                'Data yang kami kumpulkan',
                'Foto dan data wajah',
                'Lokasi',
                'Siapa yang dapat melihat data Anda',
                'Hak Anda',
                'Penghapusan akun',
                'Anak-anak',
            ])
            ->assertSee('Undang-Undang Nomor 27 Tahun 2022')
            ->assertSee('developer@rizqis.com');
    }

    public function test_signed_in_user_can_also_read_it(): void
    {
        $this->actingAs(new User(['name' => 'Tester']))
            ->get(route('privacy-policy'))
            ->assertOk();
    }
}
