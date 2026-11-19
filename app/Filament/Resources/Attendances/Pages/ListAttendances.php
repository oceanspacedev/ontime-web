<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Filament\Actions\TeamRecapAction;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Services\AttendanceFigures;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;

class ListAttendances extends ListRecords
{
    protected static string $resource = AttendanceResource::class;

    /**
     * The rows of the page with what their schedule columns need read once, not per row.
     */
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();

        AttendanceFigures::preload($records);

        return $records;
    }

    protected function getHeaderActions(): array
    {
        return [
            TeamRecapAction::make(),
        ];
    }
}
