<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'IVF CRM') — {{ config('app.name') }}</title>

    {{-- Inter is the typeface Frappe UI is built around. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600&display=swap">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

    {{-- Design system — must load last so it can restyle the libraries above. --}}
    @php
        // Cache-bust on file mtime, but never let a missing asset 500 the app —
        // an unstyled page is recoverable, a white error screen is not.
        $fuiVersion = @filemtime(public_path('css/frappe-ui.css')) ?: '1';
    @endphp
    <link rel="stylesheet" href="{{ asset('css/frappe-ui.css') }}?v={{ $fuiVersion }}">

    {{-- Apply the saved theme before first paint to avoid a light flash. --}}
    <script>
        (function () {
            try {
                if (localStorage.getItem('theme') === 'dark') {
                    document.documentElement.setAttribute('data-bs-theme', 'dark');
                }
                if (localStorage.getItem('sidebar') === 'collapsed') {
                    document.documentElement.classList.add('sidebar-collapsed');
                }
            } catch (e) { /* private mode — fall back to defaults */ }
        })();
    </script>

    <style>
        /* Collapsed sidebar: icons only. Kept here because it is shell layout,
           not part of the reusable design system. */
        html.sidebar-collapsed { --sidebar-width: 52px; }
        html.sidebar-collapsed #sidebar .nav-link { justify-content: center; padding: 0; }
        html.sidebar-collapsed #sidebar .nav-link span,
        html.sidebar-collapsed #sidebar .nav-label,
        html.sidebar-collapsed #sidebar .brand-text,
        html.sidebar-collapsed #sidebar .badge-count,
        html.sidebar-collapsed #sidebar .sidebar-search { display: none; }
        html.sidebar-collapsed #sidebar .brand { justify-content: center; padding: 10px 0; }
        html.sidebar-collapsed #sidebar .nav-label { height: 8px; padding: 4px 0 0; }

        /* Sidebar quick search */
        .sidebar-search { position: relative; padding: 0 8px 8px; }
        .sidebar-search input {
            height: 28px; width: 100%;
            background: var(--fui-surface); border: 1px solid var(--fui-border-subtle);
            border-radius: var(--fui-radius); padding: 0 8px 0 27px;
            font-size: var(--fui-text-sm); color: var(--fui-ink);
        }
        .sidebar-search input:focus { outline: none; border-color: var(--fui-blue-500); box-shadow: 0 0 0 2px rgba(44,123,229,.14); }
        .sidebar-search i { position: absolute; left: 16px; top: 7px; font-size: 13px; color: var(--fui-ink-faint); }
        .nav-link.nav-hidden, .nav-label.nav-hidden { display: none !important; }
    </style>
    @stack('styles')
</head>
<body>

{{-- ==================================================================== --}}
{{-- Sidebar                                                              --}}
{{-- ==================================================================== --}}
<nav id="sidebar">
    <div class="brand">
        <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
        <span class="brand-text flex-grow-1 text-truncate">IVF CRM</span>
        <button class="btn btn-link btn-sm px-1 brand-text d-none d-lg-inline-flex" id="sidebarCollapse"
                title="Collapse sidebar" style="height:22px">
            <i class="bi bi-chevron-double-left" style="font-size:12px"></i>
        </button>
    </div>

    <div class="sidebar-search">
        <i class="bi bi-search"></i>
        <input type="text" id="navSearch" placeholder="Search menu…" autocomplete="off">
    </div>

    <div class="sidebar-scroll">
        <div class="nav-label">Main</div>
        <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <i class="bi bi-grid-1x2"></i> <span>Dashboard</span>
        </a>
        @can('leads.view')
        <a href="{{ route('dashboard.pipeline') }}" class="nav-link {{ request()->routeIs('dashboard.pipeline') ? 'active' : '' }}">
            <i class="bi bi-kanban"></i> <span>Pipeline</span>
        </a>
        @endcan

        {{-- An enquiry and a patient are the same person; this section is the
             follow-up worklist over them, "Patients" is the full record. --}}
        @canany(['leads.view', 'followups.view', 'call_logs.view'])
        <div class="nav-label">Enquiries</div>
        @endcanany
        @can('leads.view')
        <a href="{{ route('leads.index') }}" class="nav-link {{ request()->routeIs('leads.*') ? 'active' : '' }}">
            <i class="bi bi-people"></i> <span>All Enquiries</span>
        </a>
        @endcan
        @can('followups.view')
        <a href="{{ route('followups.index') }}" class="nav-link {{ request()->routeIs('followups.*') ? 'active' : '' }}">
            <i class="bi bi-alarm"></i> <span>Follow-Ups</span>
            @php
                $due = \App\Models\FollowUp::where('user_id', auth()->id())
                    ->where('status', 'pending')->where('scheduled_at', '<=', now())->count();
            @endphp
            @if($due > 0)<span class="badge-count">{{ $due }}</span>@endif
        </a>
        @endcan
        @can('call_logs.view')
        <a href="{{ route('call_logs.index') }}" class="nav-link {{ request()->routeIs('call_logs.*') ? 'active' : '' }}">
            <i class="bi bi-telephone"></i> <span>Call Logs</span>
        </a>
        @endcan
        @can('call_logs.view')
        <a href="{{ route('calls.console') }}" class="nav-link {{ request()->routeIs('calls.console') ? 'active' : '' }}">
            <i class="bi bi-headset"></i> <span>Call Console</span>
        </a>
        @endcan
        <a href="{{ route('tata.softphone.index') }}" class="nav-link {{ request()->routeIs('tata.softphone.*') ? 'active' : '' }}">
            <i class="bi bi-telephone-inbound"></i> <span>Web Softphone</span>
        </a>

        @can('conversations.view')
        <div class="nav-label">Messaging</div>
        <a href="{{ route('chat.index') }}" class="nav-link {{ request()->routeIs('chat.*') ? 'active' : '' }}">
            <i class="bi bi-whatsapp"></i> <span>WhatsApp Inbox</span>
            @php
                $unreadChats = \App\Models\Conversation::visibleTo(auth()->user())->where('unread_count', '>', 0)->count();
            @endphp
            @if($unreadChats > 0)<span class="badge-count" id="navUnread">{{ $unreadChats }}</span>@endif
        </a>
        @can('broadcasts.view')
        <a href="{{ route('marketing.broadcasts.index') }}" class="nav-link {{ request()->routeIs('marketing.broadcasts.*') ? 'active' : '' }}">
            <i class="bi bi-broadcast"></i> <span>Broadcasts</span>
        </a>
        @endcan
        @can('whatsapp_templates.view')
        <a href="{{ route('marketing.templates.index') }}" class="nav-link {{ request()->routeIs('marketing.templates.*') ? 'active' : '' }}">
            <i class="bi bi-chat-square-text"></i> <span>Message Templates</span>
        </a>
        @endcan
        @can('whatsapp_flows.view')
        <a href="{{ route('marketing.flows.index') }}" class="nav-link {{ request()->routeIs('marketing.flows.*') ? 'active' : '' }}">
            <i class="bi bi-ui-checks-grid"></i> <span>WhatsApp Flows</span>
        </a>
        @endcan
        @endcan

        @canany(['appointments.view', 'patients.view'])
        <div class="nav-label">Clinical</div>
        @endcanany
        @can('appointments.view')
        <a href="{{ route('appointments.index') }}" class="nav-link {{ request()->routeIs('appointments.*') ? 'active' : '' }}">
            <i class="bi bi-calendar-check"></i> <span>Appointments</span>
        </a>
        @endcan
        @can('patients.view')
        <a href="{{ route('patients.index') }}" class="nav-link {{ request()->routeIs('patients.*') ? 'active' : '' }}">
            <i class="bi bi-person-vcard"></i> <span>Patients</span>
        </a>
        @endcan

        @canany(['campaigns.view', 'landing_pages.view', 'reports.view', 'lead_imports.create'])
        <div class="nav-label">Marketing</div>
        @endcanany
        @can('campaigns.view')
        <a href="{{ route('marketing.campaigns.index') }}" class="nav-link {{ request()->routeIs('marketing.campaigns.*') ? 'active' : '' }}">
            <i class="bi bi-megaphone"></i> <span>Ad Campaigns</span>
        </a>
        <a href="{{ route('marketing.adsets.index') }}" class="nav-link {{ request()->routeIs('marketing.adsets.*') ? 'active' : '' }}">
            <i class="bi bi-collection"></i> <span>Ad Sets</span>
        </a>
        <a href="{{ route('marketing.ads.index') }}" class="nav-link {{ request()->routeIs('marketing.ads.*') ? 'active' : '' }}">
            <i class="bi bi-badge-ad"></i> <span>Ads</span>
        </a>
        <a href="{{ route('marketing.leadforms.index') }}" class="nav-link {{ request()->routeIs('marketing.leadforms.*') ? 'active' : '' }}">
            <i class="bi bi-ui-checks"></i> <span>Lead Forms</span>
        </a>
        <a href="{{ route('marketing.audiences.index') }}" class="nav-link {{ request()->routeIs('marketing.audiences.*') ? 'active' : '' }}">
            <i class="bi bi-people-fill"></i> <span>Audiences</span>
        </a>
        @endcan
        @can('landing_pages.view')
        <a href="{{ route('marketing.landing.index') }}" class="nav-link {{ request()->routeIs('marketing.landing.*') ? 'active' : '' }}">
            <i class="bi bi-window"></i> <span>Landing Pages</span>
        </a>
        @endcan
        @can('reports.view')
        <a href="{{ route('marketing.analytics.index') }}" class="nav-link {{ request()->routeIs('marketing.analytics.index') ? 'active' : '' }}">
            <i class="bi bi-graph-up-arrow"></i> <span>Ad Analytics</span>
        </a>
        <a href="{{ route('marketing.analytics.funnel') }}" class="nav-link {{ request()->routeIs('marketing.analytics.funnel') ? 'active' : '' }}">
            <i class="bi bi-funnel"></i> <span>Conversion Funnel</span>
        </a>
        @endcan
        @can('campaigns.view')
        <a href="{{ route('campaigns.index') }}" class="nav-link {{ request()->routeIs('campaigns.*') ? 'active' : '' }}">
            <i class="bi bi-list-columns"></i> <span>All Campaigns</span>
        </a>
        @endcan
        @can('lead_imports.create')
        <a href="{{ route('imports.index') }}" class="nav-link {{ request()->routeIs('imports.*') ? 'active' : '' }}">
            <i class="bi bi-upload"></i> <span>Import Leads</span>
        </a>
        @endcan

        @can('reports.view')
        <div class="nav-label">Reports</div>
        <a href="{{ route('reports.leads') }}" class="nav-link {{ request()->routeIs('reports.leads') ? 'active' : '' }}">
            <i class="bi bi-bar-chart"></i> <span>Lead Report</span>
        </a>
        <a href="{{ route('reports.telecaller') }}" class="nav-link {{ request()->routeIs('reports.telecaller') ? 'active' : '' }}">
            <i class="bi bi-person-lines-fill"></i> <span>Telecaller Report</span>
        </a>
        <a href="{{ route('reports.source') }}" class="nav-link {{ request()->routeIs('reports.source') ? 'active' : '' }}">
            <i class="bi bi-pie-chart"></i> <span>Source Report</span>
        </a>
        @endcan

        @canany(['users.view','roles.view','dispositions.view','centers.view','lead_sources.view','assignment_rules.view','integrations.view','telephony.view','tata.view'])
        <div class="nav-label">Settings</div>
        @can('integrations.view')
        <a href="{{ route('settings.integrations.index') }}" class="nav-link {{ request()->routeIs('settings.integrations.*') ? 'active' : '' }}">
            <i class="bi bi-plug"></i> <span>Integrations</span>
        </a>
        @endcan
        @can('telephony.view')
        <a href="{{ route('settings.telephony.index') }}" class="nav-link {{ request()->routeIs('settings.telephony.*') ? 'active' : '' }}">
            <i class="bi bi-telephone-plus"></i> <span>Telephony</span>
        </a>
        @endcan

        @can('tata.view')
        <a href="{{ route('tata.index') }}" class="nav-link {{ request()->routeIs('tata.index') || request()->routeIs('tata.config.*') || request()->routeIs('tata.agents.*') || request()->routeIs('tata.dispositions.*') || request()->routeIs('tata.numbers.*') || request()->routeIs('tata.calls.*') ? 'active' : '' }}">
            <i class="bi bi-cloud-arrow-up"></i> <span>Tata Teleservices</span>
        </a>
        @endcan
        @can('tata.view')
        <a href="{{ route('tata.broadcasts.index') }}" class="nav-link {{ request()->routeIs('tata.broadcasts.*') ? 'active' : '' }}">
            <i class="bi bi-megaphone"></i> <span>Voice Broadcasts</span>
        </a>
        @endcan
        @can('users.view')
        <a href="{{ route('settings.users.index') }}" class="nav-link {{ request()->routeIs('settings.users.*') ? 'active' : '' }}">
            <i class="bi bi-people-fill"></i> <span>Users</span>
        </a>
        @endcan
        @can('roles.view')
        <a href="{{ route('settings.roles.index') }}" class="nav-link {{ request()->routeIs('settings.roles.*') ? 'active' : '' }}">
            <i class="bi bi-shield-lock"></i> <span>Roles &amp; Permissions</span>
        </a>
        @endcan
        @can('dispositions.view')
        <a href="{{ route('settings.dispositions.index') }}" class="nav-link {{ request()->routeIs('settings.dispositions.*') ? 'active' : '' }}">
            <i class="bi bi-tags"></i> <span>Dispositions</span>
        </a>
        @endcan
        @can('centers.view')
        <a href="{{ route('settings.centers.index') }}" class="nav-link {{ request()->routeIs('settings.centers.*') ? 'active' : '' }}">
            <i class="bi bi-building"></i> <span>Centers</span>
        </a>
        @endcan
        @can('lead_sources.view')
        <a href="{{ route('settings.lead_sources.index') }}" class="nav-link {{ request()->routeIs('settings.lead_sources.*') ? 'active' : '' }}">
            <i class="bi bi-signpost-split"></i> <span>Lead Sources</span>
        </a>
        @endcan
        @can('assignment_rules.view')
        <a href="{{ route('settings.assignment_rules.index') }}" class="nav-link {{ request()->routeIs('settings.assignment_rules.*') ? 'active' : '' }}">
            <i class="bi bi-diagram-3"></i> <span>Lead Assignment</span>
        </a>
        @endcan
        @endcanany

        <div class="fui-empty nav-hidden" id="navNoResults" style="padding:20px 8px">
            <div class="small">No menu item matches.</div>
        </div>
    </div>

    {{-- User, pinned to the bottom like Frappe UI --}}
    <div class="sidebar-footer">
        <div class="dropdown">
            <button class="btn btn-link w-100 d-flex align-items-center gap-2 px-2" data-bs-toggle="dropdown"
                    style="height:34px">
                <span class="fui-avatar" style="width:22px;height:22px;font-size:10px">
                    {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                </span>
                <span class="brand-text flex-grow-1 text-start text-truncate" style="font-size:var(--fui-text-base)">
                    {{ auth()->user()->name }}
                </span>
                <i class="bi bi-chevron-expand brand-text" style="font-size:11px"></i>
            </button>

            <ul class="dropdown-menu shadow w-100">
                <li><h6 class="dropdown-header text-truncate">{{ auth()->user()->email }}</h6></li>
                <li>
                    <span class="dropdown-item-text text-muted">
                        {{ auth()->user()->getRoleNames()->join(', ') ?: 'No role' }}
                    </span>
                </li>
                <li><hr class="dropdown-divider"></li>
                @can('call_logs.view')
                    <li>
                        <a class="dropdown-item" href="{{ route('telephony.my_microsip') }}">
                            <i class="bi bi-download"></i>MicroSIP profile
                        </a>
                    </li>
                @endcan
                <li>
                    <a class="dropdown-item" href="#" id="darkToggle">
                        <i class="bi bi-moon" id="darkIcon"></i><span id="darkLabel">Dark mode</span>
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right"></i>Log out</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</nav>

{{-- ==================================================================== --}}
{{-- Topbar                                                               --}}
{{-- ==================================================================== --}}
<header id="topbar">
    <button class="btn btn-sm btn-link d-lg-none px-1" id="sidebarToggle">
        <i class="bi bi-list" style="font-size:17px"></i>
    </button>

    <nav aria-label="breadcrumb" class="flex-grow-1 min-w-0">
        <ol class="breadcrumb">
            @yield('breadcrumb')
        </ol>
    </nav>

    <div class="d-flex align-items-center gap-2">
        @yield('page-actions')

        @can('call_logs.view')
            @include('telephony._dialer')
        @endcan

        <div class="fui-vr d-none d-md-block"></div>

        <button class="btn btn-sm btn-link px-1" title="Notifications">
            <i class="bi bi-bell" style="font-size:15px"></i>
        </button>
    </div>
</header>

{{-- ==================================================================== --}}
{{-- Main                                                                 --}}
{{-- ==================================================================== --}}
<main id="main-content">
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-start" role="alert">
            <i class="bi bi-check-circle-fill"></i>
            <div class="flex-grow-1">{{ session('success') }}</div>
            <button type="button" class="btn-close p-0 ms-2" data-bs-dismiss="alert" style="position:static"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-start" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div class="flex-grow-1">{{ session('error') }}</div>
            <button type="button" class="btn-close p-0 ms-2" data-bs-dismiss="alert" style="position:static"></button>
        </div>
    @endif
    @if(session('warning'))
        <div class="alert alert-warning alert-dismissible fade show d-flex align-items-start" role="alert">
            <i class="bi bi-exclamation-circle-fill"></i>
            <div class="flex-grow-1">{{ session('warning') }}</div>
            <button type="button" class="btn-close p-0 ms-2" data-bs-dismiss="alert" style="position:static"></button>
        </div>
    @endif

    @yield('content')
</main>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

<script>
$.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });

/* One chart palette for the whole product. Muted and evenly spaced in
   lightness so series stay distinguishable in both themes and in print. */
window.FUI = {
    colors: ['#2c7be5', '#0d9488', '#7c3aed', '#dc6803', '#d92d20', '#039855', '#db2777', '#8c8c8c'],
    ink: () => getComputedStyle(document.documentElement).getPropertyValue('--fui-ink-subtle').trim(),
    line: () => getComputedStyle(document.documentElement).getPropertyValue('--fui-border-subtle').trim(),
};

/* ApexCharts global defaults — every chart inherits the type ramp, muted
   gridlines and no toolbar chrome without repeating it per view. */
window.Apex = {
    chart: {
        fontFamily: "'Inter', -apple-system, sans-serif",
        foreColor: FUI.ink(),
        toolbar: { show: false },
        animations: { easing: 'easeinout', speed: 320 },
    },
    colors: FUI.colors,
    grid: { borderColor: FUI.line(), strokeDashArray: 3, padding: { left: 4, right: 4 } },
    dataLabels: { enabled: false },
    stroke: { width: 2, curve: 'smooth' },
    legend: { fontSize: '12px', markers: { width: 8, height: 8, radius: 8 }, itemMargin: { horizontal: 8 } },
    tooltip: { style: { fontSize: '12px' } },
    xaxis: { axisBorder: { show: false }, axisTicks: { show: false }, labels: { style: { fontSize: '11px' } } },
    yaxis: { labels: { style: { fontSize: '11px' } } },
};

toastr.options = {
    positionClass: 'toast-top-right',
    timeOut: 3500,
    progressBar: true,
    newestOnTop: true,
    preventDuplicates: true,
};

/* Shared empty-state markup, so every table's "nothing here" looks the same
   while still saying something specific about what is missing. */
/* One date format across every table. Views were slicing ISO strings by hand
   (`d.substring(0, 16)`), which rendered "2026-06-26T16:30" with the raw T. */
// Dates/times are shown in dd-mm-yyyy and 12-hour AM/PM, in India time,
// everywhere in the product. The datetime string from the server may be naive
// (app timezone is Asia/Kolkata) or carry an offset; either way we render in IST.
FUI.TZ = 'Asia/Kolkata';
FUI._parse = function (value) {
    if (value == null || value === '') return null;
    let d = new Date(value);
    if (isNaN(d)) {
        // Naive "YYYY-MM-DD HH:MM[:SS]" — treat as India wall-clock.
        const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (m) d = new Date(`${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}:00+05:30`);
    }
    return isNaN(d) ? null : d;
};
FUI.date = function (value) {
    const d = FUI._parse(value);
    if (!d) return value ? $('<div>').text(value).html() : '<span class="text-muted">—</span>';
    const p = new Intl.DateTimeFormat('en-GB', { timeZone: FUI.TZ, day: '2-digit', month: '2-digit', year: 'numeric' }).formatToParts(d);
    const g = t => p.find(x => x.type === t).value;
    return `${g('day')}-${g('month')}-${g('year')}`;
};
FUI.time = function (value) {
    const d = FUI._parse(value);
    if (!d) return value ? $('<div>').text(value).html() : '<span class="text-muted">—</span>';
    return new Intl.DateTimeFormat('en-IN', { timeZone: FUI.TZ, hour: '2-digit', minute: '2-digit', hour12: true }).format(d);
};
FUI.dateTime = function (value) {
    const d = FUI._parse(value);
    if (!d) return value ? $('<div>').text(value).html() : '<span class="text-muted">—</span>';
    return FUI.date(value) + ', ' + FUI.time(value);
};

FUI.emptyState = function (icon, title, hint) {
    return '<div class="fui-empty">'
        + '<i class="bi ' + (icon || 'bi-inbox') + '"></i>'
        + '<div class="fui-empty-title">' + (title || 'Nothing here yet') + '</div>'
        + (hint ? '<div class="small">' + hint + '</div>' : '')
        + '</div>';
};

/* THE single DataTables definition for the product.
   Every table inherits this; views should only ever set ajax, columns,
   ordering and their own emptyTable message. The `dom` layout is:

       [length] [buttons] ............ [search]
       [table]
       [1-25 of 340] ................. [pagination]

   Plain flex wrappers rather than Bootstrap grid: DataTables ships rules for
   `div.table-responsive > .dataTables_wrapper > .row` that add stray gutters,
   and using .row inside a scroll container triggers them. */
$.extend(true, $.fn.dataTable.defaults, {
    dom: "<'dt-toolbar'<'dt-toolbar-left'lB><'dt-toolbar-right'f>>"
       + "r<'dt-scroll't>"
       + "<'dt-footer'<'dt-footer-left'i><'dt-footer-right'p>>",
    pageLength: 25,
    lengthMenu: [[10, 25, 50, 100], ['10 rows', '25 rows', '50 rows', '100 rows']],
    autoWidth: false,
    language: {
        search: '',
        searchPlaceholder: 'Search…',
        lengthMenu: '_MENU_',
        info: '_START_–_END_ of _TOTAL_',
        infoEmpty: '0 records',
        infoFiltered: ' (of _MAX_)',
        processing: '<span class="spinner-border spinner-border-sm me-2"></span>Loading…',
        paginate: {
            previous: '<i class="bi bi-chevron-left"></i>',
            next: '<i class="bi bi-chevron-right"></i>',
        },
        emptyTable: FUI.emptyState('bi-inbox', 'Nothing here yet'),
        zeroRecords: FUI.emptyState('bi-search', 'No matches', 'Try a different search or filter.'),
    },
});

/* Views wrap tables in .table-responsive, which places the entire DataTables
   wrapper inside the scroll container - so on a wide table the search box and
   pagination scrolled sideways with the rows. Flag those hosts so the CSS can
   hand scrolling to the inner .dt-scroll and leave the chrome fixed. */
/* DataTables defaults to ordering by column 0. Where column 0 is a checkbox or
   an action cell that is `orderable: false`, that produced a meaningless sort
   and a stray arrow in the header. Fall back to the first column that can
   actually be sorted. */
$(document).on('preInit.dt', function (e, settings) {
    const columns = settings.aoColumns || [];
    const order = settings.aaSorting || [];

    const sortsOnUnsortable = order.some(([index]) => columns[index] && !columns[index].bSortable);

    if (!sortsOnUnsortable) return;

    const firstSortable = columns.findIndex(column => column.bSortable);

    settings.aaSorting = firstSortable === -1 ? [] : [[firstSortable, 'asc']];
});

$(document).on('init.dt', function (e, settings) {
    const api = new $.fn.dataTable.Api(settings);

    $(settings.nTable).closest('.table-responsive').addClass('dt-host');

    // `B` is in the shared dom string, so tables that define no buttons still
    // get an empty container — which would occupy a gap in the flex toolbar.
    $(api.table().container()).find('.dt-buttons').each(function () {
        if (!this.children.length) $(this).remove();
    });
});

/* Export buttons carry the design-system button classes wherever a view
   defines them, without every view having to repeat className. */
if ($.fn.dataTable.Buttons) {
    $.extend(true, $.fn.dataTable.Buttons.defaults, {
        dom: {
            button: { className: 'btn btn-sm btn-outline-secondary' },
        },
    });
}

/* Select2 defaults so every instance matches, including ones created
   without an explicit theme. */
if ($.fn.select2) {
    $.fn.select2.defaults.set('theme', 'bootstrap-5');
    $.fn.select2.defaults.set('width', '100%');
}

/* Sidebar */
$('#sidebarToggle').on('click', () => $('#sidebar').toggleClass('show'));

$('#sidebarCollapse').on('click', function (e) {
    e.stopPropagation();
    const collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
    try { localStorage.setItem('sidebar', collapsed ? 'collapsed' : 'expanded'); } catch (e) {}
});

/* Menu filter — keeps a section label only while one of its items matches. */
$('#navSearch').on('input', function () {
    const term = this.value.trim().toLowerCase();
    const $items = $('#sidebar .sidebar-scroll .nav-link');

    if (!term) {
        $items.removeClass('nav-hidden');
        $('#sidebar .nav-label').removeClass('nav-hidden');
        $('#navNoResults').addClass('nav-hidden');
        return;
    }

    $items.each(function () {
        $(this).toggleClass('nav-hidden', !$(this).text().toLowerCase().includes(term));
    });

    let anyVisible = false;
    $('#sidebar .nav-label').each(function () {
        const visible = $(this).nextUntil('.nav-label', '.nav-link').not('.nav-hidden').length > 0;
        $(this).toggleClass('nav-hidden', !visible);
        anyVisible = anyVisible || visible;
    });

    $('#navNoResults').toggleClass('nav-hidden', anyVisible);
});

/* Focus the menu filter with "/" — a Frappe UI convention. */
$(document).on('keydown', function (e) {
    if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)
        && !document.activeElement.isContentEditable) {
        e.preventDefault();
        $('#navSearch').trigger('focus');
    }
});

