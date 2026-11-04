<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Corrected times were saved on the submission day instead of the corrected date.
     * Move the time onto the corrected date; the time of day itself was right.
     */
    public function up(): void
    {
        DB::table('attendance_corrections')
            ->where(fn ($query) => $query->whereNotNull('corrected_check_in')->orWhereNotNull('corrected_check_out'))
            ->orderBy('id')
            ->each(function (object $correction): void {
                $changes = [];

                foreach (['corrected_check_in', 'corrected_check_out'] as $column) {
                    if ($correction->{$column} === null) {
                        continue;
                    }

                    $anchored = Carbon::parse($correction->date)->setTimeFrom(Carbon::parse($correction->{$column}))->toDateTimeString();

                    if ($anchored !== Carbon::parse($correction->{$column})->toDateTimeString()) {
                        $changes[$column] = $anchored;
                    }
                }

                if ($changes !== []) {
                    DB::table('attendance_corrections')->where('id', $correction->id)->update($changes);
                }
            });
    }

    public function down(): void
    {
        // The submission-day dates were a bug; there is nothing meaningful to restore.
    }
};
