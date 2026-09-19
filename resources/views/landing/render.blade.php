{{--
    Public landing page.

    Deliberately standalone (no CRM layout): it is the destination for paid ads,
    so it loads fast, carries only the tracking the marketer configured, and
    posts straight back into the CRM lead pipeline.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $page->title ?: $page->headline ?: $page->name }}</title>

    @if($page->seo['description'] ?? null)
        <meta name="description" content="{{ $page->seo['description'] }}">
    @endif

    {{-- A preview must never be indexed, and neither should a draft. --}}
    @if($isPreview || ! $page->is_published)
        <meta name="robots" content="noindex, nofollow">
    @endif

    <meta property="og:title" content="{{ $page->headline ?: $page->name }}">
    <meta property="og:description" content="{{ $page->subheadline }}">
    @if($page->hero_image)
        <meta property="og:image" content="{{ Storage::url($page->hero_image) }}">
    @endif

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        :root { --brand: {{ $page->theme_color ?: '#4361ee' }}; }

        body { font-family: 'Segoe UI', system-ui, sans-serif; color: #1f2937; }

        .hero {
            background: linear-gradient(135deg, var(--brand) 0%, color-mix(in srgb, var(--brand) 60%, #000) 100%);
            color: #fff; padding: 64px 0;
        }
        @supports not (color: color-mix(in srgb, red, blue)) {
            .hero { background: var(--brand); }
        }

        .hero h1 { font-weight: 800; font-size: clamp(1.9rem, 4vw, 3rem); line-height: 1.15; }
        .hero p.lead { opacity: .92; }

        .form-card {
            background: #fff; border-radius: 16px; padding: 26px;
            box-shadow: 0 20px 45px rgba(0,0,0,.18); color: #1f2937;
        }

        .btn-brand {
            background: var(--brand); border-color: var(--brand); color: #fff;
            font-weight: 600; padding: 12px; border-radius: 10px;
        }
        .btn-brand:hover { filter: brightness(.92); color: #fff; }

        .stat-value { font-size: 2rem; font-weight: 800; color: var(--brand); line-height: 1; }

        .usp-item { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 14px; }
        .usp-item i { color: var(--brand); font-size: 1.2rem; flex-shrink: 0; margin-top: 2px; }

        .wa-float {
            position: fixed; right: 20px; bottom: 20px; z-index: 999;
            width: 56px; height: 56px; border-radius: 50%;
            background: #25d366; color: #fff; display: flex;
            align-items: center; justify-content: center; font-size: 1.7rem;
            box-shadow: 0 6px 20px rgba(37,211,102,.45); text-decoration: none;
        }
        .wa-float:hover { color: #fff; transform: scale(1.06); transition: transform .15s; }

        .preview-banner {
            background: #fff3cd; color: #664d03; text-align: center;
            padding: 8px; font-size: .85rem; border-bottom: 1px solid #ffe69c;
        }

        footer { background: #111827; color: #9ca3af; padding: 28px 0; font-size: .85rem; }
    </style>

    @if($page->custom_css)
        <style>{!! strip_tags($page->custom_css) !!}</style>
    @endif

    {{-- Meta Pixel --}}
    @if($page->meta_pixel_id && ! $isPreview)
        <script>
            !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
            n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
            document,'script','https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', @json($page->meta_pixel_id));
            fbq('track', 'PageView');
        </script>
    @endif

    {{-- Google Analytics --}}
    @if($page->google_analytics_id && ! $isPreview)
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $page->google_analytics_id }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', @json($page->google_analytics_id));
        </script>
    @endif
</head>
<body>

@if($isPreview)
    <div class="preview-banner">
        <i class="bi bi-eye me-1"></i>Preview mode — this page is
        {{ $page->is_published ? 'published' : 'not published yet' }}. Submissions are live.
    </div>
@endif

{{-- ---------------------------------------------------------------- --}}
{{-- Hero + form                                                       --}}
{{-- ---------------------------------------------------------------- --}}
<section class="hero">
    <div class="container">
        @if($page->logo_image)
            <img src="{{ Storage::url($page->logo_image) }}" alt="Logo" style="max-height:52px" class="mb-4">
        @endif

        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <h1 class="mb-3">{{ $page->headline }}</h1>

                @if($page->subheadline)
                    <p class="lead mb-4">{{ $page->subheadline }}</p>
                @endif

                @php
                    $usps = array_filter(array_map('trim', explode("\n", $page->sections['usps'] ?? '')));
                @endphp

                @if($usps)
                    <div class="mb-4">
                        @foreach($usps as $usp)
                            <div class="usp-item">
                                <i class="bi bi-check-circle-fill text-white"></i>
                                <span>{{ $usp }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($page->hero_image)
                    <img src="{{ Storage::url($page->hero_image) }}" alt="" class="img-fluid rounded-3 shadow-lg d-none d-lg-block">
                @endif
            </div>

            <div class="col-lg-5 offset-lg-1">
                <div class="form-card" id="enquiry">
                    @if(session('submitted'))
                        <div class="text-center py-4">
                            <i class="bi bi-check-circle-fill text-success" style="font-size:3rem"></i>
                            <h4 class="mt-3">Thank you!</h4>
                            <p class="text-muted mb-0">{{ session('submitted') }}</p>
                        </div>
                    @else
                        <h4 class="mb-1">{{ $page->cta_text ?: 'Book Free Consultation' }}</h4>
                        <p class="text-muted small mb-4">Fill this in and our counsellor will call you.</p>

                        {{-- isset guard: the page is also rendered from the editor preview,
                             where the session error bag has not been shared in. --}}
                        @if(isset($errors) && $errors->any())
                            <div class="alert alert-danger small">
                                <ul class="mb-0 ps-3">
                                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                                </ul>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('landing.submit', $page->slug) }}" id="leadForm">
                            @csrf

                            {{-- Honeypot: hidden from people, tempting to bots. --}}
                            <div style="position:absolute;left:-9999px" aria-hidden="true">
                                <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                            </div>

                            {{-- Carry attribution through the form post. --}}
                            @foreach($utm ?? [] as $key => $value)
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endforeach

                            @foreach($page->fields() as $field)
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">
                                        {{ $field['label'] }}
                                        @if($field['required'] ?? false)<span class="text-danger">*</span>@endif
                                    </label>

                                    @if($field['type'] === 'textarea')
                                        <textarea name="{{ $field['key'] }}" class="form-control" rows="3"
                                                  @required($field['required'] ?? false)>{{ old($field['key']) }}</textarea>
                                    @else
                                        <input type="{{ $field['type'] }}" name="{{ $field['key'] }}" class="form-control"
                                               value="{{ old($field['key']) }}"
                                               @if($field['type'] === 'tel') inputmode="numeric" pattern="[0-9+\- ]{10,15}" @endif
                                               @required($field['required'] ?? false)>
                                    @endif
                                </div>
                            @endforeach

                            <button type="submit" class="btn btn-brand w-100">
                                {{ $page->cta_text ?: 'Book Free Consultation' }}
                            </button>

                            <p class="text-muted text-center small mt-3 mb-0">
                                <i class="bi bi-shield-lock me-1"></i>Your information stays confidential.
                            </p>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ---------------------------------------------------------------- --}}
{{-- Stats                                                             --}}
{{-- ---------------------------------------------------------------- --}}
@php
    $stats = collect([1, 2, 3])
        ->map(fn ($i) => [
            'value' => $page->sections['stat_' . $i . '_value'] ?? null,
            'label' => $page->sections['stat_' . $i . '_label'] ?? null,
        ])
        ->filter(fn ($stat) => $stat['value']);
@endphp

@if($stats->isNotEmpty())
    <section class="py-5 bg-light">
        <div class="container">
            <div class="row text-center g-4">
                @foreach($stats as $stat)
                    <div class="col-md-4">
                        <div class="stat-value">{{ $stat['value'] }}</div>
                        <div class="text-muted">{{ $stat['label'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endif

{{-- ---------------------------------------------------------------- --}}
{{-- Body content                                                      --}}
{{-- ---------------------------------------------------------------- --}}
@if($page->body_html)
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    {{-- Scripts stripped: the marketer supplies copy, not code. --}}
                    {!! strip_tags($page->body_html, '<h1><h2><h3><h4><p><ul><ol><li><strong><em><br><a><img><blockquote><hr><table><thead><tbody><tr><td><th>') !!}
                </div>
            </div>
        </div>
    </section>
@endif

{{-- ---------------------------------------------------------------- --}}
{{-- Testimonial                                                       --}}
{{-- ---------------------------------------------------------------- --}}
@if($page->sections['testimonial'] ?? null)
    <section class="py-5 bg-light">
        <div class="container">
            <div class="row justify-content-center text-center">
                <div class="col-lg-7">
                    <i class="bi bi-quote" style="font-size:2.5rem;color:var(--brand)"></i>
                    <p class="fs-5 fst-italic">{{ $page->sections['testimonial'] }}</p>
                    <p class="text-muted mb-0">{{ $page->sections['testimonial_author'] ?? '' }}</p>
                </div>
            </div>
        </div>
    </section>
@endif

{{-- ---------------------------------------------------------------- --}}
{{-- Closing CTA                                                       --}}
{{-- ---------------------------------------------------------------- --}}
@unless(session('submitted'))
    <section class="py-5 text-center">
        <div class="container">
            <h3 class="mb-3">Ready to take the first step?</h3>
            <a href="#enquiry" class="btn btn-brand px-4">{{ $page->cta_text ?: 'Book Free Consultation' }}</a>
        </div>
    </section>
@endunless

<footer>
    <div class="container d-flex flex-wrap justify-content-between gap-2">
        <span>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</span>
        @if($page->seo['privacy_url'] ?? null)
            <a href="{{ $page->seo['privacy_url'] }}" class="text-decoration-none text-white-50">Privacy Policy</a>
        @endif
    </div>
</footer>

@if($page->whatsapp_number)
    <a href="https://wa.me/{{ preg_replace('/\D/', '', $page->whatsapp_number) }}?text={{ urlencode('Hi, I would like to know more about IVF treatment.') }}"
       class="wa-float" target="_blank" rel="noopener" aria-label="Chat on WhatsApp" id="waFloat">
        <i class="bi bi-whatsapp"></i>
    </a>
@endif

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Fire the standard Lead events once the visitor actually submits.
    document.getElementById('leadForm')?.addEventListener('submit', function () {
        if (typeof fbq === 'function') fbq('track', 'Lead');
        if (typeof gtag === 'function') gtag('event', 'generate_lead');
    });

    document.getElementById('waFloat')?.addEventListener('click', function () {
        if (typeof fbq === 'function') fbq('track', 'Contact');
    });

    @if(session('submitted'))
        if (typeof fbq === 'function') fbq('track', 'CompleteRegistration');
    @endif
</script>
</body>
</html>
