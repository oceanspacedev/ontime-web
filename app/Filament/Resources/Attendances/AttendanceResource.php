<?php

namespace App\Filament\Resources\Attendances;

use App\Enums\AttendanceStatus;
use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Models\Attendance;
use BackedEnum;
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
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
                                ->seconds(false),

                            DateTimePicker::make('check_out')
                                ->label('Waktu Check Out')
                                ->nullable()
                                ->seconds(false),

                            Select::make('status')
                                ->label('Status')
                                ->options(AttendanceStatus::class)
                                ->required(),

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
                    ->disk(config('filesystems.default'))
                    ->toggleable(isToggledHiddenByDefault: true),

                ImageColumn::make('check_out_photo')
                    ->label('Foto Keluar')
                    ->circular()
                    ->disk(config('filesystems.default'))
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
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
