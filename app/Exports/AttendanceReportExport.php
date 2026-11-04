<?php

namespace App\Exports;

use App\Enums\AttendanceStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AttendanceReportExport implements FromCollection, WithColumnWidths, WithDrawings, WithHeadings, WithMapping, WithStyles
{
    public function __construct(protected Collection $records) {}

    public function collection(): Collection
    {
        return $this->records;
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
            'Foto Check-In',
            'Foto Check-Out',
            'GPS Check-In',
            'GPS Check-Out',
            'Status Kehadiran',
            'Menit Telat',
            'Keterangan / Notes',
        ];
    }

    public function map($row): array
    {
        $company = $row->employee?->company?->name
            ?? $row->intern?->company?->name
            ?? $row->freelancer?->company?->name
            ?? '-';

        $division = $row->employee?->division?->name
            ?? $row->intern?->division?->name
            ?? $row->freelancer?->division?->name
            ?? '-';

        $nip = $row->employee?->nip
            ?? $row->intern?->nis
            ?? $row->freelancer?->freelancer_number
            ?? '-';

        $name = $row->employee?->full_name
            ?? $row->intern?->full_name
            ?? $row->freelancer?->full_name
            ?? '-';

        $tipe = $row->employee ? 'Karyawan'
            : ($row->intern ? 'Magang'
            : ($row->freelancer ? 'Freelancer' : '-'));

        return [
            $company,
            $division,
            $nip,
            $name,
            $tipe,
            $row->date ? $row->date->format('d/m/Y') : '-',
            $row->check_in ? $row->check_in->format('H:i:s') : '-',
            $row->check_out ? $row->check_out->format('H:i:s') : '-',
            '',
            '',
            ($row->check_in_lat && $row->check_in_lng)
                ? '=HYPERLINK("https://www.openstreetmap.org/?mlat='.$row->check_in_lat.'&mlon='.$row->check_in_lng.'#map=17/'.$row->check_in_lat.'/'.$row->check_in_lng.'", "'.$row->check_in_lat.', '.$row->check_in_lng.'")'
                : '-',
            ($row->check_out_lat && $row->check_out_lng)
                ? '=HYPERLINK("https://www.openstreetmap.org/?mlat='.$row->check_out_lat.'&mlon='.$row->check_out_lng.'#map=17/'.$row->check_out_lat.'/'.$row->check_out_lng.'", "'.$row->check_out_lat.', '.$row->check_out_lng.'")'
                : '-',
            $row->status instanceof AttendanceStatus
                ? $row->status->getLabel()
                : (match ((string) $row->status) {
                    'on_time', 'present' => 'Tepat Waktu / Hadir',
                    'late' => 'Terlambat',
                    'leave' => 'Cuti / Izin',
                    'absent' => 'Alpa',
                    default => (string) ($row->status ?? '-'),
                }),
            $row->late_minutes ?? 0,
            $row->notes ?? ($row->is_corrected ? 'Koreksi Absensi' : '-'),
        ];
    }

    public function drawings(): array
    {
        $drawings = [];
        $rowNumber = 2;

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }

        foreach ($this->records as $row) {
            if (! empty($row->check_in_photo)) {
                $fileContent = $this->getPhotoContent($row->check_in_photo);

                if ($fileContent) {
                    $tempPath = $tempDir.'/att_in_'.md5($row->id.'_'.$row->check_in_photo).'.jpg';
                    file_put_contents($tempPath, $fileContent);
                    if (file_exists($tempPath)) {
                        $drawing = new Drawing;
                        $drawing->setName('Foto Check-In');
                        $drawing->setDescription('Foto Check-In');
                        $drawing->setPath($tempPath);
                        $drawing->setHeight(40);
                        $drawing->setCoordinates('I'.$rowNumber);
                        $drawing->setOffsetX(10);
                        $drawing->setOffsetY(4);
                        $drawings[] = $drawing;
                    }
                }
            }

            if (! empty($row->check_out_photo)) {
                $fileContent = $this->getPhotoContent($row->check_out_photo);

                if ($fileContent) {
                    $tempPath = $tempDir.'/att_out_'.md5($row->id.'_'.$row->check_out_photo).'.jpg';
                    file_put_contents($tempPath, $fileContent);
                    if (file_exists($tempPath)) {
                        $drawing = new Drawing;
                        $drawing->setName('Foto Check-Out');
                        $drawing->setDescription('Foto Check-Out');
                        $drawing->setPath($tempPath);
                        $drawing->setHeight(40);
                        $drawing->setCoordinates('J'.$rowNumber);
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
            'I' => 16,  // Foto Check-In
            'J' => 16,  // Foto Check-Out
            'K' => 24,  // GPS Check-In
            'L' => 24,  // GPS Check-Out
            'M' => 22,  // Status
            'N' => 14,  // Menit Telat
            'O' => 28,  // Keterangan
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $totalRows = count($this->records) + 1;

        for ($i = 2; $i <= $totalRows; $i++) {
            $sheet->getRowDimension($i)->setRowHeight(42);
        }

        $sheet->getRowDimension(1)->setRowHeight(28);

        $sheet->getStyle("E2:M{$totalRows}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E2:M{$totalRows}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A2:D{$totalRows}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("N2:N{$totalRows}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

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
