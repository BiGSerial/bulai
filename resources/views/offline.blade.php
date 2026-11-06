<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#12344d">
    <title>Sem conexão · BULA-IA</title>
    {{-- Página pré-cacheada pelo service worker: não depende dos assets compilados, por isso o estilo fica aqui. --}}
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f7f4ee; color: #0f172a; display: grid; place-items: center; min-height: 100dvh; margin: 0; padding: calc(1.5rem + env(safe-area-inset-top, 0px)) 1.5rem calc(1.5rem + env(safe-area-inset-bottom, 0px)); text-align: center; font-size: 1.125rem; line-height: 1.6; -webkit-tap-highlight-color: transparent; }
        main { max-width: 26rem; display: grid; gap: .75rem; justify-items: center; }
        img { border-radius: 1rem; width: 4.5rem; height: 4.5rem; }
        h1 { color: #12344d; font-size: 1.75rem; line-height: 1.2; margin: .5rem 0 0; overflow-wrap: anywhere; }
        p { margin: 0; }
        button { margin-top: .5rem; min-height: 3.5rem; width: 100%; padding: .75rem 1.25rem; border: 0; border-radius: .75rem; background: #12344d; color: #fff; font-weight: 600; font-size: 1.125rem; }
        button:active { transform: scale(.98); }
        small { color: #475569; }
    </style>
</head>
<body>
    <main>
        <img src="/brand/bulai-icone-app.svg" alt="" width="72" height="72">
        <h1>Você está sem conexão</h1>
        <p>O BULA-IA precisa de internet para consultar as bulas e os seus remédios. Nenhuma informação fica salva neste aparelho.</p>
        <button type="button" onclick="location.reload()">Tentar novamente</button>
        <small>Confira o Wi-Fi ou os dados móveis e toque no botão.</small>
    </main>
</body>
</html>
