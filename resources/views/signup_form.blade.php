<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Signup - ExchangeIT</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="{{ asset('css/app-theme.css') }}" rel="stylesheet">
</head>
<body>
  @include('external.nav')

  <main class="d-flex justify-content-center align-items-center" style="min-height:80vh;">
    <div class="card shadow-sm p-4" style="max-width: 480px; width: 100%; border-top: 4px solid var(--brand-start);">
      <h3 class="mb-4 fw-bold text-center brand-gradient">Create Account</h3>

      @if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif

      <form method="POST" action="{{ url('/signup') }}">
        @csrf
        <div class="mb-3">
          <label class="form-label fw-semibold">Full Name</label>
          <input type="text" name="name" value="{{ old('name') }}" required class="form-control @error('name') is-invalid @enderror" />
          @error('name') <small class="text-danger">{{ $message }}</small> @enderror
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Email</label>
          <input type="email" name="email" value="{{ old('email') }}" required class="form-control @error('email') is-invalid @enderror" />
          @error('email') <small class="text-danger">{{ $message }}</small> @enderror
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Phone Number</label>
          <input type="text" name="phone" value="{{ old('phone') }}" required class="form-control @error('phone') is-invalid @enderror" />
          @error('phone') <small class="text-danger">{{ $message }}</small> @enderror
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Address</label>
          <input type="text" name="address" value="{{ old('address') }}" required class="form-control @error('address') is-invalid @enderror" />
          @error('address') <small class="text-danger">{{ $message }}</small> @enderror
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">NID Number</label>
          <input type="text" name="nid_no" value="{{ old('nid_no') }}" required class="form-control @error('nid_no') is-invalid @enderror" />
          @error('nid_no') <small class="text-danger">{{ $message }}</small> @enderror
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Password</label>
          <input type="password" name="password" required class="form-control @error('password') is-invalid @enderror" />
          @error('password') <small class="text-danger">{{ $message }}</small> @enderror
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Confirm Password</label>
          <input type="password" name="password_confirmation" required class="form-control" />
        </div>

        <div class="d-grid"><button type="submit" class="btn btn-brand fw-bold">Sign Up</button></div>
        <div class="text-center mt-3">
          <small>Already have an account? <a href="{{ url('/login') }}" class="text-decoration-none brand-gradient">Login here</a></small>
        </div>
      </form>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
