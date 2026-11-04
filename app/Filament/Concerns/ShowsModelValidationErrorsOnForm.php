<?php

namespace App\Filament\Concerns;

use Closure;
use Illuminate\Validation\ValidationException;

trait ShowsModelValidationErrorsOnForm
{
    /**
     * Model events throw ValidationException with plain attribute keys, while form fields
     * read their errors from `data.*`. Re-key the errors so they appear under the field.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function withFormErrorKeys(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $key): array => [str_starts_with($key, 'data.') ? $key : "data.{$key}" => $messages])
                    ->all(),
            );
        }
    }
}
