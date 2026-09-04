<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Profile Settings - ExchangeIT</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="{{ asset('css/app-theme.css') }}" rel="stylesheet">
</head>
<body>
@include('external.nav')

<div class="container mt-4" style="max-width:720px;">
  <h3 class="mb-3">Profile Settings</h3>

  @if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
  @if(session('error'))   <div class="alert alert-danger">{{ session('error') }}</div> @endif

  <form action="{{ route('profile.update') }}" method="POST" class="card border-0 shadow-sm p-4">
    @csrf @method('PUT')

    <div class="mb-3">
      <label class="form-label">Full Name</label>
      <input type="text" name="name" value="{{ old('name', $user->name) }}" class="form-control @error('name') is-invalid @enderror" required>
      @error('name') <small class="text-danger">{{ $message }}</small> @enderror
    </div>

    <div class="mb-3">
      <label class="form-label">Email (read-only)</label>
      <input type="email" value="{{ $user->email }}" class="form-control" disabled>
      <small class="text-muted">Email change not enabled.</small>
    </div>

    <div class="mb-3">
      <label class="form-label">Phone Number</label>
      <input type="text" name="phone" value="{{ old('phone', $user->phone) }}" class="form-control @error('phone') is-invalid @enderror" required>
      @error('phone') <small class="text-danger">{{ $message }}</small> @enderror
    </div>

    <div class="mb-3">
      <label class="form-label">Address</label>
      <input type="text" name="address" value="{{ old('address', $user->address ?? '') }}" class="form-control @error('address') is-invalid @enderror" required>
      @error('address') <small class="text-danger">{{ $message }}</small> @enderror
    </div>

    <div class="mb-3">
      <label class="form-label">NID Number</label>
      <input type="text" name="nid_no" value="{{ old('nid_no', $user->nid_no) }}" class="form-control @error('nid_no') is-invalid @enderror" required>
      @error('nid_no') <small class="text-danger">{{ $message }}</small> @enderror
    </div>

    <hr>

    <div class="mb-3">
      <label class="form-label">New Password (optional)</label>
      <input type="password" name="password" class="form-control @error('password') is-invalid @enderror">
      @error('password') <small class="text-danger">{{ $message }}</small> @enderror
    </div>
    <div class="mb-3">
      <label class="form-label">Confirm New Password</label>
      <input type="password" name="password_confirmation" class="form-control">
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-brand">Save Changes</button>
      <a href="{{ url('/dashboard') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
</body>
</html>
