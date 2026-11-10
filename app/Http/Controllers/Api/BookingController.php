<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Review;
use App\Models\Trail;
use App\Models\User;
use App\Support\ReviewMedia;
use App\Support\SpamProtection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Hiker side of the reservation workflow. */
class BookingController extends Controller
{
    public function index(Request $request)
    {
        $bookings = Booking::with(['trail:id,name,location,category,image_url', 'guide:id,name,phone', 'logs', 'review'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return response()->json($bookings);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'trail_id' => 'required|exists:trails,id',
            'guide_id' => 'required|exists:users,id',
            'trek_date' => 'required|date|after_or_equal:today',
            'group_size' => 'required|integer|min:1|max:60',
            'itinerary' => 'nullable|string|max:2000',
            'special_requirements' => 'nullable|string|max:2000',
        ]);

        $guide = User::where('id', $data['guide_id'])->where('role', 'guide')->where('is_active', true)->first();
        if (! $guide) {
            return response()->json(['message' => 'Please choose an available guide.'], 422);
        }

        $restriction = SpamProtection::checkRestriction($request->user(), 'hiker', hash('sha256', $data['trail_id'].'|'.$data['guide_id'].'|'.$data['trek_date'].'|'.$data['group_size']));
        if ($restriction !== null) {
            return response()->json($restriction, 429);
        }

        return DB::transaction(function () use ($data, $guide, $request) {
            // Lock the trail row so two hikers cannot take the last slots at the same time
            $trail = Trail::where('status', 'verified')->where('is_active', true)
                ->lockForUpdate()->find($data['trail_id']);

            if (! $trail) {
                return response()->json(['message' => 'This trail is not available for booking.'], 404);
            }

            $taken = (int) Booking::where('trail_id', $trail->id)
                ->whereDate('trek_date', $data['trek_date'])
                ->where('status', '!=', 'Cancelled')
                ->sum('group_size');

            if ($taken + $data['group_size'] > $trail->max_daily_visitors) {
                $left = max(0, $trail->max_daily_visitors - $taken);

                return response()->json([
                    'message' => "Not enough slots on that date. Only {$left} slot(s) left (daily limit: {$trail->max_daily_visitors}).",
                ], 422);
            }

            $booking = Booking::create([
                'booking_code' => 'HV-'.date('Ymd').'-'.strtoupper(Str::random(5)),
                'user_id' => $request->user()->id,
                'guide_id' => $guide->id,
                'trail_id' => $trail->id,
                'trek_date' => $data['trek_date'],
                'group_size' => $data['group_size'],
                'itinerary' => $data['itinerary'] ?? null,
                'special_requirements' => $data['special_requirements'] ?? null,
                'total_cost' => ($trail->entrance_fee * $data['group_size']) + $trail->guide_fee,
                'status' => 'Pending',
            ]);
            $booking->logs()->create(['status' => 'Pending', 'changed_by' => $request->user()->id]);

            return response()->json($booking->load(['trail:id,name,location', 'guide:id,name,phone', 'logs']), 201);
        });
    }

    public function cancel(Request $request, $id)
    {
        $booking = Booking::where('user_id', $request->user()->id)->findOrFail($id);

        if (! in_array($booking->status, ['Pending', 'Approved'], true)) {
            return response()->json(['message' => 'Only Pending or Approved requests can be cancelled.'], 422);
        }

        $booking->changeStatus('Cancelled', $request->user()->id);

        return response()->json($booking->load(['trail:id,name,location,category,image_url', 'logs', 'review']));
    }

    public function deleteCompleted(Request $request, $id)
    {
        $booking = Booking::where('user_id', $request->user()->id)->findOrFail($id);
        if ($booking->status !== 'Completed') {
            return response()->json(['message' => 'Only completed bookings can be deleted.'], 422);
        }

        $trailId = $booking->trail_id;
        $review = $booking->review;
        $photoPaths = ReviewMedia::paths($review?->photos);

        DB::transaction(function () use ($booking, $review, $trailId): void {
            $review?->delete();
            $booking->delete();

            Trail::whereKey($trailId)->update([
                'avg_rating' => round((float) Review::where('trail_id', $trailId)->avg('rating'), 2),
                'reviews_count' => Review::where('trail_id', $trailId)->count(),
            ]);
        });

        Storage::disk('public')->delete($photoPaths);

        return response()->json(['message' => 'Completed booking and its review deleted.']);
    }

    public function review(Request $request, $id)
    {
        $booking = Booking::where('user_id', $request->user()->id)->findOrFail($id);

        if ($booking->status !== 'Completed') {
            return response()->json(['message' => 'You can only review a trek after it is marked Completed.'], 422);
        }
        if ($booking->review()->exists()) {
            return response()->json(['message' => 'You already reviewed this trek.'], 422);
        }

        $data = $request->validate([
            'rating' => 'required|integer|between:1,5',
            'comment' => 'nullable|string|max:1000',
            'photos' => 'nullable|array|max:5',
            'photos.*' => 'image|mimes:jpeg,png,webp|max:5120',
        ]);

        $photoUrls = [];
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $path = $photo->store('reviews', 'public');
                $photoUrls[] = Storage::disk('public')->url($path);
            }
        }

        $review = Review::create([
            'booking_id' => $booking->id,
            'trail_id' => $booking->trail_id,
            'user_id' => $request->user()->id,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
            'photos' => $photoUrls,
        ]);

        // Recompute the trail's average rating (feeds the Rating Score in the ranking)
        Trail::whereKey($booking->trail_id)->update([
            'avg_rating' => round((float) Review::where('trail_id', $booking->trail_id)->avg('rating'), 2),
            'reviews_count' => Review::where('trail_id', $booking->trail_id)->count(),
        ]);

        return response()->json($review, 201);
    }
}
