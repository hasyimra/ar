@extends('dkm-ui::layouts.app')

@section('app-name', 'AR')
@section('app-icon', 'dollar-sign')
@section('home-url', route('dashboard'))

@push('head')
<style>
    :root { --theme-default: #2563eb; --theme-secondary: #3b82f6; }
    .page-header, .page-header .header-logo-wrapper { background: #2563eb !important; }
    .page-header { box-shadow: 0 2px 8px rgba(0,0,0,.3) !important; }
    .page-header .nav-menus > li > a,
    .page-header .media-body span { color: #fff !important; }
    .page-header .media-body p { color: rgba(255,255,255,.75) !important; }
    .sidebar-wrapper .logo-wrapper { background: #2563eb !important; }
    .logo-wrapper a span { color: #fff !important; }
    .sidebar-wrapper .sidebar-links .sidebar-list .sidebar-link.active,
    .sidebar-wrapper .sidebar-links .sidebar-list .sidebar-link:hover { color: #2563eb !important; }
    .sidebar-wrapper .sidebar-links .sidebar-list .sidebar-link.active svg,
    .sidebar-wrapper .sidebar-links .sidebar-list .sidebar-link:hover svg { stroke: #2563eb !important; }
    .btn-primary { background-color: #2563eb !important; border-color: #2563eb !important; }
    .btn-primary:hover { background-color: #1d4ed8 !important; border-color: #1d4ed8 !important; }
    .badge.bg-primary { background-color: #2563eb !important; }

    /* fix toggle sidebar mobile — dkm-ui tidak menyediakan JS-nya. z-index/position WAJIB
       !important — vendor CSS compact-wrapper override z-index sidebar jadi 9 di breakpoint
       ini, jauh di bawah overlay (1049), sehingga overlay menutupi sidebar dan link di
       dalamnya tidak bisa diklik walau sidebar kelihatan terbuka secara visual. */
    @media (max-width: 991px) {
        .page-wrapper.compact-wrapper .sidebar-wrapper {
            position: fixed !important; top: 0 !important; left: 0 !important; height: 100vh !important; z-index: 1050 !important;
            transform: translateX(-280px) !important; transition: transform .3s ease;
        }
        .page-wrapper.compact-wrapper .sidebar-wrapper.mobile-open {
            transform: translateX(0) !important; box-shadow: 4px 0 24px rgba(0,0,0,.25);
        }
        .page-wrapper.compact-wrapper .page-body-wrapper { margin-left: 0 !important; }
        .page-wrapper.compact-wrapper .page-header { margin-left: 0 !important; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 1049; }
        .sidebar-overlay.active { display: block; }
    }
</style>
@endpush

@push('scripts')
<script>
(function () {
    if (window.innerWidth >= 992) return;
    var sidebar = document.querySelector('.sidebar-wrapper');
    var overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);
    function open()  { sidebar.classList.add('mobile-open');    overlay.classList.add('active'); }
    function close() { sidebar.classList.remove('mobile-open'); overlay.classList.remove('active'); }
    document.querySelectorAll('.sidebar-toggle').forEach(function (btn) {
        btn.addEventListener('click', function (e) { e.stopPropagation(); sidebar.classList.contains('mobile-open') ? close() : open(); });
    });
    overlay.addEventListener('click', close);
})();
</script>
@endpush

@section('sidebar-links')
    @auth
        @unless(auth()->user()->isSsoAdmin())
            <li class="sidebar-list">
                <a class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                    <i data-feather="home"></i><span>Dashboard</span>
                </a>
            </li>
            <li class="sidebar-list">
                <a class="sidebar-link {{ request()->routeIs('ar-invoices.*') ? 'active' : '' }}" href="{{ route('ar-invoices.index') }}">
                    <i data-feather="file-text"></i><span>AR Invoices</span>
                </a>
            </li>
            <li class="sidebar-list">
                <a class="sidebar-link {{ request()->routeIs('ar-payments.*') ? 'active' : '' }}" href="{{ route('ar-payments.index') }}">
                    <i data-feather="credit-card"></i><span>AR Payments</span>
                </a>
            </li>
            <li class="sidebar-list">
                <a class="sidebar-link {{ request()->routeIs('ar-credit-notes.*') ? 'active' : '' }}" href="{{ route('ar-credit-notes.index') }}">
                    <i data-feather="rotate-ccw"></i><span>Credit Notes</span>
                </a>
            </li>
            <li class="sidebar-list">
                <a class="sidebar-link {{ request()->routeIs('reports.open-receivables') ? 'active' : '' }}" href="{{ route('reports.open-receivables') }}">
                    <i data-feather="file"></i><span>Open Receivables</span>
                </a>
            </li>
            <li class="sidebar-list">
                <a class="sidebar-link {{ request()->routeIs('reports.aged-receivables') ? 'active' : '' }}" href="{{ route('reports.aged-receivables') }}">
                    <i data-feather="bar-chart-2"></i><span>Aged Receivables</span>
                </a>
            </li>
            <li class="sidebar-list">
                <a class="sidebar-link {{ request()->routeIs('reports.aged-receivables-summary') ? 'active' : '' }}" href="{{ route('reports.aged-receivables-summary') }}">
                    <i data-feather="pie-chart"></i><span>Aged Receivables Summary</span>
                </a>
            </li>
            <li class="sidebar-list">
                <a class="sidebar-link {{ request()->routeIs('reports.ar-history') ? 'active' : '' }}" href="{{ route('reports.ar-history') }}">
                    <i data-feather="clock"></i><span>AR History</span>
                </a>
            </li>
        @endunless
        @if(auth()->user()->canManageUsers())
            <li class="sidebar-list">
                <a class="sidebar-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}">
                    <i data-feather="users"></i><span>Users</span>
                </a>
            </li>
        @endif
    @endauth
@endsection

@section('logout')
    <li>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-link p-0 text-start w-100">
                <i class="middle fa-solid fa-right-from-bracket"></i><span>Logout</span>
            </button>
        </form>
    </li>
@endsection
