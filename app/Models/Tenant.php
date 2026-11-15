<?php

namespace App\Models;

use App\Traits\PreventsDeletionWhileInUse;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A Klien of the platform: one client that owns one or more Badan Usaha (companies). The platform
 * Superadmin creates it with a PIC account; the PIC then runs the client on their own.
 *
 * Every company and every account except the Superadmin belongs to a Klien, and data access never
 * reaches past it (see DataAccessService).
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, PreventsDeletionWhileInUse;

    protected $fillable = [
        'name',
        'code',
        'contact_name',
        'contact_email',
        'contact_phone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Switching a Klien off signs its accounts out of the app at once, like ending a contract.
        static::updated(function (Tenant $tenant): void {
            if ($tenant->wasChanged('is_active') && ! $tenant->is_active) {
                PersonalAccessToken::query()
                    ->where('tokenable_type', (new User)->getMorphClass())
                    ->whereIn('tokenable_id', $tenant->users()->select('id'))
                    ->delete();
            }
        });
    }

    public function deletionBlockedReason(): ?string
    {
        if ($this->companies()->exists() || $this->users()->exists()) {
            return 'Tidak dapat menghapus klien ini karena masih memiliki badan usaha atau akun. Nonaktifkan klien sebagai gantinya.';
        }

        return null;
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
