<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

use App\Models\User;
use App\Models\Item;
use App\Models\Category;
use App\Models\ExchangeRequest;
use App\Services\ItemSecurity;
use App\Services\NotificationService;
use App\Services\ProfileSecurity;
use RuntimeException;

class AdminController extends Controller
{
    private function isAdmin(): bool
    {
        return session()->has('user_id')
            && session()->has('session_token')
            && (bool) session('is_admin');
    }

    public function usersIndex(ProfileSecurity $profiles)
    {
        if (!$this->isAdmin()) {
            return redirect('/dashboard')->with('error', 'Unauthorized.');
        }

        $users = User::orderBy('id')->paginate(10);
        $users->getCollection()->transform(function (User $user) use ($profiles) {
            try {
                $plain = $profiles->decryptProfile($user);
                return (object) [
                    'id' => $user->id,
                    'name' => $plain['name'],
                    'email' => $plain['email'],
                    'phone' => $plain['phone'],
                    'nid_no' => $plain['nid_no'],
                    'address' => $plain['address'],
                    'created_at' => $user->created_at,
                    'is_admin' => $user->isAdmin(),
                ];
            } catch (RuntimeException $e) {
                return (object) [
                    'id' => $user->id,
                    'name' => '[integrity failed]',
                    'email' => '—',
                    'phone' => '—',
                    'nid_no' => '—',
                    'address' => '—',
                    'created_at' => $user->created_at,
                    'is_admin' => false,
                ];
            }
        });

        return view('admin.users.index', compact('users'));
    }

    public function deleteUser($id)
    {
        if (!$this->isAdmin()) {
            return redirect('/dashboard')->with('error', 'Unauthorized.');
        }

        $user = User::findOrFail($id);

        if ($user->isAdmin()) {
            return back()->with('error', 'Cannot delete the admin account.');
        }

        $user->delete();
        return back()->with('success', 'User deleted successfully.');
    }

    public function warnUser(Request $request, $id, NotificationService $notifications)
    {
        if (!$this->isAdmin()) {
            return redirect('/dashboard')->with('error', 'Unauthorized.');
        }

        $user = User::findOrFail($id);
        $message = $request->input('message') ?: 'Admin Warning: Please follow the community guidelines.';

        $notifications->push($user->id, $message);

        return back()->with('success', 'Warning sent to the user.');
    }

    public function itemsIndex(ItemSecurity $items, ProfileSecurity $profiles)
    {
        if (!$this->isAdmin()) {
            return redirect('/dashboard')->with('error', 'Unauthorized.');
        }

        $paginator = Item::with(['user', 'category'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        $paginator->getCollection()->transform(function ($item) use ($items, $profiles) {
            $hydrated = $items->hydrateTitle($item);
            if ($hydrated && $hydrated->user) {
                $hydrated->setRelation('user', $profiles->hydrateNameEmail($hydrated->user));
            }
            return $hydrated ?? $item;
        });

        return view('admin.items.index', ['items' => $paginator]);
    }

    public function categoriesIndex()
    {
        if (!$this->isAdmin()) {
            return redirect('/dashboard')->with('error', 'Unauthorized.');
        }

        $categories = Category::orderBy('name')->get();
        return view('admin.categories.index', compact('categories'));
    }

    public function storeCategory(Request $request)
    {
        if (!$this->isAdmin()) {
            return redirect('/dashboard')->with('error', 'Unauthorized.');
        }

        $request->validate(['name' => 'required|string|max:255|unique:categories,name']);
        Category::create(['name' => $request->name]);

        return back()->with('success', 'Category created.');
    }

    public function updateCategory(Request $request, $id)
    {
        if (!$this->isAdmin()) {
            return redirect('/dashboard')->with('error', 'Unauthorized.');
        }

        $category = Category::findOrFail($id);
        $request->validate(['name' => 'required|string|max:255|unique:categories,name,' . $category->id]);
        $category->name = $request->name;
        $category->save();

        return back()->with('success', 'Category updated.');
    }

    public function deleteCategory($id)
    {
        if (!$this->isAdmin()) {
            return redirect('/dashboard')->with('error', 'Unauthorized.');
        }

        $category = Category::findOrFail($id);
        if ($category->items()->exists()) {
            return back()->with('error', 'Cannot delete a category that still has items.');
        }

        $category->delete();
        return back()->with('success', 'Category deleted.');
    }

    public function stats()
    {
        if (!$this->isAdmin()) {
            return redirect('/dashboard')->with('error', 'Unauthorized.');
        }

        $usersPerMonth = User::selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as c")
            ->groupBy('ym')->pluck('c', 'ym');

        $itemsPerMonth = Item::selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as c")
            ->groupBy('ym')->pluck('c', 'ym');

        $tradedPerMonth = ExchangeRequest::whereNotNull('completed_at')
            ->selectRaw("DATE_FORMAT(completed_at, '%Y-%m') as ym, COUNT(*) as c")
            ->groupBy('ym')->pluck('c', 'ym');

        $pendingPerMonth = ExchangeRequest::where('status', 'pending')
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as c")
            ->groupBy('ym')->pluck('c', 'ym');

        $months = collect()
            ->merge($usersPerMonth->keys())
            ->merge($itemsPerMonth->keys())
            ->merge($tradedPerMonth->keys())
            ->merge($pendingPerMonth->keys())
            ->unique()
            ->sort()
            ->values();

        $rows = $months->map(function ($ym) use ($usersPerMonth, $itemsPerMonth, $tradedPerMonth, $pendingPerMonth) {
            $label = Carbon::createFromFormat('Y-m', $ym)->format('M Y');
            return [
                'ym'           => $ym,
                'month_label'  => $label,
                'total_users'  => (int) ($usersPerMonth[$ym] ?? 0),
                'total_items'  => (int) ($itemsPerMonth[$ym] ?? 0),
                'traded_items' => (int) ($tradedPerMonth[$ym] ?? 0),
                'pending_items'=> (int) ($pendingPerMonth[$ym] ?? 0),
            ];
        });

        return view('admin.stats.index', [
            'rows' => $rows,
        ]);
    }
}
