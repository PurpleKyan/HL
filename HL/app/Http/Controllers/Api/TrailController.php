<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Review;
use App\Models\Trail;
use App\Models\User;
use App\Support\TrailGallery;
use Illuminate\Http\Request;

class TrailController extends Controller
{
    /** Public list with search + filters (verified & active trails only). */
    public function index(Request $request)
    {
        $q = Trail::query()->where('status', 'verified')->where('is_active', true);

        if ($s = $request->query('search')) {
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', "%{$s}%")
                    ->orWhere('location', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
            });
        }
        foreach (['location', 'category', 'difficulty', 'trip_type'] as $field) {
            if ($v = $request->query($field)) {
                $q->where($field, $v);
            }
        }
        if ($b = $request->query('max_budget')) {
            // cost for one hiker = entrance fee + guide fee
            $q->whereRaw('(entrance_fee + guide_fee) <= ?', [(float) $b]);
        }

        switch ($request->query('sort')) {
            case 'rating':
                $q->orderByDesc('avg_rating')->orderByDesc('reviews_count');
                break;
            case 'price_asc':
                $q->orderByRaw('(entrance_fee + guide_fee) asc');
                break;
            case 'price_desc':
                $q->orderByRaw('(entrance_fee + guide_fee) desc');
                break;
            default:
                $q->orderBy('name');
        }

        return response()->json($q->get());
    }

    public function locations()
    {
        $locations = Trail::where('status', 'verified')->where('is_active', true)
            ->distinct()->orderBy('location')->pluck('location');

        return response()->json($locations);
    }

    public function show($id)
    {
        $trail = Trail::with('owner:id,name,phone')
            ->where('status', 'verified')->where('is_active', true)
            ->findOrFail($id);

        return response()->json(TrailGallery::attach($trail));
    }

    public function guides()
    {
        $guides = User::where('role', 'guide')
            ->where('is_active', true)
            ->where('guide_visible', true)
            ->where('account_status', 'approved')
            ->get(['id', 'name', 'phone']);

        $rankedGuides = $guides->map(function (User $guide) {
            $bookingIds = Booking::where('guide_id', $guide->id)
                ->where('status', 'Completed')
                ->pluck('id');

            $reviews = Review::whereIn('booking_id', $bookingIds)->get(['rating']);
            $reviewCount = $reviews->count();
            $averageRating = $reviewCount > 0
                ? round((float) $reviews->avg('rating'), 1)
                : 0.0;

            return [
                'id' => $guide->id,
                'name' => $guide->name,
                'phone' => $guide->phone,
                'avg_rating' => $averageRating,
                'review_count' => $reviewCount,
                'completed_bookings' => $bookingIds->count(),
            ];
        })->sort(function (array $a, array $b) {
            foreach (['completed_bookings', 'avg_rating', 'review_count'] as $field) {
                if ($a[$field] !== $b[$field]) {
                    return $b[$field] <=> $a[$field];
                }
            }
            return strcmp(strtolower($a['name']), strtolower($b['name']));
        })->values();

        return response()->json($rankedGuides);
    }

    public function reviews($id)
    {
        $reviews = Review::with('user:id,name')->where('trail_id', $id)->latest()->get();

        return response()->json($reviews);
    }
}
