<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;

class NotificationController extends Controller
{
    public function index(NotificationService $notifications)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        $paginator = Notification::where('user_id', session('user_id'))
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        $paginator->getCollection()->transform(function ($n) use ($notifications) {
            $hydrated = $notifications->hydrate($n);
            if (!$hydrated) {
                $n->setAttribute('message', '[Integrity check failed]');
            }
            return $n;
        });

        return view('notifications.index', ['notifications' => $paginator]);
    }

    public function markAsRead($id)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        $notif = Notification::where('id', $id)
            ->where('user_id', session('user_id'))
            ->firstOrFail();

        $notif->read = 1;
        $notif->save();

        return back()->with('success', 'Notification marked as read.');
    }

    public function open($id)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        $notif = Notification::where('id', $id)
            ->where('user_id', session('user_id'))
            ->firstOrFail();

        if (!$notif->read) {
            $notif->read = 1;
            $notif->save();
        }

        return redirect()->route('notifications.index')->with('success', 'Notification opened.');
    }

    public function markAll()
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        Notification::where('user_id', session('user_id'))
            ->where('read', 0)
            ->update(['read' => 1]);

        return back()->with('success', 'All notifications marked as read.');
    }
}
