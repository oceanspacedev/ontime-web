<?php

namespace App\Console\Commands;

use App\Services\TalentaEmployeeImportService;
use Illuminate\Console\Command;

class ImportTalentaEmployeesCommand extends Command
{
    protected $signature = 'employees:import-talenta
        {path : Path ke file JSON hasil export "list employee" Talenta}
        {--dry-run : Hitung perubahan tanpa menyimpan}
        {--deactivate-missing : Nonaktifkan karyawan aktif yang tidak ada di file (hanya badan usaha yang ada di file)}';

    protected $description = 'Import atau sinkronkan data karyawan dari file JSON Talenta';

    public function handle(TalentaEmployeeImportService $importService): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("File tidak ditemukan atau tidak bisa dibaca: {$path}");

            return self::FAILURE;
        }

        $result = $importService->importFromContent(
            (string) file_get_contents($path),
            dryRun: (bool) $this->option('dry-run'),
            deactivateMissing: (bool) $this->option('deactivate-missing'),
        );

        if (! $result['success']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);
        $this->table(['Hasil', 'Jumlah'], [
            ['Baris di file', $result['total']],
            ['Karyawan baru', $result['created']],
            ['Diperbarui', $result['updated']],
            ['Tidak berubah', $result['unchanged']],
            ['Diaktifkan kembali', $result['reactivated']],
            ['Pindah badan usaha / divisi', $result['moved']],
            ['Dinonaktifkan', $result['deactivated']],
            ['Dilewati', $result['skipped']],
            ['Badan usaha baru', count($result['new_companies'])],
            ['Divisi baru', $result['new_divisions']],
            ['Posisi baru', $result['new_job_titles']],
        ]);

        if ($result['new_companies'] !== []) {
            $this->line('Badan usaha baru: '.implode(', ', $result['new_companies']));
        }

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