/* Dark mode */
function paintThemeToggle() {
    const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    $('#darkIcon').attr('class', dark ? 'bi bi-sun' : 'bi bi-moon');
    $('#darkLabel').text(dark ? 'Light mode' : 'Dark mode');
}

$('#darkToggle').on('click', function (e) {
    e.preventDefault();
    const html = document.documentElement;
    const dark = html.getAttribute('data-bs-theme') === 'dark';
    html.setAttribute('data-bs-theme', dark ? 'light' : 'dark');
    try { localStorage.setItem('theme', dark ? 'light' : 'dark'); } catch (e) {}
    paintThemeToggle();
});

paintThemeToggle();

/* Tooltips on anything carrying a title */
document.querySelectorAll('[title]:not([data-bs-toggle])').forEach(el => {
    new bootstrap.Tooltip(el, { delay: { show: 500, hide: 0 }, container: 'body' });
});

/* Global AJAX error handling */
$(document).ajaxError(function (e, xhr) {
    if (xhr.status === 419) toastr.warning('Your session expired. Refresh the page.');
    if (xhr.status === 401) toastr.error('You have been signed out.');
    if (xhr.status === 403) toastr.error('You do not have permission to do that.');
    if (xhr.status === 422) {
        const errors = xhr.responseJSON?.errors;
        if (errors) Object.values(errors).flat().forEach(msg => toastr.error(msg));
        else if (xhr.responseJSON?.message) toastr.error(xhr.responseJSON.message);
    }
    if (xhr.status >= 500) toastr.error('Something went wrong on the server.');
});
</script>
@stack('scripts')
</body>
</html>
