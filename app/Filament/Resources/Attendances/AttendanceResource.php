<?php

namespace App\Filament\Resources\Attendances;

use App\Enums\AttendanceStatus;
use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Services\ApprovalFlowService;
use App\Services\AttendanceFigures;
use App\Support\StorageUrl;
use BackedEnum;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class AttendanceResource extends Resource
{
    use ScopesToManagedCompanies;

    protected static ?string $model = Attendance::class;

    protected static ?string $modelLabel = 'Data Absensi';

    protected static ?string $pluralModelLabel = 'Data Absensi';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Presensi & Pengajuan';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return static::canManageWorkerData();
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with(['employee', 'intern', 'freelancer']);

        return $user ? $query->visibleTo($user) : $query->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Absensi')
                    ->schema([
                        Grid::make(2)->schema([
                            DateTimePicker::make('check_in')
                                ->label('Waktu Check In')
                                ->nullable()
                                ->seconds(false)
                                ->rules([
                                    fn (?Attendance $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                        if (filled($value) && $record?->date
                                            && Carbon::parse($value)->toDateString() !== $record->date->toDateString()) {
                                            $fail('Waktu check in harus pada tanggal absensi ('.$record->date->translatedFormat('d M Y').').');
                                        }
                                    },
                                ]),

                            DateTimePicker::make('check_out')
                                ->label('Waktu Check Out')
                                ->nullable()
                                ->seconds(false)
                                ->rules([
                                    fn (Get $get, ?Attendance $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                        if (blank($value)) {
                                            return;
                                        }

                                        $checkOut = Carbon::parse($value);
                                        $checkIn = $get('check_in');

                                        if (filled($checkIn) && $checkOut->lessThanOrEqualTo(Carbon::parse($checkIn))) {
                                            $fail('Waktu check out harus setelah waktu check in.');

                                            return;
                                        }

                                        if ($record?->date
                                            && ! in_array($checkOut->toDateString(), [$record->date->toDateString(), $record->date->copy()->addDay()->toDateString()], true)) {
                                            $fail('Waktu check out harus pada tanggal absensi atau keesokan harinya (shift malam).');
                                        }
                                    },
                                ]),

                            Select::make('status')
                                ->label('Status')
                                ->options(AttendanceStatus::class)
                                ->required()
                                ->rules([
                                    fn (Get $get, ?Attendance $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                        $status = $value instanceof AttendanceStatus ? $value : AttendanceStatus::tryFrom((string) $value);
                                        $lateMinutes = (int) $get('late_minutes');

                                        if ($record?->status === AttendanceStatus::PendingApproval
                                            && $status !== AttendanceStatus::PendingApproval
                                            && $record->approvalSteps()->where('status', 'pending')->exists()) {
                                            $fail('Absensi ini masih menunggu persetujuan. Setujui atau tolak melalui menu Persetujuan, bukan dengan mengubah statusnya di sini.');

                                            return;
                                        }

                                        if ($status === AttendanceStatus::Late && $lateMinutes <= 0) {
                                            $fail('Status Terlambat membutuhkan jumlah menit keterlambatan lebih dari 0.');
                                        }

                                        if ($status === AttendanceStatus::OnTime && $lateMinutes > 0) {
                                            $fail('Status Tepat Waktu tidak boleh memiliki menit keterlambatan. Kosongkan keterlambatan atau pilih status Terlambat.');
                                        }
                                    },
                                ]),

                            TextInput::make('late_minutes')
                                ->label('Keterlambatan (menit)')
                                ->numeric()
                                ->default(0)
                                ->minValue(0),
                        ]),

                        Textarea::make('notes')
                            ->label('Catatan / Keterangan')
                            ->nullable()
                            ->columnSpanFull(),
                    ]),

                // The schedule the row was checked in to, read-only: it changes only through an approved
                // schedule correction ("Koreksi jadwal"), never by editing the attendance.
                Section::make('Jadwal Saat Absen')
                    ->description('Shift dan outlet yang berlaku saat absen. Untuk mengubahnya gunakan Koreksi jadwal.')
                    ->visible(fn (?Attendance $record): bool => $record !== null)
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('schedule_shift_snapshot')
                                ->label('Shift')
                                ->state(fn (?Attendance $record): string => $record ? (AttendanceFigures::forAttendance($record)['shift']['name'] ?? '—') : '—'),
                            TextEntry::make('schedule_outlet_snapshot')
                                ->label('Outlet')
                                ->state(fn (?Attendance $record): string => $record ? (AttendanceFigures::forAttendance($record)['outlet']['name'] ?? '—') : '—'),
                            TextEntry::make('schedule_hours_snapshot')
                                ->label('Jam Jadwal')
                                ->state(function (?Attendance $record): string {
                                    $figures = $record ? AttendanceFigures::forAttendance($record) : null;

                                    return $figures && $figures['scheduled_start'] !== null ? "{$figures['scheduled_start']} - {$figures['scheduled_end']}" : '—';
                                }),
                        ]),
                    ]),
            ]);
    }

    /**
     * Delete an attendance on the panel and record the deletion as an admin correction holding the values
     * the row had, in one transaction.
     */
    public static function deleteWithAudit(Attendance $record): bool
    {
        return DB::transaction(function () use ($record): bool {
            $before = $record->auditValues();
            AttendanceCorrection::recordAdminChange($record, $before, auth()->user(), 'Dihapus admin di halaman Data Absensi.', deleted: true);

            return (bool) $record->delete();
        });
    }

    /**
     * "Koreksi jadwal": file a correction of the shift and outlet that really applied to a checked-in row.
     * It goes through the correction approval flow like a worker's request; once approved it changes the
     * roster row (the only way to change a locked one), keeps the new schedule on the row and judges it again.
     */
    public static function scheduleCorrectionAction(): Action
    {
        return Action::make('koreksiJadwal')
            ->label('Koreksi jadwal')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('warning')
            ->visible(fn (Attendance $record): bool => $record->check_in !== null && $record->status !== AttendanceStatus::Rejected)
            ->modalHeading('Koreksi jadwal absensi')
            ->modalDescription('Pilih shift dan outlet yang sebenarnya berlaku. Koreksi ini menunggu persetujuan seperti pengajuan koreksi absensi.')
            ->schema(fn (Attendance $record): array => [
                Select::make('corrected_shift_id')
                    ->label('Shift yang berlaku')
                    ->options(fn (): array => AttendanceCorrectionResource::shiftOptions(static::draftCorrection($record)))
                    ->default($record->shift_id)
                    ->searchable(),
                Select::make('corrected_company_location_id')
                    ->label('Outlet yang berlaku')
                    ->options(fn (): array => AttendanceCorrectionResource::outletOptions(static::draftCorrection($record)))
                    ->default($record->company_location_id)
                    ->searchable(),
                Textarea::make('reason')
                    ->label('Alasan')
                    ->required(),
            ])
            ->action(function (Attendance $record, array $data): void {
                try {
                    static::fileScheduleCorrection($record, $data);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Koreksi jadwal tidak bisa diajukan')->body(collect($exception->errors())->flatten()->first())->send();

                    return;
                }

                Notification::make()->success()->title('Koreksi jadwal diajukan')->body('Koreksi menunggu persetujuan.')->send();
            });
    }

    /**
     * Create the pending schedule correction of the row with its approval steps.
     *
     * @param  array{corrected_shift_id?: int|string|null, corrected_company_location_id?: int|string|null, reason?: string|null}  $data
     *
     * @throws ValidationException
     */
    public static function fileScheduleCorrection(Attendance $record, array $data): AttendanceCorrection
    {
        $correction = static::draftCorrection($record);
        $correction->fill([
            'corrected_shift_id' => filled($data['corrected_shift_id'] ?? null) ? (int) $data['corrected_shift_id'] : null,
            'corrected_company_location_id' => filled($data['corrected_company_location_id'] ?? null) ? (int) $data['corrected_company_location_id'] : null,
            'reason' => (string) ($data['reason'] ?? ''),
        ]);

        if (! $correction->isScheduleCorrection()) {
            throw ValidationException::withMessages(['corrected_shift_id' => 'Pilih shift atau outlet yang berlaku.']);
        }

        $errors = $correction->resolveTarget(attendanceId: (int) $record->getKey()) ?: $correction->submissionErrors();

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($correction): AttendanceCorrection {
            $correction->save();
            app(ApprovalFlowService::class)->generateSteps($correction, 'correction');

            return $correction;
        });
    }

    /**
     * An unsaved correction of the row's worker and date, filed by an admin.
     */
    private static function draftCorrection(Attendance $record): AttendanceCorrection
    {
        return new AttendanceCorrection([
            'employee_id' => $record->employee_id,
            'intern_id' => $record->intern_id,
            'freelancer_id' => $record->freelancer_id,
            'date' => $record->date?->toDateString(),
            'status' => 'pending',
            'origin' => AttendanceCorrection::ORIGIN_ADMIN,
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('worker_name')
                    ->label('Nama')
                    ->state(function (Attendance $record): string {
                        return $record->employee?->full_name
                            ?? $record->intern?->full_name
                            ?? $record->freelancer?->full_name
                            ?? '—';
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $q) use ($search) {
                            $q->whereHas('employee', fn ($e) => $e->where('full_name', 'like', "%{$search}%"))
                                ->orWhereHas('intern', fn ($i) => $i->where('full_name', 'like', "%{$search}%"))
                                ->orWhereHas('freelancer', fn ($f) => $f->where('full_name', 'like', "%{$search}%"));
                        });
                    })
                    ->sortable(query: fn (Builder $query, string $direction) => $query),

                BadgeColumn::make('worker_type')
                    ->label('Tipe')
                    ->state(function (Attendance $record): string {
                        if ($record->employee_id) {
                            return 'Karyawan';
                        }
                        if ($record->intern_id) {
                            return 'Magang';
                        }

                        return 'Freelance';
                    })
                    ->color(function (Attendance $record): string {
                        if ($record->employee_id) {
                            return 'primary';
                        }
                        if ($record->intern_id) {
                            return 'info';
                        }

                        return 'warning';
                    }),

                TextColumn::make('company_name')
                    ->label('Perusahaan')
                    ->state(function (Attendance $record): string {
                        return $record->employee?->company?->name
                            ?? $record->intern?->company?->name
                            ?? $record->freelancer?->company?->name
                            ?? '—';
                    }),

                TextColumn::make('date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('check_in')
                    ->label('Check In')
                    ->time('H:i')
                    ->sortable(),

                TextColumn::make('check_out')
                    ->label('Check Out')
                    ->time('H:i')
                    ->placeholder('—'),

                // The schedule the row was checked in to, as kept on the row (not today's roster).
                TextColumn::make('schedule_shift')
                    ->label('Shift')
                    ->state(fn (Attendance $record): ?string => AttendanceFigures::forAttendance($record)['shift']['name'] ?? null)
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('schedule_outlet')
                    ->label('Outlet')
                    ->state(fn (Attendance $record): ?string => AttendanceFigures::forAttendance($record)['outlet']['name'] ?? null)
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('schedule_hours')
                    ->label('Jadwal')
                    ->state(function (Attendance $record): ?string {
                        $figures = AttendanceFigures::forAttendance($record);

                        return $figures['scheduled_start'] !== null ? "{$figures['scheduled_start']} - {$figures['scheduled_end']}" : null;
                    })
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('schedule_session')
                    ->label('Sesi')
                    ->state(fn (Attendance $record): string => 'Jadwal '.((int) ($record->schedule_sequence ?: 1)).((int) $record->segment === 2 ? ' · bagian 2' : ''))
                    ->toggleable(isToggledHiddenByDefault: true),

                BadgeColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (AttendanceStatus $state): string => $state->getLabel())
                    ->color(fn (AttendanceStatus $state): string => $state->getColor()),

                TextColumn::make('late_minutes')
                    ->label('Terlambat')
                    ->formatStateUsing(fn (?int $state): string => $state ? "{$state} mnt" : '—')
                    ->sortable(),

                BadgeColumn::make('is_out_of_bounds')
                    ->label('Geofence')
                    ->state(fn (Attendance $record): string => $record->is_out_of_bounds ? 'Luar Geofence' : 'Normal')
                    ->color(fn (Attendance $record): string => $record->is_out_of_bounds ? 'warning' : 'gray'),

                TextColumn::make('is_mock_location')
                    ->label('Lokasi Palsu')
                    ->badge()
                    ->state(fn (Attendance $record): string => $record->is_mock_location ? 'Lokasi palsu' : '—')
                    ->color(fn (Attendance $record): string => $record->is_mock_location ? 'danger' : 'gray'),

                TextColumn::make('face_result')
                    ->label('Verifikasi Wajah')
                    ->badge()
                    ->state(fn (Attendance $record): string => static::faceResultLabel($record))
                    ->color(fn (Attendance $record): string => match ($record->is_face_verified) {
                        true => 'success',
                        false => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (Attendance $record): ?string => $record->face_verification_notes)
                    ->toggleable(),

                TextColumn::make('liveness_result')
                    ->label('Liveness')
                    ->badge()
                    ->state(fn (Attendance $record): string => static::livenessResultLabel($record))
                    ->color(fn (Attendance $record): string => match ($record->liveness_verified) {
                        true => 'success',
                        false => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (Attendance $record): ?string => $record->liveness_notes)
                    ->toggleable(),

                ImageColumn::make('check_in_photo')
                    ->label('Foto Masuk')
                    ->circular()
                    ->state(fn (Attendance $record): ?string => StorageUrl::for($record->check_in_photo))
                    ->toggleable(isToggledHiddenByDefault: true),

                ImageColumn::make('check_out_photo')
                    ->label('Foto Keluar')
                    ->circular()
                    ->state(fn (Attendance $record): ?string => StorageUrl::for($record->check_out_photo))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                SelectFilter::make('worker_type')
                    ->label('Tipe Worker')
                    ->options([
                        'employee' => 'Karyawan',
                        'intern' => 'Magang',
                        'freelancer' => 'Freelance',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'employee' => $query->whereNotNull('employee_id'),
                            'intern' => $query->whereNotNull('intern_id'),
                            'freelancer' => $query->whereNotNull('freelancer_id'),
                            default => $query,
                        };
                    }),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options(AttendanceStatus::class),

                SelectFilter::make('company')
                    ->label('Perusahaan')
                    ->options(fn (): array => static::placeableCompanyOptions(activeOnly: false))
                    ->query(function (Builder $query, array $data): Builder {
                        $companyId = $data['value'] ?? null;
                        if (! $companyId) {
                            return $query;
                        }

                        return $query->where(function (Builder $q) use ($companyId) {
                            $q->whereHas('employee', fn ($e) => $e->where('company_id', $companyId))
                                ->orWhereHas('intern', fn ($i) => $i->where('company_id', $companyId))
                                ->orWhereHas('freelancer', fn ($f) => $f->where('company_id', $companyId));
                        });
                    }),

                Filter::make('date_range')
                    ->label('Rentang Tanggal')
                    ->form([
                        DatePicker::make('date_from')
                            ->label('Dari Tanggal'),
                        DatePicker::make('date_until')
                            ->label('Sampai Tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['date_from'], fn ($q, $date) => $q->whereDate('date', '>=', $date))
                            ->when($data['date_until'], fn ($q, $date) => $q->whereDate('date', '<=', $date));
                    }),

                Filter::make('is_corrected')
                    ->label('Sudah Dikoreksi')
                    ->query(fn (Builder $query) => $query->where('is_corrected', true))
                    ->toggle(),

                Filter::make('is_out_of_bounds')
                    ->label('Luar Geofence')
                    ->query(fn (Builder $query) => $query->where('is_out_of_bounds', true))
                    ->toggle(),

                Filter::make('is_mock_location')
                    ->label('Lokasi Palsu')
                    ->query(fn (Builder $query) => $query->where('is_mock_location', true))
                    ->toggle(),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make()->label('Override'),
                static::scheduleCorrectionAction(),
                DeleteAction::make()->using(fn (Attendance $record): bool => static::deleteWithAudit($record)),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->using(fn (Collection $records) => $records->each(fn (Attendance $record): bool => static::deleteWithAudit($record))),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'employee.company', 'employee.user',
                'intern.company', 'intern.user',
                'freelancer.company', 'freelancer.user',
            ]));
    }

    /**
     * Read-only view used by the table's view action: the attendance plus its verification results.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Absensi')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('date')->label('Tanggal')->date('d M Y'),
                            TextEntry::make('status')->label('Status')->badge(),
                            TextEntry::make('check_in')->label('Check In')->dateTime('H:i:s')->placeholder('—'),
                            TextEntry::make('check_out')->label('Check Out')->dateTime('H:i:s')->placeholder('—'),
                            TextEntry::make('late_minutes')
                                ->label('Keterlambatan')
                                ->formatStateUsing(fn (?int $state): string => $state ? "{$state} menit" : '—'),
                            TextEntry::make('notes')->label('Catatan')->placeholder('—'),
                            TextEntry::make('off_schedule')
                                ->label('Di Luar Jadwal')
                                ->state(fn (Attendance $record): ?string => $record->offScheduleLabel())
                                ->visible(fn (Attendance $record): bool => (bool) $record->is_off_schedule)
                                ->badge()
                                ->color('warning'),
                        ]),
                    ]),

                Section::make('Hasil Verifikasi')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('location_result')
                                ->label('Lokasi')
                                ->badge()
                                ->state(fn (Attendance $record): string => match (true) {
                                    (bool) $record->is_mock_location => 'Lokasi palsu',
                                    (bool) $record->is_out_of_bounds => 'Luar geofence',
                                    default => 'Normal',
                                })
                                ->color(fn (Attendance $record): string => match (true) {
                                    (bool) $record->is_mock_location => 'danger',
                                    (bool) $record->is_out_of_bounds => 'warning',
                                    default => 'gray',
                                }),
                            TextEntry::make('face_result')
                                ->label('Verifikasi Wajah')
                                ->badge()
                                ->state(fn (Attendance $record): string => static::faceResultLabel($record))
                                ->color(fn (Attendance $record): string => match ($record->is_face_verified) {
                                    true => 'success',
                                    false => 'danger',
                                    default => 'gray',
                                })
                                ->helperText(fn (Attendance $record): ?string => $record->face_verification_notes),
                            TextEntry::make('liveness_result')
                                ->label('Liveness')
                                ->badge()
                                ->state(fn (Attendance $record): string => static::livenessResultLabel($record))
                                ->color(fn (Attendance $record): string => match ($record->liveness_verified) {
                                    true => 'success',
                                    false => 'danger',
                                    default => 'gray',
                                })
                                ->helperText(fn (Attendance $record): ?string => $record->liveness_notes),
                        ]),
                    ]),
            ]);
    }

    public static function faceResultLabel(Attendance $record): string
    {
        return match ($record->is_face_verified) {
            true => "Cocok ({$record->face_match_score}%)",
            false => "Tidak cocok ({$record->face_match_score}%)",
            default => '—',
        };
    }

    public static function livenessResultLabel(Attendance $record): string
    {
        return match ($record->liveness_verified) {
            true => 'Lolos',
            false => 'Gagal',
            default => '—',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendances::route('/'),
            'edit' => Pages\EditAttendance::route('/{record}/edit'),
        ];
    }
}
