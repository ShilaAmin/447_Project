<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Item;
use App\Models\Category;
use App\Services\ItemSecurity;
use App\Services\NotificationService;
use RuntimeException;

class ItemController extends Controller
{
    public function create()
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        $categories = Category::all();
        return view('items.create', compact('categories'));
    }

    public function store(Request $request, ItemSecurity $security)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        $request->validate([
            'title'             => 'required|string|max:255',
            'description'       => 'required|string',
            'preferred_product' => 'required|string|max:255',
            'photo'             => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
            'category'          => 'required|string|max:255'
        ]);

        $category  = Category::firstOrCreate(['name' => $request->category]);
        $file = $request->file('photo');
        $photoPath = $file->store('uploads', 'public');

        $fields = $security->encryptFields([
            'title' => $request->title,
            'description' => $request->description,
            'preferred_product' => $request->preferred_product,
        ]);

        $meta = $security->encryptImageMeta([
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $photoPath,
            'uploaded_at' => now()->toIso8601String(),
        ]);

        Item::create(array_merge([
            'user_id'     => session('user_id'),
            'category_id' => $category->id,
            'photo'       => $photoPath,
        ], $fields, $meta));

        return redirect('/dashboard')->with('success', 'Item submitted successfully!');
    }

    public function searchForm()
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        $categories = Category::all();
        return view('items.search', compact('categories'));
    }

    public function searchResults(Request $request, ItemSecurity $security)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        $categoryName = $request->category;
        $keyword = trim((string) $request->keyword);

        $query = Item::with(['user', 'category']);

        if ($categoryName) {
            $category = Category::where('name', $categoryName)->first();
            if (!$category) {
                return redirect()->back()->with('error', 'Category not found.');
            }
            $query->where('category_id', $category->id);
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate(9)->withQueryString();

        $paginator->getCollection()->transform(function ($item) use ($security, $keyword) {
            $hydrated = $security->hydrateDecrypted($item);
            if (!$hydrated) {
                return null;
            }

            if ($keyword !== '') {
                $hay = strtolower($hydrated->title . ' ' . $hydrated->description);
                if (!str_contains($hay, strtolower($keyword))) {
                    return null;
                }
            }

            return $hydrated;
        });
        $paginator->setCollection($paginator->getCollection()->filter()->values());

        $userItems = Item::where('user_id', session('user_id'))->get()
            ->map(fn ($i) => $security->hydrateTitle($i))
            ->filter();

        $userRequestedItemIds = \App\Models\ExchangeRequest::where('requested_by', session('user_id'))
            ->pluck('item_id')->toArray();

        $isAdmin = (bool) session('is_admin');

        return view('items.search_results', [
            'items' => $paginator,
            'categoryName' => $categoryName ?: 'All',
            'keyword' => $keyword,
            'userItems' => $userItems,
            'userRequestedItemIds' => $userRequestedItemIds,
            'isAdmin' => $isAdmin,
        ]);
    }

    public function myItems(ItemSecurity $security)
{
    if (!session()->has('user_id')) {
        return redirect('/login')->with('error', 'Please login first.');
    }

    $paginator = Item::with('category')
        ->where('user_id', session('user_id'))
        ->orderBy('created_at', 'desc')
        ->paginate(12);

    $paginator->getCollection()->transform(function ($item) use ($security) {

        $hydrated = $security->hydrateDecrypted($item);

        if (!$hydrated) {
            $item->title = 'Integrity verification failed';
            $item->description = '';
            $item->preferred_product = '';

            return $item;
        }

        return $hydrated;
    });

    return view('items.my', ['items' => $paginator]);
}

    public function edit($id, ItemSecurity $security)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        $item = Item::findOrFail($id);

        if ($item->user_id != session('user_id')) {
            abort(403, 'Unauthorized');
        }

        try {
            $plain = $security->decryptFields($item);
        } catch (RuntimeException $e) {
            return redirect()->route('items.mine')->with('error', 'Item integrity check failed.');
        }

        $item->title = $plain['title'];
        $item->description = $plain['description'];
        $item->preferred_product = $plain['preferred_product'];

        $categories = Category::all();
        return view('items.edit', compact('item', 'categories'));
    }

    public function update(Request $request, $id, ItemSecurity $security)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        $item = Item::findOrFail($id);

        if ($item->user_id != session('user_id')) {
            abort(403, 'Unauthorized');
        }

        try {
            $security->assertItemIntegrity($item);
        } catch (RuntimeException $e) {
            return redirect()->route('items.mine')->with('error', 'Item integrity check failed.');
        }

        $request->validate([
            'title'             => 'required|string|max:255',
            'description'       => 'required|string',
            'preferred_product' => 'required|string|max:255',
            'photo'             => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'category'          => 'required|string|max:255'
        ]);

        $category = Category::firstOrCreate(['name' => $request->category]);

        if ($request->hasFile('photo')) {
            if ($item->photo) {
                Storage::disk('public')->delete($item->photo);
            }
            $file = $request->file('photo');
            $item->photo = $file->store('uploads', 'public');
            $meta = $security->encryptImageMeta([
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'path' => $item->photo,
                'uploaded_at' => now()->toIso8601String(),
            ]);
            $item->image_meta = $meta['image_meta'];
            $item->image_meta_mac = $meta['image_meta_mac'];
        }

        $fields = $security->encryptFields([
            'title' => $request->title,
            'description' => $request->description,
            'preferred_product' => $request->preferred_product,
        ]);

        $item->category_id = $category->id;
        $item->title = $fields['title'];
        $item->description = $fields['description'];
        $item->preferred_product = $fields['preferred_product'];
        $item->mac = $fields['mac'];
        $item->save();

        return redirect()->route('items.mine')->with('success', 'Item updated successfully.');
    }

    public function destroy($itemId, ItemSecurity $security, NotificationService $notifications)
    {
        $item = Item::with('user')->findOrFail($itemId);

        $isOwner = session('user_id') && $item->user_id == session('user_id');
        $isAdmin = (bool) session('is_admin');

        if (!$isOwner && !$isAdmin) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $security->assertItemIntegrity($item);
            $plain = $security->decryptFields($item);
            $title = $plain['title'];
        } catch (RuntimeException $e) {
            if (!$isAdmin) {
                return redirect()->back()->with('error', 'Item integrity check failed.');
            }
            $title = '[integrity-failed]';
        }

        if ($isAdmin && !$isOwner && $item->user) {
            $notifications->push(
                $item->user->id,
                "Your item '{$title}' was DELETED by Admin."
            );
        }

        if ($item->photo) {
            Storage::disk('public')->delete($item->photo);
        }

        $item->delete();
        return redirect()->back()->with('success', 'Item deleted successfully.');
    }
}
