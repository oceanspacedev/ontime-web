<?php

namespace App\Filament\Resources\ApprovalFlows\RelationManagers;

use App\Filament\Resources\ApprovalFlows\ApprovalFlowResource;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Division;
use App\Services\ApprovalFlowService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Approval rules a division sets for itself. They override the company-wide flow for workers in
 * that division and the divisions below it; request types without a rule keep the company's flow.
 */
class DivisionApprovalFlowsRelationManager extends RelationManager
{
    protected static string $relationship = 'divisionApprovalFlows';

    protected static ?string $title = 'Aturan per Divisi';

    public function table(Table $table): Table
    {
        return $table
            ->description('Divisi yang tidak diatur di sini, beserta sub-divisinya, ikut alur badan usaha di atas.')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['division', 'user'])->orderBy('request_type')->orderBy('step_order'))
            ->defaultGroup(
                Group::make('division_id')
                    ->getKeyFromRecordUsing(fn (ApprovalFlow $record): string => "{$record->division_id}-{$record->request_type}")
                    ->getTitleFromRecordUsing(fn (ApprovalFlow $record): string => ($record->division?->pathName() ?? '-').' · '.ApprovalFlowService::requestTypeLabel($record->request_type))
                    ->orderQueryUsing(fn (Builder $query, string $direction) => $query->orderBy('division_id', $direction)->orderBy('request_type')->orderBy('step_order'))
                    ->titlePrefixedWithLabel(false)
            )
            ->groupingSettingsHidden()
            ->paginated(false)
            ->columns([
                TextColumn::make('step_order')
                    ->label('Tahap')
                    ->width('1%'),
                TextColumn::make('name')
                    ->label('Nama tahap'),
                TextColumn::make('approver')
                    ->label('Approver')
                    ->state(fn (ApprovalFlow $record): string => $record->approver_type === 'user'
                        ? ($record->user?->name ?? 'User dihapus')
                        : (string) $record->approver_role)
                    ->badge()
                    ->color(fn (ApprovalFlow $record): string => $record->approver_type === 'user' ? 'info' : 'gray'),
            ])
            ->emptyStateHeading('Belum ada aturan divisi')
            ->emptyStateDescription('Semua divisi memakai alur badan usaha.')
            ->headerActions([
                $this->ruleAction('addDivisionRule')
                    ->label('Tambah aturan divisi')
                    ->icon(Heroicon::Plus)
                    ->modalHeading('Tambah aturan divisi'),
            ])
            ->recordActions([
                $this->ruleAction('editDivisionRule')
                    ->label('Ubah')
                    ->icon(Heroicon::PencilSquare)
                    ->modalHeading('Ubah aturan divisi')
                    ->fillForm(fn (ApprovalFlow $record): array => [
                        'company_id' => $record->company_id,
                        'division_id' => $record->division_id,
                        'request_types' => [$record->request_type],
                        'steps' => $this->ruleSteps($record)
                            ->map(fn (ApprovalFlow $step): array => $step->only(['name', 'approver_type', 'approver_role', 'user_id']))
                            ->all(),
                    ]),
                Action::make('deleteDivisionRule')
                    ->label('Hapus')
                    ->icon(Heroicon::Trash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Hapus aturan divisi?')
                    ->modalDescription('Divisi ini kembali memakai alur badan usaha untuk jenis pengajuan ini. Pengajuan yang sedang berjalan tidak berubah.')
                    ->action(function (ApprovalFlow $record): void {
                        ApprovalFlow::whereKey($this->ruleSteps($record)->modelKeys())->delete();
                        Notification::make()->success()->title('Aturan divisi dihapus')->send();
                    }),
            ]);
    }

    /**
     * The slide-over that adds or replaces a division's rule for one or more request types.
     */
    private function ruleAction(string $name): Action
    {
        /** @var Company $company */
        $company = $this->getOwnerRecord();

        return Action::make($name)
            ->slideOver()
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel('Simpan')
            ->modalCancelActionLabel('Batal')
            ->schema([
                Hidden::make('company_id')
                    ->default($company->id),
                Select::make('division_id')
                    ->label('Divisi')
                    ->placeholder('Pilih divisi')
                    ->options(fn (): array => $this->divisionOptions($company))
                    ->searchable()
                    ->required()
                    ->live()
                    ->helperText('Berlaku juga untuk sub-divisinya, kecuali yang punya aturan sendiri.'),
                CheckboxList::make('request_types')
                    ->label('Berlaku untuk')
                    ->options(ApprovalFlowService::REQUEST_TYPE_LABELS)
                    ->columns(2)
                    ->required()
                    ->helperText('Aturan divisi yang sudah ada untuk jenis yang dipilih akan diganti.'),
                ApprovalFlowResource::getStepRepeaterSchema('steps')
                    ->hiddenLabel(false),
            ])
            ->action(function (array $data) use ($company): void {
                app(ApprovalFlowService::class)->saveDivisionRule($company, (int) $data['division_id'], $data['request_types'], array_values($data['steps'] ?? []));
                Notification::make()->success()->title('Aturan divisi disimpan')->send();
            });
    }

    /**
     * @return Collection<int, ApprovalFlow>
     */
    private function ruleSteps(ApprovalFlow $record): Collection
    {
        return ApprovalFlow::query()
            ->where('company_id', $record->company_id)
            ->where('division_id', $record->division_id)
            ->where('request_type', $record->request_type)
            ->orderBy('step_order')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function divisionOptions(Company $company): array
    {
        return Division::query()
            ->with('parent')
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (Division $division): array => [$division->id => $division->pathName()])
            ->sort()
            ->all();
    }
}
