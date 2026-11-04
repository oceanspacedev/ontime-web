<?php

namespace App\Models;

use App\Traits\PreventsDeletionWhileInUse;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * JobLevel Model
 *
 * CATATAN PENTING:
 * Field 'level_order' (urutan level) HANYA digunakan untuk keperluan tampilan/sorting UI.
 * Field ini TIDAK BOLEH digunakan untuk logic alur persetujuan (approval flow) atau pengizinan hak akses sistem.
 * Alur approval murni ditentukan dari mapping eksplisit pada tabel 'approvers'.
 */
class JobLevel extends Model
{
    use HasFactory, PreventsDeletionWhileInUse;

    protected $fillable = [
        'name',
        'level_order',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function deletionBlockedReason(): ?string
    {
        if ($this->employees()->exists()) {
            return "Level jabatan {$this->name} masih dipakai oleh karyawan. Pindahkan karyawan ke level lain sebelum menghapus.";
        }

        return null;
    }
}
