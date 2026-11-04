<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\LeaveRequest;
use App\Services\DataAccessService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

class KalenderCuti extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $title = 'Kalender Cuti Tim';

    protected static ?string $navigationLabel = 'Kalender Cuti Tim';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    protected static string|UnitEnum|null $navigationGroup = 'Presensi & Pengajuan';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.kalender-cuti';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user?->intern !== null) {
            return false;
        }

        return true;
    }

    public ?int $selectedMonth = null;

    public ?int $selectedYear = null;

    public ?int $selectedCompanyId = null;

    public function mount(): void
    {
        $this->selectedMonth = now()->month;
        $this->selectedYear = now()->year;

        $this->form->fill([
            'selectedMonth' => $this->selectedMonth,
            'selectedYear' => $this->selectedYear,
            'selectedCompanyId' => $this->selectedCompanyId,
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Filter Kalender Cuti')
                    ->compact()
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('selectedMonth')
                                ->label('Bulan')
                                ->options([
                                    1 => 'Januari',
                                    2 => 'Februari',
                                    3 => 'Maret',
                                    4 => 'April',
                                    5 => 'Mei',
                                    6 => 'Juni',
                                    7 => 'Juli',
                                    8 => 'Agustus',
                                    9 => 'September',
                                    10 => 'Oktober',
                                    11 => 'November',
                                    12 => 'Desember',
                                ])
                                ->live()
                                ->required(),

                            Select::make('selectedYear')
                                ->label('Tahun')
                                ->options([
                                    2025 => '2025',
                                    2026 => '2026',
                                    2027 => '2027',
                                ])
                                ->live()
                                ->required(),

                            Select::make('selectedCompanyId')
                                ->label('Perusahaan (Opsional)')
                                ->options(fn (): array => static::companyOptions())
                                ->placeholder('Semua Perusahaan')
                                ->live()
                                ->nullable(),
                        ]),
                    ]),
            ]);
    }

    /**
     * Companies on the calendar: the user's data access plus their own company.
     *
     * @return array<int, string>
     */
    public static function companyOptions(): array
    {
        $user = auth()->user();

        if (! $user) {
            return [];
        }

        return DataAccessService::scopeCompanies(Company::query()->where('is_active', true), $user, 'companies.id')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function getLeaveRequestsProperty()
    {
        $month = $this->selectedMonth ?: now()->month;
        $year = $this->selectedYear ?: now()->year;

        $startOfMonth = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $endOfMonth = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        // A leave is shown in every month it overlaps, not only the month it starts in.
        $query = LeaveRequest::with([
            'employee.company', 'employee.division',
            'intern.company', 'intern.division',
            'freelancer.company', 'freelancer.division',
        ])
            ->whereIn('status', ['approved', 'pending'])
            ->whereDate('start_date', '<=', $endOfMonth)
            ->whereDate('end_date', '>=', $startOfMonth);

        if ($this->selectedCompanyId) {
            $query->whereWorkerInCompanies([(int) $this->selectedCompanyId]);
        }

        $user = auth()->user();

        if ($user) {
            $query->visibleWithColleaguesTo($user);
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query->orderBy('start_date')->get();
    }
}
