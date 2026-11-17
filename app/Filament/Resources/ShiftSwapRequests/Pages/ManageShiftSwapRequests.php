<?php

namespace App\Filament\Resources\ShiftSwapRequests\Pages;

use App\Filament\Resources\ShiftSwapRequests\ShiftSwapRequestResource;
use Filament\Resources\Pages\ManageRecords;

/**
 * Tukar Shift is filed and answered in the app; the panel lists the requests and lets approvers decide.
 */
class ManageShiftSwapRequests extends ManageRecords
{
    protected static string $resource = ShiftSwapRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
