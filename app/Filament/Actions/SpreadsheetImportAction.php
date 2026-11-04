<?php

namespace App\Filament\Actions;

use DateTimeInterface;
use Filament\Actions\ImportAction;
use Filament\Forms\Components\FileUpload;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Filament's import action, but it also accepts Excel (.xlsx) files.
 *
 * Every place where Filament reads the upload goes through getUploadedFileStream(), so an .xlsx
 * file is converted to CSV there (first sheet). Column mapping, row validation, failed rows and
 * notifications all stay Filament's own.
 */
class SpreadsheetImportAction extends ImportAction
{
    /** @var list<string> */
    private const EXCEL_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream',
        'application/zip',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $parentSchema = $this->schema;

        $this->schema(function () use ($parentSchema): array {
            $components = $this->evaluate($parentSchema);

            foreach ($components as $component) {
                if ($component instanceof FileUpload && $component->getName() === 'file') {
                    $component
                        ->label('File CSV atau Excel (.xlsx)')
                        ->placeholder('Unggah file CSV atau Excel (.xlsx)')
                        ->helperText('Baris dicocokkan lewat kolom kunci (misalnya NIP): data lama diperbarui, data baru dibuat. Hasil export bisa dipakai sebagai template.')
                        ->acceptedFileTypes([...$component->getAcceptedFileTypes() ?? [], ...self::EXCEL_MIME_TYPES]);
                }
            }

            return $components;
        });
    }

    public function getFileValidationRules(): array
    {
        return array_map(
            fn (mixed $rule): mixed => $rule === 'extensions:csv,txt' ? 'extensions:csv,txt,xlsx' : $rule,
            parent::getFileValidationRules(),
        );
    }

    public function getUploadedFileStream(TemporaryUploadedFile $file)
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            return parent::getUploadedFileStream($file);
        }

        return $this->convertXlsxToCsvStream($file);
    }

    /**
     * @return resource|false
     */
    private function convertXlsxToCsvStream(TemporaryUploadedFile $file)
    {
        $path = $file->getRealPath();
        $temporaryCopy = null;

        if (! $path || ! is_file($path)) {
            $temporaryCopy = tempnam(sys_get_temp_dir(), 'xlsx');
            file_put_contents($temporaryCopy, $file->readStream());
            $path = $temporaryCopy;
        }

        $csv = fopen('php://temp', 'r+');
        $reader = new XlsxReader;

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    fputcsv($csv, array_map($this->cellToString(...), $row->toArray()), escape: '');
                }

                break;
            }
        } finally {
            $reader->close();

            if ($temporaryCopy) {
                @unlink($temporaryCopy);
            }
        }

        rewind($csv);

        return $csv;
    }

    private function cellToString(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format($value->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i:s'),
            is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX => (string) (int) $value,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
