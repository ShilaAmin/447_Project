<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>OTP Verification - ExchangeIT</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="{{ asset('css/app-theme.css') }}" rel="stylesheet">
</head>
<body>
  @include('external.nav')

  <main class="d-flex justify-content-center align-items-center" style="min-height:80vh;">
    <div class="card shadow-sm p-4" style="max-width: 420px; width: 100%; border-top: 4px solid var(--brand-start);">
      <h3 class="mb-3 fw-bold text-center brand-gradient">Two-Factor Authentication</h3>
      <p class="text-muted text-center">Enter the 6-digit code from your authenticator app.</p>

      @if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif

      <form method="POST" action="{{ url('/login/otp') }}">
        @csrf
        <div class="mb-3">
          <label class="form-label fw-semibold">OTP Code</label>
          <input type="text" name="one_time_password" inputmode="numeric" autocomplete="one-time-code" required class="form-control" autofocus />
        </div>
        <div class="d-grid"><button type="submit" class="btn btn-brand fw-bold">Verify & Continue</button></div>
      </form>
    </div>
  </main>
</body>
</html>
