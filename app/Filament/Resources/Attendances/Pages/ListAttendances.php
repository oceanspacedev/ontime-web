<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Filament\Actions\TeamRecapAction;
use App\Filament\Resources\Attendances\AttendanceResource;
use Filament\Resources\Pages\ListRecords;

class ListAttendances extends ListRecords
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TeamRecapAction::make(),
        ];
    }
}
