<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="LipaHuru payment gateway API — collections, disbursements, and wallet operations across Tanzania mobile money networks.">
    <title>{{ config('app.name', 'LipaHuru') }} Gateway</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-icon.png">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700|jetbrains-mono:400,500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b1220;
            --card: #111827;
            --border: #1e293b;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --amber: #fbbf24;
            --orange: #f97316;
            --teal: #14b8a6;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif;
            color: var(--text);
            background:
                radial-gradient(ellipse 80% 55% at 85% 0%, rgba(249, 115, 22, 0.18), transparent 55%),
                radial-gradient(ellipse 70% 50% at 0% 100%, rgba(20, 184, 166, 0.14), transparent 50%),
                radial-gradient(ellipse 50% 40% at 50% 45%, rgba(251, 191, 36, 0.06), transparent 60%),
                var(--bg);
        }

        .shell {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1.25rem;
        }

        .card {
            width: min(100%, 40rem);
            border: 1px solid var(--border);
            border-radius: 1.5rem;
            background: rgba(17, 24, 39, 0.88);
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.35);
            backdrop-filter: blur(12px);
            padding: 2rem 1.75rem;
        }

        @media (min-width: 640px) {
            .card { padding: 2.5rem; }
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            margin-bottom: 1.75rem;
        }

        .brand strong {
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .brand span {
            display: block;
            margin-top: 0.15rem;
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--muted);
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.25rem;
            padding: 0.4rem 0.75rem;
            border-radius: 999px;
            border: 1px solid rgba(20, 184, 166, 0.35);
            background: rgba(20, 184, 166, 0.1);
            color: #5eead4;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status i {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 999px;
            background: var(--teal);
            box-shadow: 0 0 0 4px rgba(20, 184, 166, 0.2);
        }

        h1 {
            margin: 0 0 0.75rem;
            font-size: clamp(1.75rem, 4vw, 2.25rem);
            line-height: 1.15;
            letter-spacing: -0.03em;
            font-weight: 700;
        }

        .lead {
            margin: 0 0 1.75rem;
            color: var(--muted);
            font-size: 1rem;
            line-height: 1.6;
        }

        .grid {
            display: grid;
            gap: 0.75rem;
            margin-bottom: 1.75rem;
        }

        @media (min-width: 560px) {
            .grid { grid-template-columns: 1fr 1fr; }
        }

        .tile {
            border: 1px solid var(--border);
            border-radius: 1rem;
            background: rgba(11, 18, 32, 0.7);
            padding: 1rem 1.1rem;
        }

        .tile .label {
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .tile .value {
            margin-top: 0.4rem;
            font-family: "JetBrains Mono", ui-monospace, monospace;
            font-size: 0.9rem;
            color: #f8fafc;
            word-break: break-all;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            min-height: 2.75rem;
            padding: 0.65rem 1.1rem;
            border-radius: 0.85rem;
            border: 1px solid transparent;
            font: inherit;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            transition: transform 0.15s ease, background 0.15s ease, border-color 0.15s ease;
        }

        .btn:hover { transform: translateY(-1px); }

        .btn-primary {
            color: #1a0a00;
            background: linear-gradient(135deg, var(--amber), var(--orange));
            box-shadow: 0 8px 28px rgba(249, 115, 22, 0.28);
        }

        .btn-secondary {
            color: var(--text);
            background: transparent;
            border-color: var(--border);
        }

        .btn-secondary:hover {
            border-color: rgba(148, 163, 184, 0.45);
            background: rgba(255, 255, 255, 0.03);
        }

        .foot {
            margin-top: 1.75rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--border);
            color: var(--muted);
            font-size: 0.8rem;
            line-height: 1.5;
        }

        .foot code {
            font-family: "JetBrains Mono", ui-monospace, monospace;
            font-size: 0.78rem;
            color: #cbd5e1;
        }
    </style>
</head>
<body>
    @php
        $appName = config('app.name', 'LipaHuru');
        $portalUrl = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', '')), '/');
        $docsUrl = rtrim((string) env('DOCS_URL', 'https://lipahuru.co.tz/docs'), '/');
        $apiBase = rtrim(url('/api'), '/');
    @endphp

    <div class="shell">
        <main class="card">
            <div class="brand">
                <svg width="40" height="40" viewBox="0 0 48 48" aria-hidden="true">
                    <defs>
                        <linearGradient id="lhWelcome" gradientUnits="userSpaceOnUse" x1="4" y1="4" x2="44" y2="44">
                            <stop offset="0" stop-color="#fbbf24"/>
                            <stop offset="1" stop-color="#f97316"/>
                        </linearGradient>
                    </defs>
                    <rect x="17.5" y="3" width="13" height="13" rx="3.6" fill="url(#lhWelcome)"/>
                    <rect x="3" y="17.5" width="13" height="13" rx="3.6" fill="#fbbf24" opacity=".42"/>
                    <rect x="32" y="17.5" width="13" height="13" rx="3.6" fill="#f97316" opacity=".62"/>
                    <rect x="17.5" y="32" width="13" height="13" rx="3.6" fill="#fbbf24" opacity=".28"/>
                </svg>
                <div>
                    <strong>{{ $appName }}</strong>
                    <span>Payment gateway</span>
                </div>
            </div>

            <div class="status"><i aria-hidden="true"></i> API online</div>

            <h1>Move money. Monitor everything.</h1>
            <p class="lead">
                Collections, disbursements, refunds and wallet balances across Tanzania’s mobile money networks — one REST API, one set of credentials.
            </p>

            <div class="grid">
                <div class="tile">
                    <div class="label">API base</div>
                    <div class="value">{{ $apiBase }}</div>
                </div>
                <div class="tile">
                    <div class="label">Health check</div>
                    <div class="value">{{ url('/up') }}</div>
                </div>
            </div>

            <div class="actions">
                @if ($portalUrl !== '')
                    <a class="btn btn-primary" href="{{ $portalUrl }}">Open portal</a>
                @endif
                <a class="btn btn-secondary" href="{{ $docsUrl }}" target="_blank" rel="noopener noreferrer">API docs</a>
                <a class="btn btn-secondary" href="{{ url('/up') }}">Health</a>
            </div>

            <p class="foot">
                Authenticate merchants via <code>POST /oauth/token</code>. Portal and admin traffic use <code>/api/v1</code> and <code>/api/admin/v1</code>.
            </p>
        </main>
    </div>
</body>
</html>
