<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Dashboard - ExchangeIT</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="{{ asset('css/app-theme.css') }}" rel="stylesheet">
</head>
<body>
  @include('external.nav')

  <main class="container mt-5">
    <h2 class="fw-bold">Welcome, {{ $userName }}!</h2>
    <p class="text-muted mb-4">This is your admin dashboard.</p>

    <h4 class="mb-3">Admin Panel</h4>
    <div class="row g-3">
      <!-- Existing: User Info -->
      <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body d-flex flex-column">
            <h5 class="card-title">User Info</h5>
            <p class="card-text">See all users, warn or delete.</p>
            <a href="{{ route('admin.users') }}" class="btn btn-brand mt-auto">Open</a>
          </div>
        </div>
      </div>

      <!-- Existing: Tradings Statistics -->
      <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body d-flex flex-column">
            <h5 class="card-title">Tradings Statistics</h5>
            <p class="card-text">Monthly users, items, trades & pending.</p>
            <a href="{{ route('admin.stats') }}" class="btn btn-brand mt-auto">Open</a>
          </div>
        </div>
      </div>

      <!-- Existing: All Items (admin-wide) -->
      <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body d-flex flex-column">
            <h5 class="card-title">All Items</h5>
            <p class="card-text">View every item and delete if needed.</p>
            <a href="{{ route('admin.items') }}" class="btn btn-brand mt-auto">Open</a>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body d-flex flex-column">
            <h5 class="card-title">Categories</h5>
            <p class="card-text">Create, edit, or delete item categories.</p>
            <a href="{{ route('admin.categories') }}" class="btn btn-brand mt-auto">Open</a>
          </div>
        </div>
      </div>

      <!-- NEW: Profile Settings -->
      <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body d-flex flex-column">
            <h5 class="card-title">Profile Settings</h5>
            <p class="card-text">Update your name, phone, NID, and password.</p>
            <a href="{{ route('profile.edit') }}" class="btn btn-brand mt-auto">Open</a>
          </div>
        </div>
      </div>

      <!-- NEW: My Items (admin's own items) -->
      <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body d-flex flex-column">
            <h5 class="card-title">My Items</h5>
            <p class="card-text">Manage items you’ve submitted.</p>
            <a href="{{ route('items.mine') }}" class="btn btn-brand mt-auto">Open</a>
          </div>
        </div>
      </div>

      <!-- NEW: Requested Items (admin's outgoing requests) -->
      <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body d-flex flex-column">
            <h5 class="card-title">Requested Items</h5>
            <p class="card-text">See trade requests you’ve sent.</p>
            <a href="{{ route('requests.mine') }}" class="btn btn-brand mt-auto">Open</a>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
