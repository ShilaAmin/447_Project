<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

use App\Models\ExchangeRequest;
use App\Models\Item;
use App\Models\User;
use App\Models\TradeOffer;
use App\Services\ExchangeRequestSecurity;
use App\Services\ItemSecurity;
use App\Services\NotificationService;
use App\Services\ProfileSecurity;
use RuntimeException;

class ExchangeRequestController extends Controller
{
    public function store(Request $request, $id, ExchangeRequestSecurity $security, ItemSecurity $items, NotificationService $notifications)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'You must be logged in to request a trade.');
        }

        $userId = session('user_id');

        $request->validate([
            'offered_item_id' => 'nullable|exists:items,id'
        ]);

        $item = Item::with('user')->find($id);
        if (!$item) {
            return redirect()->back()->with('error', 'Item not found.');
        }

        if ($item->user_id == $userId) {
            return redirect()->back()->with('error', 'You cannot request your own item.');
        }

        $exists = ExchangeRequest::where('item_id', $id)
            ->where('requested_by', $userId)
            ->first();

        if ($exists) {
            return redirect()->back()->with('error', 'You have already requested this item.');
        }

        $status = 'pending';
        $encryptedDetails = $security->encryptDetails([
            'item_id' => (int) $id,
            'requested_by' => $userId,
            'offered_item_id' => $request->offered_item_id ? (int) $request->offered_item_id : null,
            'created_at' => now()->toIso8601String(),
        ]);

        $exchangeRequest = ExchangeRequest::create([
            'item_id' => $id,
            'requested_by' => $userId,
            'offered_item_id' => $request->offered_item_id ?? null,
            'encrypted_details' => $encryptedDetails,
            'mac' => $security->generateMac($encryptedDetails, $status),
            'status' => $status,
        ]);

        $plainItem = $items->hydrateTitle($item);
        $itemTitle = $plainItem?->title ?? 'item';
        $requesterName = session('user_name') ?? 'A user';

        $offeredTitle = null;
        if (!empty($exchangeRequest->offered_item_id)) {
            $offered = Item::find($exchangeRequest->offered_item_id);
            $offeredTitle = $offered ? ($items->hydrateTitle($offered)?->title) : null;
        }

        $message = "New trade request for '{$itemTitle}' from {$requesterName}.";
        if ($offeredTitle) {
            $message .= " Offered item: {$offeredTitle}.";
        }
        $message .= " Open your Requests page to review.";

        $notifications->push($item->user->id, $message);

        return redirect()->back()->with('success', 'Trade request sent successfully!');
    }

    public function index(ItemSecurity $items, ExchangeRequestSecurity $security, ProfileSecurity $profiles)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'You must be logged in to view requests.');
        }

        $userId = session('user_id');

        $requests = ExchangeRequest::whereHas('item', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->with(['item', 'requester', 'offeredItem'])
          ->orderBy('created_at', 'desc')
          ->paginate(10);

        $requests->getCollection()->transform(function ($er) use ($security, $items, $profiles) {
            if ($er->encrypted_details && $er->mac && !$security->verifyMac($er->encrypted_details, $er->status, $er->mac, $er->completion_payload)) {
                return $er;
            }
            if ($er->item) {
                $er->setRelation('item', $items->hydrateTitle($er->item) ?? $er->item);
            }
            if ($er->offeredItem) {
                $er->setRelation('offeredItem', $items->hydrateTitle($er->offeredItem) ?? $er->offeredItem);
            }
            if ($er->requester) {
                $er->setRelation('requester', $profiles->hydrateName($er->requester));
            }
            return $er;
        });

        return view('requests.index', compact('requests'));
    }

    public function myRequests(ItemSecurity $items, ExchangeRequestSecurity $security)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'You must be logged in to view your requests.');
        }

        $userId = session('user_id');

        $requests = ExchangeRequest::with(['item', 'offeredItem'])
            ->where('requested_by', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        $requests->getCollection()->transform(function ($er) use ($items, $security) {
            if ($er->encrypted_details && $er->mac && !$security->verifyMac($er->encrypted_details, $er->status, $er->mac, $er->completion_payload)) {
                return $er;
            }
            if ($er->item) {
                $er->setRelation('item', $items->hydrateTitle($er->item) ?? $er->item);
            }
            if ($er->offeredItem) {
                $er->setRelation('offeredItem', $items->hydrateTitle($er->offeredItem) ?? $er->offeredItem);
            }
            return $er;
        });

        return view('requests.my', compact('requests'));
    }

    public function accept($id, ExchangeRequestSecurity $security, ItemSecurity $items, NotificationService $notifications, ProfileSecurity $profiles)
    {
        $exchangeRequest = ExchangeRequest::with(['item.user'])->findOrFail($id);

        if (!session()->has('user_id') || $exchangeRequest->item->user_id != session('user_id')) {
            return redirect()->back()->with('error', 'Unauthorized.');
        }

        try {
            $security->assertIntegrity(
                $exchangeRequest->encrypted_details,
                $exchangeRequest->status,
                $exchangeRequest->mac,
                $exchangeRequest->completion_payload
            );
        } catch (RuntimeException $e) {
            return redirect()->back()->with('error', 'Request integrity check failed.');
        }

        $exchangeRequest->status = 'accepted';
        if (Schema::hasColumn('exchange_requests', 'accepted_at')) {
            $exchangeRequest->accepted_at = now();
        }
        $exchangeRequest->mac = $security->generateMac(
            $exchangeRequest->encrypted_details,
            $exchangeRequest->status,
            $exchangeRequest->completion_payload
        );
        $exchangeRequest->save();

        $owner = $exchangeRequest->item->user;
        try {
            $ownerPlain = $profiles->decryptProfile($owner);
            $ownerName = $ownerPlain['name'];
            $ownerEmail = $ownerPlain['email'];
            $ownerPhone = $ownerPlain['phone'];
        } catch (RuntimeException $e) {
            $ownerName = session('user_name') ?? 'Owner';
            $ownerEmail = '—';
            $ownerPhone = '—';
        }

        $itemTitle = $items->hydrateTitle($exchangeRequest->item)?->title ?? 'item';

        $notifications->push(
            $exchangeRequest->requested_by,
            "Your offer for '{$itemTitle}' was ACCEPTED.\nOwner: {$ownerName}\nEmail: {$ownerEmail}\nPhone: {$ownerPhone}"
        );

        return redirect()->back()->with('success', 'Request accepted and requester notified with contact info.');
    }

    public function complete($id, ExchangeRequestSecurity $security, ItemSecurity $items, NotificationService $notifications)
    {
        $exchangeRequest = ExchangeRequest::with('item.user')->findOrFail($id);

        if (!session()->has('user_id') || !$exchangeRequest->item || $exchangeRequest->item->user_id != session('user_id')) {
            return redirect()->back()->with('error', 'Unauthorized.');
        }

        try {
            $security->assertIntegrity(
                $exchangeRequest->encrypted_details,
                $exchangeRequest->status,
                $exchangeRequest->mac,
                $exchangeRequest->completion_payload
            );
        } catch (RuntimeException $e) {
            return redirect()->back()->with('error', 'Request integrity check failed.');
        }

        $plainItem = $items->hydrateTitle($exchangeRequest->item);
        $itemTitle = $plainItem?->title ?? 'item';
        $previousStatus = $exchangeRequest->status;

        $completionPayload = $security->encryptCompletion([
            'exchange_request_id' => $exchangeRequest->id,
            'item_id' => $exchangeRequest->item_id,
            'item_title' => $itemTitle,
            'owner_id' => $exchangeRequest->item->user_id,
            'requester_id' => $exchangeRequest->requested_by,
            'offered_item_id' => $exchangeRequest->offered_item_id,
            'completed_at' => now()->toIso8601String(),
            'previous_status' => $previousStatus,
        ]);

        $exchangeRequest->status = 'completed';
        $exchangeRequest->completion_payload = $completionPayload;
        if (Schema::hasColumn('exchange_requests', 'completed_at')) {
            $exchangeRequest->completed_at = now();
        }
        $exchangeRequest->mac = $security->generateMac(
            $exchangeRequest->encrypted_details,
            $exchangeRequest->status,
            $exchangeRequest->completion_payload
        );
        $exchangeRequest->save();

        $ownerName = session('user_name') ?? 'Owner';
        $notifications->push(
            $exchangeRequest->requested_by,
            "Trade for '{$itemTitle}' has been COMPLETED by {$ownerName}. Item removed from listings."
        );

        if ($exchangeRequest->item) {
            $exchangeRequest->item->delete();
        }

        return redirect()->back()->with('success', 'Trade completed, item removed, requester notified.');
    }

    public function decline($id, ExchangeRequestSecurity $security, ItemSecurity $items, NotificationService $notifications)
    {
        $exchangeRequest = ExchangeRequest::with(['item.user', 'requester'])->find($id);
        if (!$exchangeRequest) {
            return redirect()->back()->with('error', 'Request not found.');
        }

        if (!session()->has('user_id') || $exchangeRequest->item->user_id != session('user_id')) {
            return redirect()->back()->with('error', 'Unauthorized.');
        }

        try {
            $security->assertIntegrity(
                $exchangeRequest->encrypted_details,
                $exchangeRequest->status,
                $exchangeRequest->mac,
                $exchangeRequest->completion_payload
            );
        } catch (RuntimeException $e) {
            return redirect()->back()->with('error', 'Request integrity check failed.');
        }

        $exchangeRequest->status = 'declined';
        $exchangeRequest->mac = $security->generateMac(
            $exchangeRequest->encrypted_details,
            $exchangeRequest->status,
            $exchangeRequest->completion_payload
        );
        $exchangeRequest->save();

        $itemTitle = $items->hydrateTitle($exchangeRequest->item)?->title ?? 'item';
        $ownerName = session('user_name') ?? 'Owner';

        $notifications->push(
            $exchangeRequest->requested_by,
            "Your trade request for '{$itemTitle}' has been DECLINED by {$ownerName}."
        );

        return redirect()->back()->with('success', 'Request declined and requester notified!');
    }

    public function negotiate($id, ItemSecurity $items, ExchangeRequestSecurity $security, ProfileSecurity $profiles)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'You must be logged in.');
        }

        $userId = session('user_id');
        $exchangeRequest = ExchangeRequest::with(['item.user', 'requester', 'offeredItem'])->findOrFail($id);

        if ($exchangeRequest->encrypted_details && $exchangeRequest->mac) {
            try {
                $security->assertIntegrity(
                    $exchangeRequest->encrypted_details,
                    $exchangeRequest->status,
                    $exchangeRequest->mac,
                    $exchangeRequest->completion_payload
                );
            } catch (RuntimeException $e) {
                return redirect()->back()->with('error', 'Request integrity check failed.');
            }
        }

        if ($exchangeRequest->requested_by !== $userId && $exchangeRequest->item->user_id !== $userId) {
            return redirect()->back()->with('error', 'Unauthorized.');
        }

        if (!Schema::hasTable('trade_offers')) {
            return redirect()->back()->with('error', 'Negotiation feature not initialized. Please run: php artisan migrate');
        }

        if ($exchangeRequest->item) {
            $hydratedItem = $items->hydrateTitle($exchangeRequest->item) ?? $exchangeRequest->item;
            if ($hydratedItem->relationLoaded('user') || $hydratedItem->user) {
                $hydratedItem->setRelation('user', $profiles->hydrateName($hydratedItem->user));
            }
            $exchangeRequest->setRelation('item', $hydratedItem);
        }

        if ($exchangeRequest->requester) {
            $exchangeRequest->setRelation('requester', $profiles->hydrateName($exchangeRequest->requester));
        }

        $offers = TradeOffer::with(['fromUser', 'toUser', 'offeredItem'])
            ->where('exchange_request_id', $exchangeRequest->id)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($offer) use ($profiles, $items) {
                if ($offer->fromUser) {
                    $offer->setRelation('fromUser', $profiles->hydrateName($offer->fromUser));
                }
                if ($offer->toUser) {
                    $offer->setRelation('toUser', $profiles->hydrateName($offer->toUser));
                }
                if ($offer->offeredItem) {
                    $offer->setRelation('offeredItem', $items->hydrateTitle($offer->offeredItem) ?? $offer->offeredItem);
                }
                return $offer;
            });

        $myItems = Item::where('user_id', $userId)->orderBy('created_at', 'desc')->get()
            ->map(fn ($i) => $items->hydrateTitle($i))
            ->filter();

        return view('requests.negotiate', compact('exchangeRequest', 'offers', 'myItems', 'userId'));
    }

    public function sendOffer(Request $request, $id, NotificationService $notifications, ItemSecurity $items)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'You must be logged in.');
        }
        $userId = session('user_id');

        $exchangeRequest = ExchangeRequest::with(['item.user', 'requester'])->findOrFail($id);

        $isRequester = ($exchangeRequest->requested_by === $userId);
        $isOwner = ($exchangeRequest->item->user_id === $userId);
        if (!$isRequester && !$isOwner) {
            return redirect()->back()->with('error', 'Unauthorized.');
        }

        $request->validate([
            'offered_item_id' => 'nullable|exists:items,id',
            'cash_adjustment' => 'nullable|numeric|min:0',
            'message' => 'nullable|string|max:2000',
        ]);

        $toUserId = $isOwner ? $exchangeRequest->requested_by : $exchangeRequest->item->user_id;

        if ($request->filled('offered_item_id')) {
            $owned = Item::where('id', $request->offered_item_id)
                ->where('user_id', $userId)->exists();
            if (!$owned) {
                return back()->with('error', 'You can only offer your own item.');
            }
        }

        TradeOffer::create([
            'exchange_request_id' => $exchangeRequest->id,
            'from_user_id'        => $userId,
            'to_user_id'          => $toUserId,
            'offered_item_id'     => $request->offered_item_id ?: null,
            'cash_adjustment'     => $request->cash_adjustment ?: null,
            'message'             => $request->message,
            'status'              => 'pending',
        ]);

        $itemTitle = $items->hydrateTitle($exchangeRequest->item)?->title ?? 'item';
        $notifications->push($toUserId, "New offer on trade '{$itemTitle}'.");

        return redirect()->route('requests.negotiate', $exchangeRequest->id)
            ->with('success', 'Offer sent.');
    }

    public function acceptOffer($offerId, ExchangeRequestSecurity $security, NotificationService $notifications, ItemSecurity $items)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'You must be logged in.');
        }
        $userId = session('user_id');

        $offer = TradeOffer::with(['exchangeRequest.item.user', 'fromUser', 'toUser'])->findOrFail($offerId);

        if ($offer->to_user_id !== $userId) {
            return back()->with('error', 'Unauthorized.');
        }

        if ($offer->status !== 'pending') {
            return back()->with('error', 'Offer already handled.');
        }

        DB::transaction(function () use ($offer, $security, $notifications, $items) {
            $offer->status = 'accepted';
            $offer->save();

            TradeOffer::where('exchange_request_id', $offer->exchange_request_id)
                ->where('id', '!=', $offer->id)
                ->where('status', 'pending')
                ->update(['status' => 'declined']);

            $er = $offer->exchangeRequest;
            if ($er->encrypted_details && $er->mac) {
                $security->assertIntegrity($er->encrypted_details, $er->status, $er->mac, $er->completion_payload);
            }

            $er->status = 'accepted';
            if (Schema::hasColumn('exchange_requests', 'accepted_at')) {
                $er->accepted_at = now();
            }
            if ($er->encrypted_details) {
                $er->mac = $security->generateMac($er->encrypted_details, $er->status, $er->completion_payload);
            }
            $er->save();

            $itemTitle = $items->hydrateTitle($er->item)?->title ?? 'item';
            $notifications->push($offer->from_user_id, "Your offer for '{$itemTitle}' was ACCEPTED.");
        });

        return back()->with('success', 'Offer accepted. The trade is now accepted; you may proceed to complete it after meeting.');
    }

    public function declineOffer($offerId, NotificationService $notifications, ItemSecurity $items)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'You must be logged in.');
        }
        $userId = session('user_id');

        $offer = TradeOffer::with(['exchangeRequest.item'])->findOrFail($offerId);

        if ($offer->to_user_id !== $userId) {
            return back()->with('error', 'Unauthorized.');
        }

        if ($offer->status !== 'pending') {
            return back()->with('error', 'Offer already handled.');
        }

        $offer->status = 'declined';
        $offer->save();

        $itemTitle = $items->hydrateTitle($offer->exchangeRequest->item)?->title ?? 'item';
        $notifications->push($offer->from_user_id, "Your offer for '{$itemTitle}' was DECLINED.");

        return back()->with('success', 'Offer declined.');
    }
}
