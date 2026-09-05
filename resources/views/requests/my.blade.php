<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My Trade Requests - ExchangeIT</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="{{ asset('css/app-theme.css') }}" rel="stylesheet">
</head>
<body>
@include('external.nav')

<div class="container mt-4">
  <h3 class="mb-3">My Trade Requests</h3>

  @if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
  @if(session('error'))   <div class="alert alert-danger">{{ session('error') }}</div> @endif

  @if($requests->isEmpty())
    <div class="alert alert-info">You haven’t sent any trade requests yet.</div>
  @else
    <div class="list-group">
      @foreach($requests as $req)
        <div class="list-group-item mb-2">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div>
                Requested item: <strong>{{ $req->item?->title ?? 'Deleted Item' }}</strong>
              </div>
              @if($req->offered_item_id)
                <div class="small text-muted">Your offer: {{ $req->offeredItem?->title ?? '—' }}</div>
              @endif
              <div class="mt-1">
                <span class="badge 
                  {{ $req->status === 'accepted' ? 'text-bg-success' : 
                     ($req->status === 'declined' ? 'text-bg-danger' : 
                     ($req->status === 'completed' ? 'text-bg-primary' : 'text-bg-secondary')) }}">
                  {{ ucfirst($req->status) }}
                </span>
                <small class="text-muted ms-2">Sent {{ $req->created_at?->diffForHumans() }}</small>
              </div>
            </div>

            <div class="d-flex gap-2">
              {{-- NEW: Negotiate thread link (available to requester always) --}}
              <a href="{{ route('requests.negotiate', $req->id) }}" class="btn btn-outline-primary btn-sm">
                Negotiate
              </a>
            </div>
          </div>
        </div>
        @endforeach
    </div>
    <div class="mt-3">{{ $requests->links() }}</div>
  @endif
</div>
</body>
</html>
