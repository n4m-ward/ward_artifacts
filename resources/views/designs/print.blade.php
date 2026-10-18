{{-- Vários artboards num PDF: uma página por artboard, do tamanho dele. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $artifact->title }}</title>
    <style>
        html, body { margin: 0; background: #e4e4e7; font: 14px system-ui, sans-serif; }
        .ward-print-note { padding: 12px 16px; color: #3f3f46; }
        .ward-print-page { display: block; margin: 0 auto 24px; background: #fff; overflow: hidden; }
        .ward-print-page iframe { display: block; border: 0; }
        @foreach ($boards as $board)
            @page b{{ $board['id'] }} { size: {{ $board['w'] }}px {{ $board['h'] }}px; margin: 0; }
            .page-b{{ $board['id'] }} { page: b{{ $board['id'] }}; width: {{ $board['w'] }}px; height: {{ $board['h'] }}px; }
        @endforeach
        @media print {
            html, body { background: none; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .ward-print-note { display: none; }
            .ward-print-page { margin: 0; break-after: page; }
            .ward-print-page:last-child { break-after: auto; }
        }
    </style>
</head>
<body>
    <p class="ward-print-note" role="status">Preparando {{ $boards->count() }} página(s) para o PDF… Na janela de impressão, escolha "Salvar como PDF".</p>
    @foreach ($boards as $board)
        <div class="ward-print-page page-b{{ $board['id'] }}">
            <iframe src="{{ $board['url'] }}" title="{{ $board['title'] }}" width="{{ $board['w'] }}" height="{{ $board['h'] }}"
                sandbox="{{ $sandbox }}" allow="{{ $allow }}" referrerpolicy="no-referrer"></iframe>
        </div>
    @endforeach
    <script>
        (() => {
            const frames = [...document.querySelectorAll('iframe')];
            let pending = frames.length;
            const ready = () => setTimeout(() => window.print(), 1500);
            frames.forEach((frame) => frame.addEventListener('load', () => { if (--pending === 0) ready(); }, { once: true }));
            setTimeout(() => { if (pending > 0) { pending = 0; ready(); } }, 15000);
        })();
    </script>
</body>
</html>
