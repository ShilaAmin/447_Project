<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Items in {{ $categoryName }} - ExchangeIT</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="{{ asset('css/app-theme.css') }}" rel="stylesheet">
  <style>
    .item-card-img{ height: 180px; object-fit: cover; }
    .line-clamp-3{
      display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden;
    }
    .badge-soft{
      background: rgba(6,182,212,.10);
      color:#0e7490;
      border:1px solid rgba(6,182,212,.15);
    }
  </style>
</head>
<body>
  @include('external.nav')

  @php
    // ensure $isAdmin is set (controller already passes it, but keep fallback)
    $isAdmin = isset($isAdmin) ? $isAdmin : (bool) session('is_admin');
  @endphp

  <div class="container mt-5">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
      <h3 class="mb-0">
        Search results
        @if(!empty($categoryName) && $categoryName !== 'All')
          in <span class="brand-gradient fw-bold">{{ $categoryName }}</span>
        @endif
        @if(!empty($keyword))
          for “{{ $keyword }}”
        @endif
      </h3>
      <a href="{{ route('items.search.form') }}" class="btn btn-outline-secondary btn-sm">Back to Search</a>
    </div>

    @if($items->isEmpty())
      <div class="alert alert-info">No items found in this category.</div>
    @else
      <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-4">
        @foreach($items as $item)
          <div class="col">
            <div class="card h-100 border-0 shadow-sm">
              @if($item->photo)
                <img src="{{ asset('storage/' . $item->photo) }}" class="card-img-top item-card-img" alt="Item photo" />
              @endif

              <div class="card-body d-flex flex-column">
                <div class="mb-2">
                  <h5 class="card-title mb-1">{{ $item->title }}</h5>
                  <div class="small text-muted">Preferred exchange:</div>
                  <span class="badge badge-soft">{{ $item->preferred_product }}</span>
                </div>

                <p class="card-text mt-3 line-clamp-3">{{ $item->description }}</p>

                <div class="mt-auto">
                  {{-- Owner actions --}}
                  @if(session('user_id') == $item->user_id)
                    <form method="POST" action="{{ route('items.destroy', $item->id) }}" class="d-inline">
                      @csrf @method('DELETE')
                      <button type="submit" class="btn btn-sm btn-outline-warning">Delete</button>
                    </form>
                  @endif

                  {{-- Admin actions (even if not owner) --}}
                  @if(!empty($isAdmin) && $isAdmin)
                    <form method="POST" action="{{ route('items.destroy', $item->id) }}" class="d-inline ms-1">
                      @csrf @method('DELETE')
                      <button type="submit" class="btn btn-sm btn-outline-danger">Admin Delete</button>
                    </form>
                  @endif
                </div>
              </div>

              {{-- Request section (only if logged in and not the owner) --}}
              @if(session()->has('user_id') && session('user_id') != $item->user_id)
                <div class="card-footer bg-transparent">
                  @php
                    // $userRequestedItemIds is passed from controller
                    $alreadyRequested = in_array($item->id, $userRequestedItemIds ?? []);
                  @endphp

                  @if($alreadyRequested)
                    <button class="btn btn-secondary btn-sm w-100" disabled>Already Requested</button>
                  @else
                    <form action="{{ url('/items/' . $item->id . '/request') }}" method="POST" class="vstack gap-2">
                      @csrf
                      <div>
                        <label class="form-label small mb-1">Offer one of your items (optional)</label>
                        <select name="offered_item_id" class="form-select form-select-sm">
                          <option value="">-- None --</option>
                          @foreach($userItems as $userItem)
                            <option value="{{ $userItem->id }}">{{ $userItem->title }}</option>
                          @endforeach
                        </select>
                      </div>
                      <button type="submit" class="btn btn-brand btn-sm">Request Trade</button>
                    </form>
                  @endif
                </div>
              @endif
            </div>
          </div>
        @endforeach
      </div>
      <div class="mt-4">{{ $items->links() }}</div>
    @endif
  </div>
</body>
</html>
