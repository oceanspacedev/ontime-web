<?php

namespace App\Providers;

use App\Support\ObservabilityAccess;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Horizon::auth(fn ($request): bool => ObservabilityAccess::allowed($request->user()));
    }

    protected function gate(): void
    {
        // viewHorizon is registered in ObservabilityAccess::register().
    }
}
