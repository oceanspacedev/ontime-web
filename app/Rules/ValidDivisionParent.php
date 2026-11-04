<?php

namespace App\Rules;

use App\Models\Division;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidDivisionParent implements ValidationRule
{
    public function __construct(
        protected ?int $divisionId = null
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value || ! $this->divisionId) {
            return;
        }

        $divisionId = (int) $this->divisionId;
        $parentId = (int) $value;

        if ($parentId === $divisionId) {
            $fail('Divisi tidak boleh menjadi parent bagi dirinya sendiri.');

            return;
        }

        $division = Division::find($divisionId);
        if ($division) {
            if (in_array($parentId, $division->getAllChildrenIds(), true)) {
                $fail('Terjadi circular reference! Parent divisi tidak boleh diambil dari sub-divisinya sendiri.');
            }
        }
    }
}
