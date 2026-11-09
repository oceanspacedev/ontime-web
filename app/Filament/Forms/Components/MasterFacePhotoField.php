<?php

namespace App\Filament\Forms\Components;

use App\Models\User;
use App\Services\Attendance\AttendanceRuleException;
use App\Support\SelfieUploadToken;
use Closure;
use Filament\Forms\Components\ViewField;
use Illuminate\Database\Eloquent\Model;

/**
 * Master face photo of a worker, taken with the camera or picked from a file.
 *
 * The capture component uploads the photo to the selfie endpoint and keeps the returned upload token
 * as the field state, never a storage path. This field accepts the stored path unchanged, turns a
 * token issued to the signed in user into its storage path, and refuses anything else, so nobody can
 * point a master face at another stored file.
 */
class MasterFacePhotoField extends ViewField
{
    protected string $view = 'filament.components.master-face-capture';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules([
            fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                try {
                    static::resolveSubmittedPhoto($value, $record);
                } catch (AttendanceRuleException $exception) {
                    $fail($exception->getMessage());
                }
            },
        ]);

        $this->dehydrateStateUsing(fn (mixed $state, ?Model $record): ?string => static::resolveSubmittedPhoto($state, $record));
    }

    /**
     * The storage path to save for the submitted state.
     *
     * @throws AttendanceRuleException When the state is neither the stored path nor a valid upload token.
     */
    public static function resolveSubmittedPhoto(mixed $state, ?Model $record): ?string
    {
        if (blank($state)) {
            return null;
        }

        $storedPath = $record?->getAttribute('master_face_photo');

        if ($state === $storedPath) {
            return $storedPath;
        }

        $user = auth()->user();

        if (! is_string($state) || ! $user instanceof User) {
            throw new AttendanceRuleException(SelfieUploadToken::INVALID_MESSAGE, 422, 'photo');
        }

        try {
            return SelfieUploadToken::resolve($state, $user);
        } catch (AttendanceRuleException $exception) {
            // The form keeps the token after a save, so saving the same page again later must not fail
            // because the token of the photo that is already stored has expired.
            if (filled($storedPath) && SelfieUploadToken::resolve($state, $user, allowExpired: true) === $storedPath) {
                return $storedPath;
            }

            throw $exception;
        }
    }
}
