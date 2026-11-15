<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Models\Tenant;
use App\Services\TenantOnboardingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use UnitEnum;

/**
 * Klien: the clients of the platform. Only the Superadmin sees this menu; making a Klien also makes
 * its PIC account, and from there the PIC runs the client without the Superadmin.
 */
class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $modelLabel = 'Klien';

    protected static ?string $pluralModelLabel = 'Klien';

    protected static ?string $slug = 'klien';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan Sistem';

    protected static ?int $navigationSort = 0;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasRole('Superadmin');
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny() && $record instanceof Tenant && $record->deletionBlockedReason() === null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Klien')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('Nama Klien')
                                ->placeholder('Contoh: Grup Franchise Ciledug')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('code')
                                ->label('Kode Klien')
                                ->placeholder('Contoh: CILEDUG')
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->maxLength(50),
                            TextInput::make('contact_name')
                                ->label('Nama Kontak')
                                ->maxLength(255),
                            TextInput::make('contact_phone')
                                ->label('Telepon Kontak')
                                ->tel()
                                ->maxLength(50),
                            TextInput::make('contact_email')
                                ->label('Email Kontak')
                                ->email()
                                ->maxLength(255),
                            Toggle::make('is_active')
                                ->label('Klien Aktif')
                                ->default(true),
                        ]),
                    ]),

                Section::make('Akun PIC')
                    ->description('PIC mengatur sendiri badan usaha, divisi, lokasi, mode absensi, shift, alur persetujuan, dan akun untuk kliennya. Data login dikirim lewat WhatsApp.')
                    ->visibleOn('create')
                    ->schema([
                        Grid::make(2)->schema(static::picFields()),
                    ]),
            ]);
    }

    /**
     * The PIC account fields, shared by the create page and the "Tambah PIC" table action. The email
     * is unique across all accounts and never ignores the current record: that record is the Klien,
     * not a user.
     *
     * @return list<TextInput>
     */
    public static function picFields(): array
    {
        return [
            TextInput::make('pic_name')
                ->label('Nama PIC')
                ->required()
                ->maxLength(255),
            TextInput::make('pic_email')
                ->label('Email Login PIC')
                ->email()
                ->required()
                ->unique('users', 'email', ignoreRecord: false)
                ->maxLength(255),
            TextInput::make('pic_phone')
                ->label('Nomor WhatsApp PIC')
                ->tel()
                ->maxLength(50),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Klien')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('companies_count')
                    ->label('Badan Usaha')
                    ->counts('companies')
                    ->sortable(),
                TextColumn::make('users_count')
                    ->label('Akun')
                    ->counts('users')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->defaultSort('name')
            ->recordActions([
                Action::make('addPic')
                    ->label('Tambah PIC')
                    ->icon('heroicon-o-user-plus')
                    ->modalHeading(fn (Tenant $record): string => "Tambah PIC untuk {$record->name}")
                    ->schema(static::picFields())
                    ->action(fn (Tenant $record, array $data) => static::openPic($record, $data)),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * Create the PIC account and show the login details once, with a WhatsApp link when the gateway did not send them.
     *
     * @param  array{pic_name: string, pic_email: string, pic_phone?: string|null}  $data
     */
    public static function openPic(Tenant $tenant, array $data): void
    {
        try {
            $account = app(TenantOnboardingService::class)->createPic($tenant, $data['pic_name'], $data['pic_email'], $data['pic_phone'] ?? null);
        } catch (InvalidArgumentException $exception) {
            Notification::make()->title('Akun PIC belum dibuat')->body($exception->getMessage())->danger()->send();

            return;
        }

        $whatsapp = $account['whatsapp'];
        $status = match (true) {
            (bool) ($whatsapp['api_sent'] ?? false) => 'Data login sudah dikirim ke WhatsApp PIC.',
            filled($whatsapp['wa_url'] ?? null) => 'WhatsApp belum terkirim otomatis. Kirim lewat tombol di bawah.',
            default => 'Nomor WhatsApp kosong. Berikan data login ini langsung ke PIC.',
        };

        $notification = Notification::make()
            ->title("Akun PIC {$account['user']->name} dibuat")
            ->body(new HtmlString(collect([
                'Email: '.e($account['email']),
                'Kata sandi: '.e($account['password']),
                $status,
            ])->implode('<br>')))
            ->success()
            ->persistent();

        if (! ($whatsapp['api_sent'] ?? false) && filled($whatsapp['wa_url'] ?? null)) {
            $notification->actions([
                Action::make('sendWhatsApp')
                    ->label('Kirim via WhatsApp')
                    ->url($whatsapp['wa_url'], shouldOpenInNewTab: true)
                    ->button(),
            ]);
        }

        $notification->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
