<?php

namespace App\Exports;

use App\Services\AttendanceLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The report as a list: one row per attendance, with photos, GPS, status and notes, and one row for each
 * absent or leave day. Times, hours, lateness, schedule and overtime are the attendance ledger's
 * ({@see AttendanceLedger}, through {@see AttendanceReportData}).
 */
class AttendanceDetailSheet implements FromCollection, WithColumnWidths, WithDrawings, WithHeadings, WithMapping, WithStyles, WithTitle
{
    private const HOURS_FORMAT = '0.00';

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $records = null;

    public function __construct(private readonly AttendanceReportData $data) {}

    public function title(): string
    {
        return 'Detail';
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collection(): Collection
    {
        return $this->records ??= collect($this->data->details());
    }

    public function headings(): array
    {
        return [
            'Badan Usaha',
            'Divisi',
            'NIP / ID',
            'Nama',
            'Tipe',
            'Tanggal',
            'Jam Check In',
            'Jam Check Out',
            'Jumlah Jam',
            'Foto Check-In',
            'Foto Check-Out',
            'GPS Check-In',
            'GPS Check-Out',
            'Status Kehadiran',
            'Menit Telat',
            'Menit Pulang Cepat',
            'Keterangan / Notes',
            'Shift',
            'Outlet',
            'Jadwal',
            'Lembur Otomatis (jam)',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    public function map($row): array
    {
        return [
            $row['company'],
            $row['division'],
            $row['number'],
            $row['name'],
            $row['type'],
            date('d/m/Y', strtotime($row['date'])),
            AttendanceReportData::time($row['check_in'], 8) ?? '-',
            AttendanceReportData::time($row['check_out'], 8) ?? '-',
            $row['worked_minutes'] !== null ? $row['worked_minutes'] / 60 : '-',
            '',
            '',
            $this->mapLink($row['check_in_lat'], $row['check_in_lng']),
            $this->mapLink($row['check_out_lat'], $row['check_out_lng']),
            $row['status_label'],
            $row['late_minutes'],
            $row['early_leave_minutes'],
            $row['notes'] ?? ($row['is_corrected'] ? 'Koreksi Absensi' : '-'),
            $row['shift'] ?? '-',
            $row['outlet'] ?? '-',
            $row['schedule'] ?? '-',
            $row['auto_overtime_minutes'] / 60,
        ];
    }

    /**
     * A link to the map for a GPS position, or "-" without one.
     */
    private function mapLink(mixed $latitude, mixed $longitude): string
    {
        if (! $latitude || ! $longitude) {
            return '-';
        }

        return '=HYPERLINK("https://www.openstreetmap.org/?mlat='.$latitude.'&mlon='.$longitude.'#map=17/'.$latitude.'/'.$longitude.'", "'.$latitude.', '.$longitude.'")';
    }

    public function drawings(): array
    {
        $drawings = [];
        $rowNumber = 2;

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }

        foreach ($this->collection() as $row) {
            if (! empty($row['check_in_photo'])) {
                $fileContent = $this->getPhotoContent($row['check_in_photo']);

                if ($fileContent) {
                    $tempPath = $tempDir.'/att_in_'.md5($row['attendance_id'].'_'.$row['check_in_photo']).'.jpg';
                    file_put_contents($tempPath, $fileContent);
                    if (file_exists($tempPath)) {
                        $drawing = new Drawing;
                        $drawing->setName('Foto Check-In');
                        $drawing->setDescription('Foto Check-In');
                        $drawing->setPath($tempPath);
                        $drawing->setHeight(40);
                        $drawing->setCoordinates('J'.$rowNumber);
                        $drawing->setOffsetX(10);
                        $drawing->setOffsetY(4);
                        $drawings[] = $drawing;
                    }
                }
            }

            if (! empty($row['check_out_photo'])) {
                $fileContent = $this->getPhotoContent($row['check_out_photo']);

                if ($fileContent) {
                    $tempPath = $tempDir.'/att_out_'.md5($row['attendance_id'].'_'.$row['check_out_photo']).'.jpg';
                    file_put_contents($tempPath, $fileContent);
                    if (file_exists($tempPath)) {
                        $drawing = new Drawing;
                        $drawing->setName('Foto Check-Out');
                        $drawing->setDescription('Foto Check-Out');
                        $drawing->setPath($tempPath);
                        $drawing->setHeight(40);
                        $drawing->setCoordinates('K'.$rowNumber);
                        $drawing->setOffsetX(10);
                        $drawing->setOffsetY(4);
                        $drawings[] = $drawing;
                    }
                }
            }

            $rowNumber++;
        }

        return $drawings;
    }

    protected function getPhotoContent(?string $photo): ?string
    {
        if (empty($photo)) {
            return null;
        }

        if (str_starts_with($photo, 'http://') || str_starts_with($photo, 'https://')) {
            try {
                $response = Http::timeout(5)->get($photo);

                return $response->successful() ? $response->body() : null;
            } catch (\Throwable) {
                return null;
            }
        }

        $disk = config('filesystems.default');

        try {
            if (Storage::disk($disk)->exists($photo)) {
                return Storage::disk($disk)->get($photo);
            }
            if (Storage::disk('public')->exists($photo)) {
                return Storage::disk('public')->get($photo);
            }
            // Checked last: an unconfigured S3 disk throws and would hide the other disks.
            if (Storage::disk('s3')->exists($photo)) {
                return Storage::disk('s3')->get($photo);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 24,
            'B' => 20,
            'C' => 18,
            'D' => 26,
            'E' => 14,  // Tipe
            'F' => 14,  // Tanggal
            'G' => 14,  // Check In
            'H' => 14,  // Check Out
            'I' => 12,  // Jumlah Jam
            'J' => 16,  // Foto Check-In
            'K' => 16,  // Foto Check-Out
            'L' => 24,  // GPS Check-In
            'M' => 24,  // GPS Check-Out
            'N' => 22,  // Status
            'O' => 14,  // Menit Telat
            'P' => 16,  // Menit Pulang Cepat
            'Q' => 28,  // Keterangan
            'R' => 22,  // Shift
            'S' => 24,  // Outlet
            'T' => 16,  // Jadwal
            'U' => 14,  // Lembur Otomatis
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $totalRows = $this->collection()->count() + 1;

        for ($i = 2; $i <= $totalRows; $i++) {
            $sheet->getRowDimension($i)->setRowHeight(42);
        }

        $sheet->getRowDimension(1)->setRowHeight(28);

        $sheet->getStyle("E2:N{$totalRows}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E2:N{$totalRows}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A2:D{$totalRows}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("O2:P{$totalRows}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("I2:I{$totalRows}")->getNumberFormat()->setFormatCode(self::HOURS_FORMAT);
        $sheet->getStyle("R2:U{$totalRows}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("U2:U{$totalRows}")->getNumberFormat()->setFormatCode(self::HOURS_FORMAT);

        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size' => 11,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1F2937'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }
}
