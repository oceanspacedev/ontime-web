<x-mail::message>
<x-mail::card-header :icon="asset('images/mail/envelope.png')" :title="'Masuk ke '.config('app.name')">
@if ($otp !== null)
Halo {{ $name }}, masukkan kode ini di halaman masuk atau klik tombol di bawah.
@else
Halo {{ $name }}, ketuk tombol di bawah dari HP tempat Anda meminta link ini untuk masuk ke aplikasi.
@endif
</x-mail::card-header>

@if ($otp !== null)
<x-mail::code label="Kode OTP">
{{ $otp }}
</x-mail::code>
@endif

<x-mail::button :url="$loginUrl">
Masuk ke {{ config('app.name') }}
</x-mail::button>

<x-slot:subcopy>
{{ $otp !== null ? 'Link dan kode' : 'Link ini' }} berlaku {{ $expiresInMinutes }} menit dan hanya bisa dipakai sekali. Jangan bagikan kepada siapa pun. Jika Anda tidak meminta masuk, abaikan email ini.
</x-slot:subcopy>
</x-mail::message>
