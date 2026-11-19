<?php

namespace App\Models;

use App\Traits\PreventsDeletionWhileInUse;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyLocation extends Model
{
    use HasFactory;
    use PreventsDeletionWhileInUse;

    protected $fillable = [
        'company_id',
        'division_id',
        'name',
        'address',
        'latitude',
        'longitude',
        'radius_meters',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'radius_meters' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Brand of the outlet; its shifts come first when a schedule names this outlet.
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /**
     * An outlet named in a schedule or an attendance stays: deleting it would leave those rows without an
     * outlet (the foreign keys null them), so the roster and the reports lose where the work was done.
     */
    public function deletionBlockedReason(): ?string
    {
        if (ShiftSchedule::query()->where('company_location_id', $this->getKey())->whereDate('date', '>=', today())->exists()) {
            return "Outlet {$this->name} masih dipakai di jadwal mendatang. Ubah jadwalnya atau nonaktifkan outlet ini.";
        }

        if (ShiftSchedule::query()->where('company_location_id', $this->getKey())->exists()
            || Attendance::query()->where('company_location_id', $this->getKey())->exists()) {
            return "Outlet {$this->name} sudah dipakai di jadwal atau absensi. Nonaktifkan outlet ini agar jadwal dan laporan tidak kehilangan outletnya.";
        }

        return null;
    }
}
