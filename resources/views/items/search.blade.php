<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Search Items - ExchangeIT</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="{{ asset('css/app-theme.css') }}" rel="stylesheet">
</head>
<body>
  @include('external.nav')

  <div class="container mt-5">
    <h3 class="mb-4 brand-gradient fw-bold">Search & Filter Items</h3>

    <form action="{{ route('items.search.results') }}" method="GET" class="card border-0 shadow-sm p-4 mb-4">
      <div class="mb-3">
        <label for="category" class="form-label">Category (optional filter)</label>
        <input list="categoryList" name="category" id="category" class="form-control" placeholder="Choose or type category" value="{{ request('category') }}" />
        <datalist id="categoryList">
          @foreach($categories as $cat)
            <option value="{{ $cat->name }}"></option>
          @endforeach
        </datalist>
      </div>
      <div class="mb-3">
        <label for="keyword" class="form-label">Keyword / title search</label>
        <input type="text" name="keyword" id="keyword" class="form-control" placeholder="Search titles or descriptions" value="{{ request('keyword') }}" />
      </div>
      <button type="submit" class="btn btn-brand">Search</button>
    </form>
  </div>
</body>
</html>
