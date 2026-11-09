{{--
    OneSignal web push for the panel (Web SDK v16). The signed-in user is linked by their OnTime user id,
    the same external id the mobile app uses, so one server push reaches the phone and the browser.
    On guest pages (login) the browser is unlinked, so the next person does not get the previous user's pushes.
    Permission is only asked from the banner button, never on page load.
--}}
@php
    $oneSignalAppId = config('services.onesignal.web_app_id');
    $oneSignalUserId = auth()->id();
@endphp

@if (filled($oneSignalAppId))
    <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
    <script>
        window.OneSignalDeferred = window.OneSignalDeferred || [];
        OneSignalDeferred.push(async function (OneSignal) {
            try {
                await OneSignal.init({
                    appId: @js($oneSignalAppId),
                    allowLocalhostAsSecureOrigin: @js(app()->isLocal()),
                });
            } catch (error) {
                // Usually the Web platform is not set up for this site URL in OneSignal, or the page is not HTTPS.
                console.warn('OneSignal web push tidak aktif:', error?.message ?? error);
                return;
            }

            @if ($oneSignalUserId)
                await OneSignal.login(@js((string) $oneSignalUserId));
                window.dispatchEvent(new CustomEvent('ontime-push-ready', { detail: { supported: OneSignal.Notifications.isPushSupported(), permission: OneSignal.Notifications.permission } }));
            @else
                if (OneSignal.User.externalId) {
                    await OneSignal.logout();
                }
            @endif
        });
    </script>

    @if ($oneSignalUserId)
        {{-- One banner until the user enables or dismisses it; the choice is kept in this browser. --}}
        <div
            x-data="{
                show: false,
                init() {
                    window.addEventListener('ontime-push-ready', (event) => {
                        let dismissed = false;
                        try { dismissed = localStorage.getItem('ontime.pushBannerDismissed') === '1'; } catch (e) {}
                        this.show = event.detail.supported && ! event.detail.permission && ! dismissed;
                    });
                },
                enable() {
                    this.dismiss();
                    window.OneSignalDeferred.push((OneSignal) => OneSignal.Notifications.requestPermission());
                },
                dismiss() {
                    this.show = false;
                    try { localStorage.setItem('ontime.pushBannerDismissed', '1'); } catch (e) {}
                },
            }"
            x-show="show"
            x-cloak
            class="fixed bottom-4 right-4 z-50 w-80 rounded-xl bg-white p-4 shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
        >
            <p class="text-sm font-semibold text-gray-950 dark:text-white">Aktifkan notifikasi</p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Dapatkan pengingat shift dan kabar pengajuan di browser ini.</p>
            <div class="mt-3 flex justify-end gap-2">
                <x-filament::button color="gray" size="sm" x-on:click="dismiss()">Nanti</x-filament::button>
                <x-filament::button size="sm" x-on:click="enable()">Aktifkan</x-filament::button>
            </div>
        </div>
    @endif
@endif
