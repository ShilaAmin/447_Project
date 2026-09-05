<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage Categories</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="{{ asset('css/app-theme.css') }}" rel="stylesheet">
</head>
<body>
@include('external.nav')

<div class="container mt-4" style="max-width:720px;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Categories</h3>
    <a href="{{ url('/dashboard') }}" class="btn btn-outline-secondary btn-sm">Back to Dashboard</a>
  </div>

  @if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
  @if(session('error'))   <div class="alert alert-danger">{{ session('error') }}</div> @endif

  <form action="{{ route('admin.categories.store') }}" method="POST" class="card border-0 shadow-sm p-3 mb-4">
    @csrf
    <div class="input-group">
      <input type="text" name="name" class="form-control" placeholder="New category name" required>
      <button class="btn btn-brand">Add</button>
    </div>
  </form>

  <ul class="list-group shadow-sm">
    @forelse($categories as $category)
      <li class="list-group-item d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <form action="{{ route('admin.categories.update', $category->id) }}" method="POST" class="d-flex gap-2 flex-grow-1">
          @csrf
          @method('PUT')
          <input type="text" name="name" value="{{ $category->name }}" class="form-control form-control-sm" required>
          <button class="btn btn-sm btn-outline-primary">Save</button>
        </form>
        <form action="{{ route('admin.categories.delete', $category->id) }}" method="POST" onsubmit="return confirm('Delete this category?')">
          @csrf
          <button class="btn btn-sm btn-outline-danger">Delete</button>
        </form>
      </li>
    @empty
      <li class="list-group-item text-muted">No categories yet.</li>
    @endforelse
  </ul>
</div>
</body>
</html>
