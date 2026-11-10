<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Review;
use App\Models\Trail;
use App\Support\Reports;
use App\Support\ReviewMedia;
use App\Support\SpamProtection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Guide / Tourism Officer: manage own trails and incoming requests. */
class GuideController extends Controller
{
    private function trailRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'category' => 'required|in:Mountain,Waterfall,Eco-Park',
            'difficulty' => 'required|in:Easy,Moderate,Hard',
            'trip_type' => 'required|in:Day Hike,Overnight',
            'description' => 'required|string|max:5000',
            'elevation' => 'required|integer|min:0|max:10000',
            'distance_km' => 'required|numeric|min:0|max:1000',
            'duration_hours' => 'required|numeric|min:0|max:500',
            'entrance_fee' => 'required|numeric|min:0|max:1000000',
            'guide_fee' => 'required|numeric|min:0|max:1000000',
            'permits' => 'nullable|string|max:2000',
            'max_daily_visitors' => 'required|integer|min:1|max:5000',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'image_url' => 'nullable|url|max:500',
            'features' => 'nullable|array',
            'features.*' => 'string|max:50',
            'safety_advisory' => 'nullable|string|max:3000',
            'lnt_notes' => 'nullable|string|max:3000',
        ];
    }

    private function bookingsQuery(Request $request)
    {
        return Booking::with(['trail:id,name,location,user_id', 'guide:id,name,phone', 'user:id,name,email,phone', 'logs', 'review'])
            ->where(function ($q) use ($request) {
                $q->where('guide_id', $request->user()->id)
                    ->orWhere(function ($legacy) use ($request) {
                        $legacy->whereNull('guide_id')->whereHas('trail', fn ($trail) => $trail->where('user_id', $request->user()->id));
                    });
            });
    }

    public function trails(Request $request)
    {
        return response()->json(Trail::latest()->get());
    }

    public function storeTrail(Request $request)
    {
        $data = $request->validate($this->trailRules());

        $restriction = SpamProtection::checkRestriction($request->user(), 'guide', hash('sha256', $data['name'].'|'.$data['location'].'|'.$data['description']));
        if ($restriction !== null) {
            return response()->json($restriction, 429);
        }

        $data['user_id'] = $request->user()->id;
        $data['status'] = 'pending'; // must be verified by the administrator

        return response()->json(Trail::create($data), 201);
    }

    public function updateTrail(Request $request, $id)
    {
        $trail = Trail::where('user_id', $request->user()->id)->findOrFail($id);
        $trail->update($request->validate($this->trailRules()));

        return response()->json($trail->fresh());
    }

    public function visibility(Request $request)
    {
        return response()->json(['guide_visible' => (bool) $request->user()->guide_visible]);
    }

    public function toggleVisibility(Request $request)
    {
        $guide = $request->user();
        $guide->update(['guide_visible' => ! $guide->guide_visible]);

        return response()->json(['guide_visible' => (bool) $guide->guide_visible]);
    }

    public function destroyTrail(Request $request, $id)
    {
        $trail = Trail::where('user_id', $request->user()->id)->findOrFail($id);

        if ($trail->bookings()->exists()) {
            return response()->json(['message' => 'This trail already has bookings, so it cannot be deleted. Deactivate it instead.'], 422);
        }
        $trail->delete();

        return response()->json(['message' => 'Trail deleted.']);
    }

    public function bookings(Request $request)
    {
        $q = $this->bookingsQuery($request);
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return response()->json($q->orderByDesc('id')->get());
    }

    public function updateBookingStatus(Request $request, $id)
    {
        $data = $request->validate(['status' => 'required|in:Approved,Cancelled,Completed']);
        $booking = $this->bookingsQuery($request)->findOrFail($id);

        $allowed = [
            'Pending' => ['Approved', 'Cancelled'],
            'Approved' => ['Completed', 'Cancelled'],
        ];
        if (! in_array($data['status'], $allowed[$booking->status] ?? [], true)) {
            return response()->json([
                'message' => "A {$booking->status} request cannot be changed to {$data['status']}.",
            ], 422);
        }

        $booking->changeStatus($data['status'], $request->user()->id);

        return response()->json($booking->fresh(['trail:id,name,location,user_id', 'user:id,name,email,phone', 'logs']));
    }

    public function deleteCompletedBooking(Request $request, $id)
    {
        $booking = $this->bookingsQuery($request)->findOrFail($id);
        if ($booking->status !== 'Completed') {
            return response()->json(['message' => 'Only completed booking history can be deleted.'], 422);
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

        return response()->json(['message' => 'Completed booking history and its review deleted.']);
    }

    public function reports(Request $request)
    {
        $bookings = $this->bookingsQuery($request)->get();

        return response()->json(Reports::summary($bookings));
    }
}
