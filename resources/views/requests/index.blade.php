<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Trade Requests - ExchangeIT</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="{{ asset('css/app-theme.css') }}" rel="stylesheet">
</head>
<body>
  @include('external.nav')

  <div class="container mt-5">
    <h3>Incoming Trade Requests</h3>

    @if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
    @if(session('error'))   <div class="alert alert-danger">{{ session('error') }}</div> @endif

    @if($requests->isEmpty())
      <div class="alert alert-info">No trade requests found.</div>
    @else
      <div class="list-group">
        @foreach($requests as $req)
          <div class="list-group-item mb-2">
            <p class="mb-1">
              <strong>{{ $req->requester->name }}</strong> wants your item:
              <strong>{{ $req->item->title }}</strong>
            </p>
            @if($req->offered_item_id)
              <p class="mb-1">Offered item: <strong>{{ $req->offeredItem->title }}</strong></p>
            @endif

            <small class="text-muted d-block mb-2">Status: {{ ucfirst($req->status) }}</small>

            <div class="d-flex flex-wrap gap-2">
              {{-- NEW: Negotiate thread link (available to owner always) --}}
              <a href="{{ route('requests.negotiate', $req->id) }}" class="btn btn-outline-primary btn-sm">
                Negotiate
              </a>

              @if($req->status === 'pending')
                <form action="{{ url('/requests/' . $req->id . '/accept') }}" method="POST" class="d-inline">
                  @csrf
                  <button class="btn btn-success btn-sm">Accept</button>
                </form>
                <form action="{{ url('/requests/' . $req->id . '/decline') }}" method="POST" class="d-inline">
                  @csrf
                  <button class="btn btn-danger btn-sm">Decline</button>
                </form>
              @elseif($req->status === 'accepted')
                <form action="{{ url('/requests/' . $req->id . '/complete') }}" method="POST" class="d-inline">
                  @csrf
                  <button class="btn btn-primary btn-sm">Mark Complete</button>
                </form>
              @endif
            </div>
          </div>
        @endforeach
      </div>
      <div class="mt-3">{{ $requests->links() }}</div>
    @endif
  </div>
</body>
</html>
