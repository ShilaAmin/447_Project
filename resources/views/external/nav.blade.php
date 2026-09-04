<nav class="navbar navbar-expand-lg app-navbar shadow-sm border-0" id="appNav">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="{{ url('/') }}">
      <span class="brand-gradient fw-bold brand-text">ExchangeIT</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    @php
      $isLoggedIn = session()->has('user_id');
      $isAdmin    = $isLoggedIn && session('is_admin');
      $unreadCount = 0;
      if ($isLoggedIn) {
        $unreadCount = \App\Models\Notification::where('user_id', session('user_id'))
          ->where('read', 0)
          ->count();
      }
    @endphp

    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto align-items-lg-center">
        @if($isLoggedIn)
          <li class="nav-item"><a class="nav-link" href="{{ url('/dashboard') }}">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="{{ route('community.index') }}">Community</a></li>
          <li class="nav-item"><a class="nav-link" href="{{ url('/items/create') }}">Submit Item</a></li>
          <li class="nav-item"><a class="nav-link" href="{{ url('/items/search') }}">Search Items</a></li>
          <li class="nav-item"><a class="nav-link" href="{{ url('/requests') }}">Requests</a></li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              Notifications
              @if($unreadCount > 0)
                <span class="badge bg-danger ms-1">{{ $unreadCount }}</span>
              @endif
            </a>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width: 240px;">
              <li>
                <span class="dropdown-item-text small text-muted">
                  @if($unreadCount > 0)
                    {{ $unreadCount }} unread notification{{ $unreadCount === 1 ? '' : 's' }}
                  @else
                    No unread notifications
                  @endif
                </span>
              </li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="{{ route('notifications.index') }}">View notifications</a></li>
            </ul>
          </li>
          <li class="nav-item ms-lg-2">
            <a class="btn btn-brand fw-bold px-3" href="{{ url('/logout') }}">Logout</a>
          </li>
        @else
          <li class="nav-item"><a class="nav-link" href="{{ url('/login') }}">Login</a></li>
          <li class="nav-item ms-lg-2"><a class="btn btn-brand fw-bold px-3" href="{{ url('/signup') }}">Signup</a></li>
        @endif
      </ul>
    </div>
  </div>
</nav>

<script>
  (function(){
    const nav = document.getElementById('appNav');
    const toggle = () => {
      if (window.scrollY > 8) nav.classList.add('is-scrolled');
      else nav.classList.remove('is-scrolled');
    };
    toggle();
    window.addEventListener('scroll', toggle, { passive: true });
  })();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
