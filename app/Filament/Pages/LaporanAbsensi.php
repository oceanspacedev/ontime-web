<?php

namespace App\Filament\Pages;

use App\Enums\AttendanceStatus;
use App\Exports\AttendanceReportData;
use App\Exports\AttendanceReportExport;
use App\Filament\Actions\TeamRecapAction;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Services\Attendance\LedgerQuery;
use App\Services\AttendanceFigures;
use App\Services\AttendanceLedger;
use App\Services\DataAccessService;
use App\Support\StorageUrl;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

class LaporanAbsensi extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable {
        getTableRecords as private filamentTableRecords;
    }

    protected static ?string $title = 'Laporan Absensi';

    protected static ?string $navigationLabel = 'Laporan Absensi';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static string|UnitEnum|null $navigationGroup = 'Presensi & Pengajuan';

    protected static ?int $navigationSort = 3;

    public static function shouldRegisterNavigation(): bool
    {
        return ! DataAccessService::isCoordinator(auth()->user());
    }

    protected string $view = 'filament.pages.laporan-absensi';

    public ?array $filterData = [];

    protected function getHeaderActions(): array
    {
        return [
            TeamRecapAction::make(),
        ];
    }

    public function mount(): void
    {
        $this->form->fill([
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => today()->toDateString(),
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        $user = auth()->user();
        $visibleCompanyIds = $user ? DataAccessService::visibleCompanyIds($user) : [];
        $visibleDivisionIds = $user ? DataAccessService::visibleDivisionIds($user) : [];

        return $schema
            ->statePath('filterData')
            ->components([
                Section::make('Filter Laporan')
                    ->schema([
                        Grid::make(4)->schema([
                            DatePicker::make('date_from')
                                ->label('Dari Tanggal')
                                ->required()
                                ->live(),

                            DatePicker::make('date_to')
                                ->label('Sampai Tanggal')
                                ->required()
                                ->live(),

                            Select::make('company_id')
                                ->label('Badan Usaha')
                                ->options(fn (): array => Company::query()
                                    ->where('is_active', true)
                                    ->when($visibleCompanyIds !== null, fn (Builder $query) => $query->whereIn('id', $visibleCompanyIds))
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->live()
                                ->placeholder('Semua Company'),

                            Select::make('division_id')
                                ->label('Divisi')
                                ->options(fn (Get $get): array => Division::query()
                                    ->where('is_active', true)
                                    ->when($get('company_id'), fn (Builder $query, $companyId) => $query->where('company_id', $companyId))
                                    ->when($visibleCompanyIds !== null, fn (Builder $query) => $query->whereIn('company_id', $visibleCompanyIds))
                                    ->when($visibleDivisionIds !== null, fn (Builder $query) => $query->whereIn('id', $visibleDivisionIds))
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->live()
                                ->placeholder('Semua Divisi'),
                        ]),
                    ]),
            ]);
    }

    public function getReportQuery(): Builder
    {
        $user = auth()->user();

        $query = Attendance::query()->with([
            'employee.company',
            'employee.division',
            'employee.user',
            'intern.company',
            'intern.division',
            'intern.user',
            'freelancer.company',
            'freelancer.division',
            'freelancer.user',
            'shift',
        ]);

        // The rows follow the summary and the export: workers in the user's data access, and whoever worked
        // at an outlet in it (the scope of the attendance ledger and of the app's report).
        if ($user) {
            DataAccessService::scopeWorkerOrOutletRecords($query, $user, Attendance::workerRelations());
        } else {
            $query->whereRaw('1 = 0');
        }

        // Apply Form Filters
        $data = $this->filterData;

        if (! empty($data['date_from'])) {
            $query->whereDate('date', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('date', '<=', $data['date_to']);
        }
        if (! empty($data['company_id'])) {
            $cid = $data['company_id'];
            $query->where(function ($q) use ($cid) {
                $q->whereHas('employee', fn ($s) => $s->where('company_id', $cid))
                    ->orWhereHas('intern', fn ($s) => $s->where('company_id', $cid))
                    ->orWhereHas('freelancer', fn ($s) => $s->where('company_id', $cid));
            });
        }
        if (! empty($data['division_id'])) {
            $did = $data['division_id'];
            $query->where(function ($q) use ($did) {
                $q->whereHas('employee', fn ($s) => $s->where('division_id', $did))
                    ->orWhereHas('intern', fn ($s) => $s->where('division_id', $did))
                    ->orWhereHas('freelancer', fn ($s) => $s->where('division_id', $did));
            });
        }

        return $query->orderBy('date', 'desc');
    }

    /**
     * What the summary and the export ask the attendance ledger: the filtered dates, company and division
     * for the signed-in user. Null without a user or a usable range. A range longer than a year is narrowed
     * to the dates that have attendance, so a mistyped year does not read thousands of empty days.
     */
    public function ledgerQuery(): ?LedgerQuery
    {
        $user = auth()->user();
        $data = $this->filterData ?? [];

        if (! $user || blank($data['date_from'] ?? null) || blank($data['date_to'] ?? null)) {
            return null;
        }

        try {
            $from = CarbonImmutable::parse($data['date_from'])->startOfDay();
            $to = CarbonImmutable::parse($data['date_to'])->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($from->diffInDays($to) > AttendanceReportData::MAX_RANGE_DAYS) {
            $dates = $this->getReportQuery()->reorder()->toBase()->selectRaw('MIN(date) AS first_date, MAX(date) AS last_date')->first();

            if (blank($dates?->first_date)) {
                return null;
            }

            $from = $from->max(CarbonImmutable::parse(substr((string) $dates->first_date, 0, 10)));
            $to = $to->min(CarbonImmutable::parse(substr((string) $dates->last_date, 0, 10)));
        }

        if ($from->greaterThan($to)) {
            return null;
        }

        return (new LedgerQuery($from, $to, $user))->with(
            companyId: filled($data['company_id'] ?? null) ? (int) $data['company_id'] : null,
            divisionId: filled($data['division_id'] ?? null) ? (int) $data['division_id'] : null,
        );
    }

    /**
     * The figures above the table, from the attendance ledger: distinct worker-days and hours of the
     * filtered range, the same numbers as the app's laporan absensi and the team recap.
     *
     * @return list<array{label: string, value: string}>
     */
    public function getSummaryProperty(): array
    {
        $query = $this->ledgerQuery();

        if ($query === null || $query->from->diffInDays($query->to) > AttendanceReportData::MAX_RANGE_DAYS) {
            return [];
        }

        $summary = app(AttendanceLedger::class)->summary($query);
        $hours = fn (int $minutes): string => str_replace('.', ',', (string) round($minutes / 60, 2)).' jam';

        return [
            ['label' => 'Hadir', 'value' => $summary['present_days'].' hari'],
            ['label' => 'Terlambat', 'value' => $summary['late_days'].' hari'],
            ['label' => 'Cuti / Izin', 'value' => $summary['leave_days'].' hari'],
            ['label' => 'Tidak Hadir', 'value' => $summary['absent_days'].' hari'],
            ['label' => 'Menunggu Persetujuan', 'value' => $summary['pending_days'].' hari'],
            ['label' => 'Lupa Absen Pulang', 'value' => $summary['missing_check_out_days'].' hari'],
            ['label' => 'Jam Kerja', 'value' => $hours($summary['worked_minutes'])],
            ['label' => 'Lembur', 'value' => $hours($summary['overtime_minutes'])],
        ];
    }

    /**
     * The rows of the page with what their schedule columns need read once, not per row.
     */
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = $this->filamentTableRecords();

        AttendanceFigures::preload($records);

        return $records;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->getReportQuery())
            ->columns([
                TextColumn::make('perusahaan_divisi')
                    ->label('Perusahaan & Divisi')
                    ->state(fn (Attendance $record): string => $record->employee?->company?->name
                        ?? $record->intern?->company?->name
                        ?? $record->freelancer?->company?->name
                        ?? '-'
                    )
                    ->description(fn (Attendance $record): string => $record->employee?->division?->name
                        ?? $record->intern?->division?->name
                        ?? $record->freelancer?->division?->name
                        ?? '-'
                    ),

                TextColumn::make('nama_peserta')
                    ->label('Nama & User')
                    ->state(function (Attendance $record): string {
                        $worker = $record->employee ?? $record->intern ?? $record->freelancer;
                        $name = $worker?->full_name ?? '-';
                        $type = $record->employee ? 'Karyawan' : ($record->intern ? 'Magang' : ($record->freelancer ? 'Freelancer' : 'User'));

                        return "{$name} ({$type})";
                    })
                    ->description(function (Attendance $record): string {
                        $worker = $record->employee ?? $record->intern ?? $record->freelancer;
                        $user = $worker?->user;
                        $nip = $record->employee?->nip ?? $record->intern?->nis ?? $record->freelancer?->freelancer_number ?? '-';
                        $email = $user?->email ? " | Account: {$user->email}" : '';

                        return "NIP/ID: {$nip}{$email}";
                    })
                    ->searchable(query: function ($query, string $search) {
                        $query->where(function ($q) use ($search) {
                            $q->whereHas('employee', fn ($s) => $s->where('full_name', 'like', "%{$search}%")->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%")))
                                ->orWhereHas('intern', fn ($s) => $s->where('full_name', 'like', "%{$search}%")->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%")))
                                ->orWhereHas('freelancer', fn ($s) => $s->where('full_name', 'like', "%{$search}%")->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%")));
                        });
                    }),

                TextColumn::make('date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                // Shift, outlet and hours as they applied at check-in (the schedule kept on the row).
                TextColumn::make('shift.name')
                    ->label('Shift')
                    ->state(fn (Attendance $record): ?string => AttendanceFigures::forAttendance($record)['shift']['name'] ?? null)
                    ->placeholder('Jam kerja BU')
                    ->toggleable(),

                TextColumn::make('outlet')
                    ->label('Outlet')
                    ->state(fn (Attendance $record): ?string => AttendanceFigures::forAttendance($record)['outlet']['name'] ?? null)
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('jadwal')
                    ->label('Jadwal')
                    ->state(function (Attendance $record): ?string {
                        $figures = AttendanceFigures::forAttendance($record);

                        return $figures['scheduled_start'] !== null ? "{$figures['scheduled_start']} - {$figures['scheduled_end']}" : null;
                    })
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('check_in')
                    ->label('Check In')
                    ->dateTime('H:i:s')
                    ->suffix(' WIB')
                    ->placeholder('-'),

                TextColumn::make('check_out')
                    ->label('Check Out')
                    ->dateTime('H:i:s')
                    ->suffix(' WIB')
                    ->placeholder('-'),

                TextColumn::make('jam_kerja')
                    ->label('Jam Kerja')
                    ->state(fn (Attendance $record): ?string => $record->check_in && $record->check_out
                        ? static::durationLabel(AttendanceFigures::forAttendance($record)['worked_minutes'])
                        : null)
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('lembur')
                    ->label('Lembur')
                    ->state(function (Attendance $record): ?string {
                        $minutes = AttendanceFigures::forAttendance($record)['overtime_minutes'];

                        return $minutes > 0 ? static::durationLabel($minutes) : null;
                    })
                    ->placeholder('-')
                    ->tooltip('Lembur otomatis: jam kerja di dalam shift di atas jam normal shift.')
                    ->toggleable(),

                ImageColumn::make('check_in_photo')
                    ->label('Foto Check-In')
                    ->state(fn (Attendance $record): ?string => StorageUrl::for($record->check_in_photo))
                    ->square()
                    ->size(40)
                    ->defaultImageUrl(null),

                ImageColumn::make('check_out_photo')
                    ->label('Foto Check-Out')
                    ->state(fn (Attendance $record): ?string => StorageUrl::for($record->check_out_photo))
                    ->square()
                    ->size(40)
                    ->defaultImageUrl(null),

                TextColumn::make('lokasi_gps')
                    ->label('Lokasi GPS (In / Out)')
                    ->visible(fn ($livewire): bool => $livewire->isGpsRequiredInPolicy())
                    ->state(function (Attendance $record): string {
                        $in = ($record->check_in_lat && $record->check_in_lng)
                            ? "In: {$record->check_in_lat}, {$record->check_in_lng}"
                            : null;
                        $out = ($record->check_out_lat && $record->check_out_lng)
                            ? "Out: {$record->check_out_lat}, {$record->check_out_lng}"
                            : null;

                        if (! $in && ! $out) {
                            return '-';
                        }

                        return implode(' | ', array_filter([$in, $out]));
                    })
                    ->description(function (Attendance $record): ?string {
                        if ($record->check_in_lat && $record->check_in_lng) {
                            return 'Klik koordinat untuk lihat di peta';
                        }

                        return null;
                    })
                    ->url(function (Attendance $record): ?string {
                        if ($record->check_in_lat && $record->check_in_lng) {
                            return "https://www.openstreetmap.org/?mlat={$record->check_in_lat}&mlon={$record->check_in_lng}#map=17/{$record->check_in_lat}/{$record->check_in_lng}";
                        }

                        return null;
                    }, shouldOpenInNewTab: true),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state): string|array|null => AttendanceStatus::tryFrom($state instanceof AttendanceStatus ? $state->value : (string) $state)?->getColor() ?? 'gray')
                    ->formatStateUsing(fn (Attendance $record): string => $this->statusLabel($record)),

                TextColumn::make('notes')
                    ->label('Keterangan')
                    ->state(fn (Attendance $record): string => $record->notes ?? ($record->is_corrected ? 'Koreksi Absensi' : '-')),
            ])
            ->actions([
                Action::make('viewPhoto')
                    ->label('Foto Selfie')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading(fn (Attendance $record): string => 'Foto Absensi: '.(
                        $record->employee?->full_name
                        ?? $record->intern?->full_name
                        ?? $record->freelancer?->full_name
                        ?? '-'
                    ))
                    ->modalContent(fn (Attendance $record) => view('filament.components.attendance-photo-modal', ['record' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->visible(fn (Attendance $record): bool => ! empty($record->check_in_photo) || ! empty($record->check_out_photo)),

                DeleteAction::make('delete')
                    ->label('Hapus')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Hapus Data Absensi User')
                    ->modalDescription('Apakah Anda yakin ingin menghapus data absensi ini? Setelah dihapus, status absensi user pada tanggal ini akan di-reset.')
                    // Recorded as an admin correction holding the deleted values.
                    ->using(fn (Attendance $record): bool => AttendanceResource::deleteWithAudit($record))
                    ->visible(fn (): bool => (bool) auth()->user()?->hasAnyRole(['Superadmin', 'Admin'])),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Hapus Absensi Terpilih')
                        ->requiresConfirmation()
                        ->modalHeading('Hapus Absensi User Terpilih')
                        ->modalDescription('Apakah Anda yakin ingin menghapus semua data absensi terpilih?')
                        ->using(fn (Collection $records) => $records->each(fn (Attendance $record): bool => AttendanceResource::deleteWithAudit($record)))
                        ->visible(fn (): bool => (bool) auth()->user()?->hasAnyRole(['Superadmin', 'Admin'])),
                ]),
            ]);
    }

    /**
     * The row's real status: on time reads "Hadir", late carries its minutes, and absent, rejected and
     * pending rows keep their own label instead of falling back to "Hadir".
     */
    public function statusLabel(Attendance $record): string
    {
        $status = $record->status instanceof AttendanceStatus ? $record->status : AttendanceStatus::tryFrom((string) $record->status);

        $label = match ($status) {
            AttendanceStatus::OnTime => 'Hadir',
            AttendanceStatus::Late => "Terlambat ({$record->late_minutes}m)",
            AttendanceStatus::PendingApproval => $record->reportedLateMinutes() > 0
                ? "Menunggu Persetujuan (telat {$record->reportedLateMinutes()}m)"
                : 'Menunggu Persetujuan',
            null => '-',
            default => $status->getLabel(),
        };

        return $record->early_leave_minutes > 0 ? "{$label} · Pulang cepat ({$record->early_leave_minutes}m)" : $label;
    }

    public function isGpsRequiredInPolicy(): bool
    {
        $companyId = $this->filterData['company_id'] ?? null;
        if ($companyId) {
            $company = Company::find($companyId);

            return (bool) ($company?->policy?->require_gps ?? false);
        }

        return CompanyPolicy::where('require_gps', true)->exists();
    }

    public function getReportDataProperty(): Collection
    {
        return $this->getReportQuery()->get();
    }

    /**
     * Download the report workbook. It is built from the attendance ledger for the filtered range, not
     * from the rows of this page.
     */
    public function exportExcel(): BinaryFileResponse
    {
        return Excel::download(new AttendanceReportExport($this->ledgerQuery()), 'laporan-rekap-absensi.xlsx');
    }

    /**
     * Minutes as "8j 30m".
     */
    public static function durationLabel(int $minutes): string
    {
        return intdiv($minutes, 60).'j '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
