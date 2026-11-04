@php
    use App\Services\EmailOtpService;
    use App\Services\WhatsAppOtpService;
    use Filament\Support\Icons\Heroicon;
    use Illuminate\View\ComponentAttributeBag;

    use function Filament\Support\generate_icon_html;

    $hasWhatsAppLogin = WhatsAppOtpService::isConfigured();
    $hasEmailLogin = EmailOtpService::isConfigured();
    $hasSsoLogin = filled(config('services.core.client_id')) && filled(config('services.core.client_secret')) && filled(config('services.core.redirect'));
@endphp

@if ($hasWhatsAppLogin || $hasEmailLogin || $hasSsoLogin)
    <div class="space-y-6">
        <div class="relative flex items-center justify-center">
            <div class="flex-grow border-t border-gray-200 dark:border-gray-700/80"></div>
            <span class="flex-shrink mx-4 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                Atau masuk dengan
            </span>
            <div class="flex-grow border-t border-gray-200 dark:border-gray-700/80"></div>
        </div>

        {{-- Two equal columns so both buttons stay inside the card on narrow phones. --}}
        <div @class(['grid gap-3', 'grid-cols-2' => $hasWhatsAppLogin && $hasEmailLogin])>
            @if ($hasWhatsAppLogin)
                <x-filament::button
                    tag="a"
                    href="{{ route('phone-login') }}"
                    color="gray"
                    :outlined="true"
                    class="w-full min-w-0"
                >
                    <span class="flex min-w-0 items-center justify-center gap-2">
                        <svg class="whatsapp-login-icon size-5 shrink-0" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M13.601 2.326A7.85 7.85 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.043 3.963L0 16l4.204-1.102a7.9 7.9 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.9 7.9 0 0 0 13.6 2.326zM7.994 14.521a6.6 6.6 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.56 6.56 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592m3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.065-.315-.099-.445.099-.133.197-.513.646-.627.78-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.99-1.174-1.105-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.73.73 0 0 0-.529.247c-.182.198-.691.677-.691 1.654s.71 1.916.81 2.049c.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.906.13 1.25.08.381-.058 1.171-.48 1.338-.941.164-.464.164-.86.114-.941-.049-.084-.182-.133-.38-.232" />
                        </svg>
                        <span class="truncate font-semibold text-gray-900 dark:text-white">WhatsApp</span>
                    </span>
                </x-filament::button>
            @endif

            @if ($hasEmailLogin)
                <x-filament::button
                    tag="a"
                    href="{{ route('email-login') }}"
                    color="gray"
                    :outlined="true"
                    class="w-full min-w-0"
                >
                    <span class="flex min-w-0 items-center justify-center gap-2">
                        {{
                            generate_icon_html(
                                Heroicon::Envelope,
                                attributes: (new ComponentAttributeBag)->class(['email-login-icon size-5 shrink-0']),
                            )
                        }}
                        <span class="truncate font-semibold text-gray-900 dark:text-white">Email</span>
                    </span>
                </x-filament::button>
            @endif
        </div>

        @if ($hasSsoLogin)
            <x-filament::button
                tag="a"
                href="{{ route('sso.redirect') }}"
                color="gray"
                :outlined="true"
                class="w-full"
            >
                <span class="flex min-w-0 items-center justify-center gap-2">
                    {{
                        generate_icon_html(
                            Heroicon::BuildingOffice2,
                            attributes: (new ComponentAttributeBag)->class(['size-5 shrink-0']),
                        )
                    }}
                    <span class="truncate font-semibold text-gray-900 dark:text-white">Akun Core (SSO)</span>
                </span>
            </x-filament::button>
        @endif
    </div>
@endif
