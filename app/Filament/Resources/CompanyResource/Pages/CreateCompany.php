<?php

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Resources\CompanyResource;
use App\Models\Company;
use App\Services\CompanyProvisioningService;
use Filament\Resources\Pages\CreateRecord;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    /**
     * Default approval flows, and the new company stays in reach of the PIC who added it.
     */
    protected function afterCreate(): void
    {
        /** @var Company $company */
        $company = $this->record;

        app(CompanyProvisioningService::class)->provision($company, auth()->user());
    }
}
