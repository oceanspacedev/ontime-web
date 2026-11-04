<?php

namespace App\Models;

use App\Traits\PreventsDeletionWhileInUse;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobTitle extends Model
{
    use HasFactory, PreventsDeletionWhileInUse;

    protected $fillable = [
        'division_id',
        'name',
    ];

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function deletionBlockedReason(): ?string
    {
        if ($this->employees()->exists()) {
            return "Posisi {$this->name} masih dipakai oleh karyawan. Pindahkan karyawan ke posisi lain sebelum menghapus.";
        }

        return null;
    }
}
