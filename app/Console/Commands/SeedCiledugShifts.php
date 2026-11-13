<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Division;
use App\Models\Shift;
use Illuminate\Console\Command;

class SeedCiledugShifts extends Command
{
    protected $signature = 'shifts:seed-ciledug {company : Company id or code} {--dry-run : Show what would be created without saving}';

    protected $description = 'Create the brand shifts of the Ciledug outlets (JChicken, Momoyo, Luuca) with their Talenta codes; shifts the brand already has (same name or code) are left alone';

    /**
     * Brand => shifts as [name, code, start, end, talenta code, second start, second end], from the
     * outlets' own schedule sheets (week 44, November 2026). Hours that differ by weekday are separate
     * shifts, and a split shift has a second segment. Talenta codes read from the sheets: check them.
     *
     * @var array<string, list<array{0: string, 1: string, 2: string, 3: string, 4: string|null, 5?: string, 6?: string}>>
     */
    private const PRESET = [
        'JCHICKEN' => [
            ['Pagi', 'P', '08:00', '17:00', null],
            ['Pagi Opening', 'PO', '08:00', '13:30', null],
            ['Midle 10', 'M10', '10:00', '19:00', null],
            ['Midle 12', 'M12', '12:00', '21:00', null],
            ['Siang', 'S', '13:30', '22:30', null],
            ['Sore Closing 17', 'SC', '17:00', '22:30', null],
            ['Sore Closing 19', 'SC19', '19:00', '22:30', null],
        ],
        'MOMOYO' => [
            ['Pagi Event', 'PE', '07:00', '16:00', 'Regular 1'],
            ['Pagi', 'P', '08:00', '17:00', 'Momoyo Pagi'],
            ['Pagi 08.30', 'P830', '08:30', '17:30', null],
            ['Midle 10', 'M10', '10:00', '19:00', null],
            ['Midle 11', 'M11', '11:00', '20:00', 'Regular 13'],
            ['Siang', 'S', '13:00', '22:00', 'Momoyo Siang'],
            ['Sore Closing', 'SC', '17:00', '22:00', null],
            ['Long Shift', 'LS', '08:00', '22:00', 'Momoyo LS'],
            ['Split', 'SP', '07:45', '13:00', 'Regular 11', '17:45', '22:00'],
            ['Split GC', 'SP GC', '07:45', '13:00', 'Momoyo Pagi', '20:00', '23:00'],
            ['Siang GC', 'S GC', '14:00', '23:00', 'Khusus Parttime'],
            ['Sore Closing GC', 'SC GC', '17:45', '23:00', 'Khusus Parttime'],
            ['Housekeeping', 'HK', '10:00', '19:00', 'Regular 11'],
        ],
        'LUUCA' => [
            ['Pagi 09', 'P', '09:00', '18:00', 'SIFT 09 -18'],
            ['Pagi 08 (Minggu)', 'P08', '08:00', '17:00', 'SIFT 08 -17'],
            ['Pagi Opening', 'PO', '09:00', '13:30', null],
            ['Siang 13', 'S', '13:30', '22:30', 'LUUCA SHIFT 13.30'],
            ['Siang 14 (Sabtu)', 'S14', '14:30', '23:30', 'LUUCA SHIFT 14.30'],
            ['Midle', 'M', '11:00', '20:00', 'LUUCA SHIFT 11.00'],
            ['Long Midle', 'LM', '11:00', '22:30', 'LUUCA SHIFT 11.00 - 22.30'],
            ['Long Midle (Sabtu)', 'LM12', '12:00', '23:30', null],
            ['Long Shift', 'LS', '08:45', '22:30', 'LS LUUCA SHIFT 09.00 - 22.30'],
            ['Sore Closing', 'SC', '18:00', '22:30', null],
        ],
    ];

    public function handle(): int
    {
        $key = (string) $this->argument('company');
        $company = Company::query()->where('id', ctype_digit($key) ? (int) $key : 0)->orWhere('code', $key)->first();

        if (! $company) {
            $this->error("Badan usaha \"{$key}\" tidak ditemukan.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $existing = 0;

        foreach (self::PRESET as $brandName => $shifts) {
            $brand = Division::query()->where('company_id', $company->id)->where('name', $brandName)->first();

            if (! $brand) {
                $this->line("Brand {$brandName}: ".($dryRun ? 'akan dibuat' : 'dibuat').'.');
                $brand = $dryRun ? new Division(['company_id' => $company->id, 'name' => $brandName]) : Division::create(['company_id' => $company->id, 'name' => $brandName, 'is_active' => true]);
            }

            foreach ($shifts as $row) {
                [$name, $code, $start, $end, $talenta] = $row;
                $secondStart = $row[5] ?? null;
                $secondEnd = $row[6] ?? null;

                $attributes = ['company_id' => $company->id, 'division_id' => $brand->exists ? $brand->id : null, 'name' => $name];

                // A brand already holding a shift with this name or this code keeps its own.
                if ($brand->exists && Shift::query()->where('company_id', $company->id)->where('division_id', $brand->id)->where(fn ($same) => $same->where('name', $name)->orWhere('code', $code))->exists()) {
                    $existing++;

                    continue;
                }

                if (! $dryRun) {
                    Shift::create($attributes + [
                        'code' => $code,
                        'talenta_code' => $talenta,
                        'start_time' => $start,
                        'end_time' => $end,
                        'second_start_time' => $secondStart,
                        'second_end_time' => $secondEnd,
                        'is_active' => true,
                    ]);
                }

                $created++;
            }
        }

        $verb = $dryRun ? 'akan dibuat' : 'dibuat';
        $this->info("{$created} shift {$verb}, {$existing} sudah ada dan dibiarkan. Outlet (lokasi dan koordinat) belum dibuat: tambahkan di Lokasi & Cabang Kantor.");

        return self::SUCCESS;
    }
}
