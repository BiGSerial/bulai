<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar - BULA-IA</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f4f7f9; color: #172026; }
        main { width: min(420px, calc(100% - 32px)); }
        form { display: grid; gap: 16px; padding: 28px; background: #ffffff; border: 1px solid #d8e0e6; border-radius: 8px; box-shadow: 0 12px 32px rgb(23 32 38 / 8%); }
        h1 { margin: 0 0 4px; font-size: 24px; line-height: 1.2; }
        label { display: grid; gap: 6px; font-size: 14px; font-weight: 600; }
        input[type="email"], input[type="password"] { box-sizing: border-box; width: 100%; border: 1px solid #bac7d1; border-radius: 6px; padding: 11px 12px; font: inherit; }
        .check { display: flex; align-items: center; gap: 8px; font-weight: 500; }
        button { border: 0; border-radius: 6px; padding: 12px 14px; background: #0f766e; color: #ffffff; font: inherit; font-weight: 700; cursor: pointer; }
        .error { margin: 0; color: #b42318; font-size: 14px; }
    </style>
</head>
<body>
    <main>
        <form method="POST" action="{{ route('login.store') }}">
            @csrf

            <h1>BULA-IA Admin</h1>

            @error('email')
                <p class="error">{{ $message }}</p>
            @enderror

            <label>
                E-mail
                <input name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
            </label>

            <label>
                Senha
                <input name="password" type="password" autocomplete="current-password" required>
            </label>

            <label class="check">
                <input name="remember" type="checkbox" value="1">
                Manter conectado
            </label>

            <button type="submit">Entrar</button>
        </form>
    </main>
</body>
</html>
