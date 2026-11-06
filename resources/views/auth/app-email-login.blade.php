<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Masuk ke {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f5f5f7; --card: #fff; --text: #111; --muted: #6b7280; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0a0a0a; --card: #1c1c1e; --text: #f5f5f7; --muted: #9ca3af; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 380px; background: var(--card); border-radius: 24px; padding: 32px 24px; text-align: center; }
        h1 { margin: 0 0 8px; font-size: 22px; }
        p { margin: 0 0 24px; color: var(--muted); line-height: 1.5; font-size: 15px; }
        a.button { display: block; padding: 16px; border-radius: 999px; background: #F22929; color: #fff; font-weight: 700; text-decoration: none; font-size: 16px; }
        small { display: block; margin-top: 20px; color: var(--muted); line-height: 1.5; }
    </style>
</head>
<body>
    <main>
        <h1>Masuk ke {{ config('app.name') }}</h1>
        <p>Ketuk tombol di bawah untuk membuka aplikasi dan menyelesaikan proses masuk.</p>
        <a class="button" href="{{ $deepLink }}">Buka aplikasi {{ config('app.name') }}</a>
        <small>Buka link ini di HP tempat Anda meminta link masuk. Link hanya berlaku sekali dan untuk waktu singkat.</small>
    </main>
    <script>window.location.replace(@json($deepLink));</script>
</body>
</html>
