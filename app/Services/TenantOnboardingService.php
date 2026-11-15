<?php

namespace App\Services;

use App\Enums\DataScope;
use App\Enums\SystemRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

/**
 * Hands a Klien over to its PIC: the one account the platform Superadmin makes for a client. From
 * there the PIC runs the client alone (Badan Usaha, divisions, locations, attendance mode, shifts,
 * approval flows, accounts).
 */
final class TenantOnboardingService
{
    /**
     * Create a PIC account for the Klien and send the credentials over WhatsApp.
     *
     * The PIC is an Admin with data access "Semua" (every Badan Usaha of the Klien, including the ones
     * added later) plus the PIC role, which lets them add Badan Usaha and Admin accounts.
     *
     * @return array{user: User, email: string, password: string, whatsapp: array<string, mixed>}
     *
     * @throws InvalidArgumentException When the email is already used.
     */
    public function createPic(Tenant $tenant, string $name, string $email, ?string $phone = null): array
    {
        $email = Str::lower(trim($email));
        $password = Str::password(10, symbols: false);

        if (User::query()->where('email', $email)->exists()) {
            throw new InvalidArgumentException("Email {$email} sudah dipakai akun lain.");
        }

        $user = DB::transaction(function () use ($tenant, $name, $email, $phone, $password): User {
            $user = new User([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password' => Hash::make($password),
                'is_active' => true,
            ]);
            $user->tenant_id = $tenant->id;
            $user->save();

            $user->assignRole(Role::findOrCreate(SystemRole::Admin->value, 'web'), Role::findOrCreate(SystemRole::Pic->value, 'web'));
            $user->grantDataAccess(DataScope::All);

            return $user;
        });

        $whatsapp = WhatsAppNotificationService::sendAccountCredentials($name, $phone, $email, $password);

        return ['user' => $user, 'email' => $email, 'password' => $password, 'whatsapp' => $whatsapp];
    }
}
