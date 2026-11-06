<?php

namespace App\Filament\Resources\FreelanceResource\Pages;

use App\Filament\Resources\FreelanceResource;
use App\Models\Freelancer;
use Filament\Resources\Pages\CreateRecord;

class CreateFreelancer extends CreateRecord
{
    protected static string $resource = FreelanceResource::class;

    protected function afterCreate(): void
    {
        /** @var Freelancer $freelancer */
        $freelancer = $this->record;

        if (($this->data['create_account'] ?? false) && ! $freelancer->user_id) {
            FreelanceResource::openAccount($freelancer);
        }
    }
}
