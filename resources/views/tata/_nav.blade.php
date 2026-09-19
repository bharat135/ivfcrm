{{-- Sub-navigation for the Tata Teleservices section. --}}
<ul class="nav nav-pills flex-wrap gap-1 mb-3">
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('tata.index') ? 'active' : '' }}" href="{{ route('tata.index') }}"><i class="bi bi-speedometer2 me-1"></i>Overview</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('tata.config.*') ? 'active' : '' }}" href="{{ route('tata.config.index') }}"><i class="bi bi-sliders me-1"></i>Configuration</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('tata.agents.*') ? 'active' : '' }}" href="{{ route('tata.agents.index') }}"><i class="bi bi-people me-1"></i>Agents</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('tata.dispositions.*') ? 'active' : '' }}" href="{{ route('tata.dispositions.index') }}"><i class="bi bi-tags me-1"></i>Dispositions</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('tata.numbers.*') ? 'active' : '' }}" href="{{ route('tata.numbers.index') }}"><i class="bi bi-hash me-1"></i>Numbers &amp; Routing</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('tata.calls.*') ? 'active' : '' }}" href="{{ route('tata.calls.index') }}"><i class="bi bi-clock-history me-1"></i>Call Records</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('tata.broadcasts.*') ? 'active' : '' }}" href="{{ route('tata.broadcasts.index') }}"><i class="bi bi-megaphone me-1"></i>Broadcasts</a></li>
</ul>
