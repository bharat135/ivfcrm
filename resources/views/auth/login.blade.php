<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in — {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600&display=swap">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    @php
        // Cache-bust on file mtime, but never let a missing asset 500 the app —
        // an unstyled page is recoverable, a white error screen is not.
        $fuiVersion = @filemtime(public_path('css/frappe-ui.css')) ?: '1';
    @endphp
    <link rel="stylesheet" href="{{ asset('css/frappe-ui.css') }}?v={{ $fuiVersion }}">

    <script>
        try {
            if (localStorage.getItem('theme') === 'dark') {
                document.documentElement.setAttribute('data-bs-theme', 'dark');
            }
        } catch (e) {}
    </script>

    <style>
        /* A quiet, centred sign-in — Frappe UI keeps auth screens plain and
           lets the product do the talking after login. */
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--fui-surface-subtle);
            padding: 24px;
        }

        /* Subtle dot grid so the card has something to sit on without a gradient. */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: radial-gradient(var(--fui-gray-300) 1px, transparent 1px);
            background-size: 22px 22px;
            opacity: .5;
            pointer-events: none;
        }

        .auth-shell { width: 100%; max-width: 364px; position: relative; }

        .auth-card {
            background: var(--fui-surface);
            border: 1px solid var(--fui-border-subtle);
            border-radius: var(--fui-radius-2xl);
            box-shadow: var(--fui-shadow-lg);
            padding: 26px;
        }

        .brand-mark {
            width: 38px; height: 38px;
            border-radius: var(--fui-radius-lg);
            background: var(--fui-gray-950);
            color: var(--fui-ink-invert);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-bottom: 14px;
        }

        .auth-title { font-size: 19px; font-weight: 600; letter-spacing: -0.018em; margin-bottom: 2px; }
        .auth-sub { font-size: var(--fui-text-base); color: var(--fui-ink-subtle); margin-bottom: 20px; }

        .auth-footer {
            text-align: center;
            margin-top: 14px;
            font-size: var(--fui-text-sm);
            color: var(--fui-ink-faint);
        }

        .demo-hint {
            margin-top: 14px;
            padding: 9px 11px;
            border: 1px dashed var(--fui-border);
            border-radius: var(--fui-radius);
            font-size: var(--fui-text-sm);
            color: var(--fui-ink-subtle);
            background: var(--fui-surface-subtle);
        }
        .demo-hint code { background: transparent; padding: 0; color: var(--fui-ink); font-weight: 500; }
    </style>
</head>
<body>

<div class="auth-shell">
    <div class="auth-card">
        <div class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></div>

        <h1 class="auth-title">Sign in to IVF CRM</h1>
        <p class="auth-sub">Fertility clinic management</p>

        @if(session('error'))
            <div class="alert alert-danger mb-3">
                <i class="bi bi-exclamation-circle-fill"></i>
                <div>{{ session('error') }}</div>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger mb-3">
                <i class="bi bi-exclamation-circle-fill"></i>
                <div>
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('login.submit') }}">
            @csrf

            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <input type="email" name="email" id="email"
                       class="form-control @error('email') is-invalid @enderror"
                       value="{{ old('email') }}" placeholder="you@clinic.com" required autofocus autocomplete="username">
            </div>

            <div class="mb-3">
                <label class="form-label d-flex align-items-center" for="password">
                    Password
                    <button type="button" class="btn btn-link btn-sm ms-auto px-1 py-0" id="togglePwd"
                            style="height:auto;font-size:var(--fui-text-sm)">Show</button>
                </label>
                <input type="password" name="password" id="password"
                       class="form-control @error('password') is-invalid @enderror"
                       placeholder="••••••••" required autocomplete="current-password">
            </div>

            <div class="form-check mb-3">
                <input type="checkbox" class="form-check-input" name="remember" id="remember" value="1">
                <label class="form-check-label" for="remember">Keep me signed in</label>
            </div>

            <button type="submit" class="btn btn-primary w-100">
                Sign in
                <i class="bi bi-arrow-right"></i>
            </button>
        </form>

        @if(config('app.env') === 'local')
            <div class="demo-hint">
                <i class="bi bi-info-circle me-1"></i>
                Demo login — <code>admin@ivfcrm.com</code> / <code>Admin@123</code>
            </div>
        @endif
    </div>

    <div class="auth-footer">
        Sessions are limited to one device at a time.
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('togglePwd').addEventListener('click', function () {
    const field = document.getElementById('password');
    const showing = field.type === 'text';
    field.type = showing ? 'password' : 'text';
    this.textContent = showing ? 'Show' : 'Hide';
    field.focus();
});
</script>
</body>
</html>
