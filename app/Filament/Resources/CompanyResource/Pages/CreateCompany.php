<?php

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Resources\CompanyResource;
use App\Models\Company;
use App\Services\ApprovalFlowService;
use Filament\Resources\Pages\CreateRecord;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    /**
     * Without approval flows its workers cannot file any request, so a new company starts with the default stages.
     */
    protected function afterCreate(): void
    {
        /** @var Company $company */
        $company = $this->record;

        app(ApprovalFlowService::class)->createDefaultFlows($company);
    }
}
