<?php

namespace App\Filament\Auth\Pages;

use App\Models\EmailOtp;
use App\Models\User;
use App\Services\EmailOtpService;
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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * Passwordless login with a code or magic link sent by email, the same flow as gais-web, dnd-web and
 * helpdesk-web. The page never reveals whether an email has an account.
 *
 * @property-read Schema $form
 */
class EmailLogin extends SimplePage
{
    use RestrictsFileUploadsToSchemaComponents;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    #[Locked]
    public bool $awaitingOtp = false;

    /**
     * Magic link token from the URL. It is redeemed through wire:init, a Livewire request after the page
     * shows, not on GET, so link scanners in mail clients that only open the URL cannot use it up.
     */
    #[Locked]
    public ?string $magicToken = null;

    public function boot(): void
    {
        if (Filament::getCurrentPanel() === null) {
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            Filament::bootCurrentPanel();
        }
    }

    public function mount(?string $token = null): void
    {
        if (Filament::auth()->check()) {
            $this->redirectIntended(Filament::getUrl());

            return;
        }

        $this->maxWidth = 'full';
        $this->magicToken = filled($token) ? $token : null;
        $this->form->fill();
    }

    public function isMailerConfigured(): bool
    {
        return EmailOtpService::isConfigured();
    }

    public function consumeMagicLink(EmailOtpService $otpService): ?LoginResponse
    {
        $token = (string) $this->magicToken;
        $this->magicToken = null;

        if ($token === '') {
            return null;
        }

        $this->ensureMailerConfigured();
        $this->throttle([
            ['ontime:email-otp:link:ip:'.$this->ipHash(), 20, 60],
        ], 'data.email', 'Terlalu banyak percobaan masuk. Coba lagi sebentar lagi.');

        $user = $otpService->consumeMagicLink($token, EmailOtp::PURPOSE_LOGIN);
        if (! $user || ! $user->canAccessPanel(Filament::getCurrentPanel())) {
            throw ValidationException::withMessages([
                'data.email' => 'Link masuk tidak valid atau sudah kedaluwarsa. Minta kode baru di bawah.',
            ]);
        }

        return $this->login($user);
    }

    public function send(EmailOtpService $otpService): void
    {
        $this->ensureMailerConfigured();
        $data = $this->form->getState();
        $email = EmailOtpService::normalizeEmail($data['email'] ?? null);

        if ($email === null) {
            throw ValidationException::withMessages(['data.email' => 'Alamat email tidak valid.']);
        }

        $emailHash = hash('sha256', $email);
        $this->throttle([
            ['ontime:email-otp:send:email-5m:'.$emailHash, 3, 300],
            ['ontime:email-otp:send:email-1h:'.$emailHash, 10, 3600],
            ['ontime:email-otp:send:ip:'.$this->ipHash(), 20, 60],
        ], 'data.email', 'Terlalu banyak permintaan kode. Silakan coba lagi nanti.');

        // Only the single active account that owns this address and may open the panel gets an email.
        // Every other address still moves on to the code step, so nothing is revealed.
        $user = $otpService->findUser($email);
        if ($user && $user->canAccessPanel(Filament::getCurrentPanel())) {
            try {
                $otpService->issue($user, $email, EmailOtp::PURPOSE_LOGIN);
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    'data.email' => 'Email belum bisa dikirim. Coba lagi sebentar lagi.',
                ]);
            }
        }

        $this->awaitingOtp = true;
        $this->form->fill(['email' => $email, 'otp' => null]);
    }

    public function verify(EmailOtpService $otpService): ?LoginResponse
    {
        $this->ensureMailerConfigured();
        $data = $this->form->getState();
        $email = EmailOtpService::normalizeEmail($data['email'] ?? null);

        if ($email === null) {
            throw ValidationException::withMessages(['data.email' => 'Alamat email tidak valid.']);
        }

        $this->throttle([
            ['ontime:email-otp:verify:email:'.hash('sha256', $email), 15, 300],
            ['ontime:email-otp:verify:ip:'.$this->ipHash(), 20, 60],
        ], 'data.otp', 'Terlalu banyak percobaan kode. Silakan coba lagi nanti.');

        $user = $otpService->verify($email, EmailOtp::PURPOSE_LOGIN, (string) ($data['otp'] ?? ''));
        if (! $user || ! $user->canAccessPanel(Filament::getCurrentPanel())) {
            throw ValidationException::withMessages([
                'data.otp' => 'Kode tidak valid atau sudah kedaluwarsa.',
            ]);
        }

        return $this->login($user);
    }

    public function changeEmail(): void
    {
        $this->awaitingOtp = false;
        $this->resetValidation();
        $this->form->fill();
    }

    private function login(User $user): LoginResponse
    {
        Filament::auth()->login($user, false);
        session()->regenerate();

        return app(LoginResponse::class);
    }

    /**
     * Every limit is checked before any is counted, so a refused request does not use up the others.
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $limits  [key, max attempts, window in seconds]
     */
    private function throttle(array $limits, string $field, string $message): void
    {
        foreach ($limits as [$key, $maxAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw ValidationException::withMessages([$field => $message]);
            }
        }

        foreach ($limits as [$key, , $decaySeconds]) {
            RateLimiter::hit($key, $decaySeconds);
        }
    }

    private function ipHash(): string
    {
        return hash('sha256', (string) request()->ip());
    }

    private function ensureMailerConfigured(): void
    {
        if (! $this->isMailerConfigured()) {
            throw ValidationException::withMessages([
                'data.email' => 'Pengiriman email belum dikonfigurasi. Silakan masuk menggunakan email dan password.',
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
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(255)
                ->autocomplete('email')
                ->disabled(fn (): bool => $this->awaitingOtp || ! $this->isMailerConfigured())
                ->dehydrated()
                ->autofocus(fn (): bool => ! $this->awaitingOtp),
            TextInput::make('otp')
                ->label('Kode OTP')
                ->helperText('Jika email terdaftar, kode 6 digit dan tombol masuk sudah dikirim ke email tersebut. Pakai salah satunya.')
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
        return 'Masuk dengan Email';
    }

    public function getHeading(): string|Htmlable|null
    {
        return $this->getTitle();
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! $this->isMailerConfigured()) {
            return 'Pengiriman email belum dikonfigurasi. Silakan masuk menggunakan email dan password.';
        }

        if (filled($this->magicToken)) {
            return 'Memproses link masuk…';
        }

        if ($this->awaitingOtp) {
            return Action::make('changeEmail')
                ->link()
                ->label('Ganti email atau kirim ulang kode')
                ->action('changeEmail');
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
                    Action::make('send')->label('Kirim Kode ke Email')->submit('send')
                        ->disabled(fn (): bool => ! $this->isMailerConfigured())
                        ->visible(fn (): bool => ! $this->awaitingOtp),
                    Action::make('verify')->label('Verifikasi dan Masuk')->submit('verify')
                        ->visible(fn (): bool => $this->awaitingOtp),
                ])->fullWidth()->key('form-actions'),
            ]);
    }

    public function getView(): string
    {
        return 'filament.auth.email-login';
    }

    public function hasLogo(): bool
    {
        return false;
    }
}
