<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Which records a user can see, independent of what their role lets them do.
 */
enum DataScope: string implements HasDescription, HasLabel
{
    case All = 'all';
    case Companies = 'companies';
    case Divisions = 'divisions';
    case Own = 'own';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => 'Semua',
            self::Companies => 'Badan Usaha',
            self::Divisions => 'Divisi',
            self::Own => 'Pribadi',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::All => 'Seluruh badan usaha.',
            self::Companies => 'Badan usaha yang dipilih, beserta semua divisinya.',
            self::Divisions => 'Divisi yang dipilih, termasuk semua sub-divisinya.',
            self::Own => 'Hanya data miliknya sendiri.',
        };
    }
}
