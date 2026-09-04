<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Login - ExchangeIT</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="{{ asset('css/app-theme.css') }}" rel="stylesheet">
</head>
<body>
  @include('external.nav')

  <main class="d-flex justify-content-center align-items-center" style="min-height:80vh;">
    <div class="card shadow-sm p-4" style="max-width: 420px; width: 100%; border-top: 4px solid var(--brand-start);">
      <h3 class="mb-4 fw-bold text-center brand-gradient">Login</h3>

      @if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif
      @if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif

      @if(session('otp_secret'))
        <div class="alert alert-info">
          <p class="mb-2"><strong>Save your 2FA secret:</strong> <code>{{ session('otp_secret') }}</code></p>
          <p class="mb-2 small">Add it in Google Authenticator / Authy (manual entry), or open this otpauth URL:</p>
          <p class="small text-break mb-0"><a href="{{ session('otp_qr') }}">{{ session('otp_qr') }}</a></p>
        </div>
      @endif

      <form method="POST" action="{{ url('/login') }}">
        @csrf
        <div class="mb-3">
          <label for="email" class="form-label fw-semibold">Email</label>
          <input type="email" id="email" name="email" required class="form-control" value="{{ old('email') }}" />
        </div>
        <div class="mb-3">
          <label for="password" class="form-label fw-semibold">Password</label>
          <input type="password" id="password" name="password" required class="form-control" />
        </div>
        <div class="d-grid"><button type="submit" class="btn btn-brand fw-bold">Login</button></div>
        <div class="text-center mt-3">
          <small>Don’t have an account? <a href="{{ url('/signup') }}" class="text-decoration-none brand-gradient">Sign up here</a></small>
        </div>
      </form>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
