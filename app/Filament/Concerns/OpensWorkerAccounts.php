<?php

namespace App\Filament\Concerns;

use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Services\WorkerAccountService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * One-step account opening for workers: the email comes from their data, the password is random, and
 * WhatsApp sends both with the app store links. Only employees are asked for a role, since an employee
 * can also be an approver; interns and freelancers always get their own role.
 */
trait OpensWorkerAccounts
{
    public static function createAccountAction(): Action
    {
        $asksForRole = static::getModel() === Employee::class;

        return Action::make('createUser')
            ->label('Buat Akun')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('success')
            ->visible(fn (Employee|Intern|Freelancer $record): bool => ! $record->user_id)
            ->modalHeading(fn (Employee|Intern|Freelancer $record): string => "Buat akun {$record->full_name}?")
            ->modalDescription(fn (Employee|Intern|Freelancer $record): string => static::accountSummary($record))
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel('Buat akun')
            ->modalCancelActionLabel('Batal')
            ->modalIcon(Heroicon::OutlinedUserPlus)
            ->modalIconColor('success')
            ->schema($asksForRole ? [
                Select::make('role')
                    ->label('Role')
                    ->options(fn (): array => static::assignableRoleOptions())
                    ->default('Employee')
                    ->required(),
            ] : [])
            ->requiresConfirmation(! $asksForRole)
            ->action(function (Employee|Intern|Freelancer $record, array $data): void {
                $role = $data['role'] ?? null;
                abort_if($role !== null && ! array_key_exists($role, static::assignableRoleOptions()), 403);

                static::openAccount($record, $role);
            });
    }

    /**
     * Create the account and show the login details once, with a WhatsApp link when the gateway did not send them.
     */
    public static function openAccount(Employee|Intern|Freelancer $worker, ?string $role = null): void
    {
        try {
            $account = app(WorkerAccountService::class)->createAccount($worker, role: $role);
        } catch (InvalidArgumentException $exception) {
            Notification::make()->title('Akun belum dibuat')->body($exception->getMessage())->danger()->send();

            return;
        }

        $whatsapp = $account['whatsapp'];
        $status = match (true) {
            $whatsapp['api_sent'] => 'Data masuk dan link aplikasi sudah dikirim ke WhatsApp.',
            filled($whatsapp['wa_url']) => 'WhatsApp belum terkirim otomatis. Kirim lewat tombol di bawah.',
            default => 'Nomor HP kosong. Berikan data ini beserta link aplikasi: '.e((string) config('services.mobile_app.play_store_url')),
        };

        $notification = Notification::make()
            ->title("Akun {$worker->full_name} dibuat")
            ->body(new HtmlString(collect([
                'Email: '.e($account['email']),
                'Kata sandi: '.e($account['password']),
                $status,
            ])->implode('<br>')))
            ->success()
            ->persistent();

        if (! $whatsapp['api_sent'] && filled($whatsapp['wa_url'])) {
            $notification->actions([
                Action::make('sendWhatsApp')
                    ->label('Kirim via WhatsApp')
                    ->url($whatsapp['wa_url'], shouldOpenInNewTab: true)
                    ->button(),
            ]);
        }

        $notification->send();
    }

    private static function accountSummary(Employee|Intern|Freelancer $worker): string
    {
        $email = app(WorkerAccountService::class)->loginEmailFor($worker);

        return filled($worker->phone)
            ? "Email masuk: {$email}. Kata sandi dibuat otomatis lalu dikirim ke WhatsApp {$worker->phone} bersama link unduh aplikasi."
            : "Email masuk: {$email}. Kata sandi dibuat otomatis. Nomor HP kosong, jadi data masuk ditampilkan untuk Anda berikan sendiri.";
    }
}
