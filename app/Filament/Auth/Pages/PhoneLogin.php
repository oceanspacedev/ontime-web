<?php

namespace App\Filament\Auth\Pages;

use App\Models\User;
use App\Services\WhatsAppOtpService;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Login with a WhatsApp one-time code, the same flow as gais-web, dnd-web and helpdesk-web. The code
 * goes to any valid number, so the page never reveals whether a number has an account.
 *
 * @property-read Schema $form
 */
class PhoneLogin extends SimplePage
{
    use RestrictsFileUploadsToSchemaComponents;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    #[Locked]
    public bool $awaitingOtp = false;

    #[Locked]
    public ?string $otpChallengeId = null;

    public function boot(): void
    {
        if (Filament::getCurrentPanel() === null) {
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            Filament::bootCurrentPanel();
        }
    }

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            $this->redirectIntended(Filament::getUrl());

            return;
        }

        $this->maxWidth = 'full';
        $this->form->fill();
    }

    public function isGatewayConfigured(): bool
    {
        return WhatsAppOtpService::isConfigured();
    }

    public function send(WhatsAppOtpService $otpService): void
    {
        $this->ensureGatewayConfigured();
        $data = $this->form->getState();
        $phone = WhatsAppOtpService::canonicalPhone($data['phone'] ?? null);

        if ($phone === null) {
            throw ValidationException::withMessages(['data.phone' => 'Nomor HP tidak valid.']);
        }

        $issued = $otpService->issue(
            'login',
            'phone-login',
            $this->otpPrincipal(),
            $phone,
            rotate: true,
            tenant: 'phone-login-ip:'.hash('sha256', (string) request()->ip()),
        );

        if (! ($issued['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'data.phone' => match ($issued['status'] ?? '') {
                    'rate_limited' => 'Terlalu banyak permintaan OTP. Silakan coba lagi nanti.',
                    'busy' => 'Pembuatan OTP sedang sibuk. Coba lagi sebentar lagi.',
                    default => 'OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi.',
                },
            ]);
        }

        $this->otpChallengeId = $issued['challenge_id'];
        $this->awaitingOtp = true;
        $this->form->fill(['phone' => $phone, 'otp' => null]);
    }

    public function verify(WhatsAppOtpService $otpService): ?LoginResponse
    {
        $this->ensureGatewayConfigured();
        $data = $this->form->getState();
        $verified = $otpService->verify(
            'login',
            'phone-login',
            $this->otpPrincipal(),
            (string) $this->otpChallengeId,
            (string) ($data['otp'] ?? ''),
        );

        if (! ($verified['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'data.otp' => match ($verified['status'] ?? '') {
                    'rate_limited' => 'Terlalu banyak percobaan kode yang salah. Mulai ulang proses login.',
                    'busy' => 'Verifikasi OTP sedang diproses. Coba lagi sebentar lagi.',
                    default => 'Kode OTP tidak valid atau sudah kedaluwarsa.',
                },
            ]);
        }

        $phone = WhatsAppOtpService::canonicalPhone($verified['phone'] ?? null);
        $this->otpChallengeId = null;
        $this->awaitingOtp = false;
        $this->form->fill(['phone' => $phone, 'otp' => null]);

        if ($phone === null || $phone !== WhatsAppOtpService::canonicalPhone($data['phone'] ?? null)) {
            throw ValidationException::withMessages([
                'data.phone' => 'Identitas nomor OTP tidak cocok. Mulai ulang proses login.',
            ]);
        }

        // Only numbers recorded by HR or an admin (on the account or its worker profile) can log in.
        $user = User::findByPhone($phone);
        if (! $user || ! $user->canAccessPanel(Filament::getCurrentPanel())) {
            throw ValidationException::withMessages([
                'data.phone' => 'Nomor belum terdaftar untuk login atau akun tidak memiliki akses. Hubungi administrator.',
            ]);
        }

        Filament::auth()->login($user, false);
        session()->regenerate();

        return app(LoginResponse::class);
    }

    public function changePhone(WhatsAppOtpService $otpService): void
    {
        if (! $otpService->revoke('login', 'phone-login', $this->otpPrincipal())) {
            throw ValidationException::withMessages([
                'data.otp' => 'Verifikasi OTP sedang diproses. Coba lagi sebentar lagi.',
            ]);
        }

        $this->otpChallengeId = null;
        $this->awaitingOtp = false;
        $this->resetValidation();
        $this->form->fill();
    }

    private function otpPrincipal(): string
    {
        return 'phone-login-session:'.hash('sha256', Session::getId());
    }

    private function ensureGatewayConfigured(): void
    {
        if (! $this->isGatewayConfigured()) {
            throw ValidationException::withMessages([
                'data.phone' => 'WhatsApp Gateway belum dikonfigurasi. Silakan masuk menggunakan email dan password.',
            ]);
        }
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('phone')
                ->label('Nomor HP')
                ->tel()
                ->required()
                ->maxLength(30)
                ->autocomplete('tel')
                ->disabled(fn (): bool => $this->awaitingOtp || ! $this->isGatewayConfigured())
                ->dehydrated()
                ->autofocus(fn (): bool => ! $this->awaitingOtp),
            TextInput::make('otp')
                ->label('Kode OTP')
                ->helperText('Masukkan 6 digit kode yang dikirim ke WhatsApp.')
                ->required()
                ->length(6)
                ->rule('regex:/^[0-9]{6}$/D')
                ->inputMode('numeric')
                ->autocomplete('one-time-code')
                ->autofocus()
                ->visible(fn (): bool => $this->awaitingOtp),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Masuk dengan WhatsApp';
    }

    public function getHeading(): string|Htmlable|null
    {
        return $this->getTitle();
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! $this->isGatewayConfigured()) {
            return 'WhatsApp Gateway belum dikonfigurasi. Silakan masuk menggunakan email dan password.';
        }

        if ($this->awaitingOtp) {
            return Action::make('changePhone')
                ->link()
                ->label('Ganti nomor atau kirim ulang OTP')
                ->action('changePhone');
        }

        return Action::make('login')
            ->link()
            ->label('Kembali ke halaman masuk')
            ->icon(Heroicon::ArrowLeft)
            ->url(Filament::getLoginUrl());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler(fn (): string => $this->awaitingOtp ? 'verify' : 'send')
            ->footer([
                Actions::make([
                    Action::make('send')->label('Kirim OTP WhatsApp')->submit('send')
                        ->disabled(fn (): bool => ! $this->isGatewayConfigured())
                        ->visible(fn (): bool => ! $this->awaitingOtp),
                    Action::make('verify')->label('Verifikasi OTP')->submit('verify')
                        ->visible(fn (): bool => $this->awaitingOtp),
                ])->fullWidth()->key('form-actions'),
            ]);
    }

    public function getView(): string
    {
        return 'filament.auth.phone-login';
    }

    public function hasLogo(): bool
    {
        return false;
    }
}
