<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Copies the photos and attachments records point at onto the default disk, so files saved while
 * FILESYSTEM_DISK was "local" or "public" still open after switching to s3. Source files are kept.
 */
class CopyStoredFilesToDefaultDisk extends Command
{
    /**
     * Disks that held uploads before they followed the default disk.
     */
    public const SOURCE_DISKS = ['local', 'public'];

    protected $signature = 'storage:copy-to-default-disk {--dry-run : Only report what would be copied}';

    protected $description = 'Copy stored photos and attachments from the local/public disks to the default disk';

    public function handle(): int
    {
        $target = (string) config('filesystems.default');
        $sources = array_values(array_diff(self::SOURCE_DISKS, [$target]));
        $dryRun = (bool) $this->option('dry-run');

        $copied = 0;
        $alreadyThere = 0;
        $missing = 0;

        foreach ($this->referencedPaths() as $path) {
            if (Storage::disk($target)->exists($path)) {
                $alreadyThere++;

                continue;
            }

            $source = collect($sources)->first(fn (string $disk): bool => Storage::disk($disk)->exists($path));

            if ($source === null) {
                $missing++;

                continue;
            }

            if (! $dryRun) {
                $stream = Storage::disk($source)->readStream($path);
                Storage::disk($target)->writeStream($path, $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $copied++;
            $this->line(($dryRun ? 'Akan disalin' : 'Disalin')." dari {$source}: {$path}");
        }

        $this->info(($dryRun ? 'Akan disalin' : 'Disalin')." ke {$target}: {$copied} file. Sudah ada: {$alreadyThere}. Tidak ditemukan: {$missing}.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, string>
     */
    private function referencedPaths(): Collection
    {
        return collect()
            ->merge(Attendance::query()->whereNotNull('check_in_photo')->pluck('check_in_photo'))
            ->merge(Attendance::query()->whereNotNull('check_out_photo')->pluck('check_out_photo'))
            ->merge(Attendance::query()->whereNotNull('liveness_frames')->pluck('liveness_frames')->flatten())
            ->merge(LeaveRequest::query()->whereNotNull('attachment')->pluck('attachment'))
            ->merge(AttendanceCorrection::query()->whereNotNull('attachment')->pluck('attachment'))
            ->merge(Employee::query()->whereNotNull('master_face_photo')->pluck('master_face_photo'))
            ->merge(Intern::query()->whereNotNull('master_face_photo')->pluck('master_face_photo'))
            ->merge(Freelancer::query()->whereNotNull('master_face_photo')->pluck('master_face_photo'))
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '' && ! str_starts_with($path, 'http://') && ! str_starts_with($path, 'https://'))
            ->unique()
            ->values();
    }
}
