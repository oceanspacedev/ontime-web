<?php

namespace App\Support;

use App\Models\Division;
use App\Models\User;
use App\Services\DataAccessService;
use Illuminate\Support\Str;

/**
 * Who gets the link to the CESA food waste report: coordinators, and crew whose division sits
 * under one of the F&B brands in services.cesa.waste_brands (JChicken, Momoyo, Luuca).
 */
final class WasteReport
{
    public static function urlFor(?User $user): ?string
    {
        $url = config('services.cesa.waste_url');

        if (! $user || blank($url)) {
            return null;
        }

        return DataAccessService::isCoordinator($user) || self::worksAtWasteBrand($user) ? $url : null;
    }

    private static function worksAtWasteBrand(User $user): bool
    {
        $brands = array_map(fn (string $brand): string => Str::upper($brand), (array) config('services.cesa.waste_brands', []));
        $division = ($user->employee ?? $user->intern ?? $user->freelancer)?->division;

        if ($brands === [] || ! $division) {
            return false;
        }

        $visited = [];

        while ($division && ! isset($visited[$division->id])) {
            if (in_array(Str::upper($division->name), $brands, true)) {
                return true;
            }

            $visited[$division->id] = true;
            $division = $division->parent_id ? Division::query()->find($division->parent_id) : null;
        }

        return false;
    }
}
