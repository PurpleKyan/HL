<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Review;
use App\Models\Trail;
use App\Models\User;
use App\Models\UserSpamAuditLog;
use App\Support\Reports;
use App\Support\ReviewMedia;
use App\Support\TrailGallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AdminController extends Controller
{
    public function stats()
    {
        return response()->json([
            'users' => [
                'hikers' => User::where('role', 'hiker')->count(),
                'guides' => User::where('role', 'guide')->count(),
                'admins' => User::where('role', 'admin')->count(),
            ],
            'trails' => [
                'total' => Trail::count(),
                'pending' => Trail::where('status', 'pending')->count(),
                'verified' => Trail::where('status', 'verified')->count(),
                'rejected' => Trail::where('status', 'rejected')->count(),
            ],
            'bookings' => [
                'total' => Booking::count(),
                'pending' => Booking::where('status', 'Pending')->count(),
                'approved' => Booking::where('status', 'Approved')->count(),
                'completed' => Booking::where('status', 'Completed')->count(),
                'cancelled' => Booking::where('status', 'Cancelled')->count(),
            ],
        ]);
    }

    public function users()
    {
        $users = User::orderBy('role')->orderBy('name')->get();
        $users->each(function (User $user): void {
            $user->setAttribute('has_guide_qualification', filled($user->guide_qualification_path));
        });

        return response()->json($users);
    }

    public function reviews()
    {
        return response()->json(
            Review::with(['user:id,name,email', 'trail:id,name'])
                ->latest()
                ->get()
        );
    }

    public function deleteReview($id)
    {
        $review = Review::findOrFail($id);
        $trailId = $review->trail_id;
        $photoPaths = ReviewMedia::paths($review->photos);
        $review->delete();
        Storage::disk('public')->delete($photoPaths);

        $trail = Trail::find($trailId);
        if ($trail) {
            $trail->update([
                'avg_rating' => round((float) Review::where('trail_id', $trailId)->avg('rating'), 2),
                'reviews_count' => Review::where('trail_id', $trailId)->count(),
            ]);
        }

        return response()->json(['message' => 'Review deleted.']);
    }

    public function flaggedUsers()
    {
        return response()->json(
            User::where('spam_validation_status', '!=', 'clear')
                ->orWhereNotNull('spam_restriction_expires_at')
                ->orderByDesc('spam_restriction_expires_at')
                ->orderByDesc('spam_violation_count')
                ->get()
        );
    }

    public function reviewValidation(Request $request, $id)
    {
        $data = $request->validate([
            'status' => 'required|in:clear,flagged,approved,rejected',
            'notes' => 'nullable|string|max:1000',
        ]);

        $user = User::findOrFail($id);
        $user->forceFill([
            'spam_validation_status' => $data['status'],
            'spam_restriction_expires_at' => in_array($data['status'], ['clear', 'approved', 'flagged'], true) ? null : $user->spam_restriction_expires_at,
            'spam_violation_count' => in_array($data['status'], ['clear', 'approved'], true) ? 0 : $user->spam_violation_count,
            'spam_last_submission_at' => in_array($data['status'], ['clear', 'approved'], true) ? null : $user->spam_last_submission_at,
            'spam_last_request_hash' => in_array($data['status'], ['clear', 'approved'], true) ? null : $user->spam_last_request_hash,
            'spam_flagged_at' => $data['status'] === 'flagged' ? now() : (in_array($data['status'], ['clear', 'approved'], true) ? null : $user->spam_flagged_at),
        ])->save();

        UserSpamAuditLog::create([
            'user_id' => $user->id,
            'role' => $user->role,
            'status' => $data['status'],
            'reason' => $data['notes'] ?? 'Manual validation review by administrator.',
            'metadata' => [
                'reviewed_by' => $request->user()->id,
            ],
        ]);

        return response()->json($user->fresh());
    }

    public function toggleUser(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id || $user->role === 'admin') {
            return response()->json(['message' => 'Administrator accounts cannot be deactivated here.'], 422);
        }

        $user->update(['is_active' => ! $user->is_active]);
        if (! $user->is_active) {
            $user->tokens()->delete(); // force logout
        }

        return response()->json($user->fresh());
    }

    public function reviewGuideApplication(Request $request, $id)
    {
        $data = $request->validate(['status' => 'required|in:approved,rejected']);
        $user = User::where('role', 'guide')->findOrFail($id);

        if ($data['status'] === 'approved') {
            if (! $user->guide_qualification_path) {
                return response()->json(['message' => 'This guide has not submitted a qualification photo.'], 422);
            }

            $user->update(['account_status' => 'approved', 'is_active' => true, 'guide_visible' => true]);
        } else {
            $user->update(['account_status' => 'rejected', 'is_active' => false, 'guide_visible' => false]);
            $user->tokens()->delete();
        }

        return response()->json($user->fresh());
    }

    public function deleteUser($id)
    {
        $user = User::whereIn('role', ['hiker', 'guide'])->findOrFail($id);
        $trailIds = Trail::where('user_id', $user->id)->pluck('id');
        $bookingIds = Booking::where(function ($query) use ($user): void {
            $query->where('user_id', $user->id)
                ->orWhere('guide_id', $user->id);
        })
            ->when($trailIds->isNotEmpty(), fn ($query) => $query->orWhereIn('trail_id', $trailIds))
            ->pluck('id');
        $reviews = Review::where(function ($query) use ($user, $trailIds, $bookingIds): void {
            $query->where('user_id', $user->id);
            if ($trailIds->isNotEmpty()) {
                $query->orWhereIn('trail_id', $trailIds);
            }
            if ($bookingIds->isNotEmpty()) {
                $query->orWhereIn('booking_id', $bookingIds);
            }
        })->get();

        $publicPaths = $reviews
            ->flatMap(fn (Review $review) => ReviewMedia::paths($review->photos))
            ->values()
            ->all();

        foreach (Trail::whereIn('id', $trailIds)->get() as $trail) {
            $publicPaths = [
                ...$publicPaths,
                ...Storage::disk('public')->files("trail-gallery/{$trail->id}"),
            ];

            if ($trail->image_url && str_contains($trail->image_url, '/storage/trails/')) {
                $publicPaths[] = 'trails/'.basename(parse_url($trail->image_url, PHP_URL_PATH));
            }
        }

        $qualificationPath = $user->guide_qualification_path;
        DB::transaction(function () use ($user, $trailIds, $bookingIds, $reviews): void {
            $user->tokens()->delete();
            $reviews->each->delete();
            Booking::whereIn('id', $bookingIds)->delete();
            Trail::whereIn('id', $trailIds)->delete();
            $user->delete();
        });

        if ($qualificationPath) {
            Storage::disk('local')->delete($qualificationPath);
        }
        Storage::disk('public')->delete(array_unique($publicPaths));

        return response()->json(['message' => 'User account and linked history deleted.']);
    }

    public function guideQualificationPhoto($id)
    {
        $user = User::where('role', 'guide')->findOrFail($id);
        abort_unless($user->guide_qualification_path, 404);

        $path = Storage::disk('local')->path($user->guide_qualification_path);
        abort_unless(is_file($path), 404);

        $response = response()->file($path);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public function trails(Request $request)
    {
        $q = Trail::with('owner:id,name,email');
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return response()->json($q->latest()->get()->map(fn (Trail $trail) => TrailGallery::attach($trail)));
    }

    public function storeTrail(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
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
        ]);

        $guide = User::whereKey($data['user_id'])->where('role', 'guide')->where('is_active', true)->first();
        if (! $guide) {
            return response()->json(['message' => 'Please select an active guide.'], 422);
        }

        $data['status'] = 'verified';
        $data['is_active'] = true;

        $trail = Trail::create($data);

        return response()->json($trail->fresh('owner:id,name,email'), 201);
    }

    public function updateTrail(Request $request, $id)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'location' => 'required|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'image_url' => 'nullable|url|max:500',
            'entrance_fee' => 'required|numeric|min:0|max:1000000',
            'guide_fee' => 'required|numeric|min:0|max:1000000',
        ]);

        $trail = Trail::findOrFail($id);
        $trail->update($data);

        return response()->json($this->freshTrailWithGallery($trail));
    }

    public function updateTrailPicture(Request $request, $id)
    {
        $request->validate([
            'picture' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $trail = Trail::findOrFail($id);
        $path = $request->file('picture')->store('trails', 'public');

        if ($trail->image_url && str_contains($trail->image_url, '/storage/trails/')) {
            $oldPath = 'trails/'.basename(parse_url($trail->image_url, PHP_URL_PATH));
            Storage::disk('public')->delete($oldPath);
        }

        $imageUrl = Storage::disk('public')->url($path);
        $trail->update(['image_url' => $imageUrl]);

        return response()->json($this->freshTrailWithGallery($trail));
    }

    public function uploadTrailPhotos(Request $request, $id)
    {
        $data = $request->validate([
            'photos' => 'required|array|min:1|max:10',
            'photos.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $trail = Trail::findOrFail($id);
        $storedPaths = [];

        try {
            $disk = Storage::disk('public');
            $photos = [];
            foreach ($data['photos'] as $photo) {
                $path = $photo->store("trail-gallery/{$trail->id}", 'public');
                if (! is_string($path)) {
                    throw new \RuntimeException('A trail gallery photo could not be saved.');
                }

                $storedPaths[] = $path;
                $photos[] = [
                    'id' => 'file-'.basename($path),
                    'trail_id' => $trail->id,
                    'url' => $disk->url($path),
                    'created_at' => date(DATE_ATOM, $disk->lastModified($path)),
                ];
            }
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);
            throw $exception;
        }

        return response()->json($photos, 201);
    }

    public function deleteTrailPhoto($trailId, $photoId)
    {
        $trail = Trail::findOrFail($trailId);
        if (str_starts_with($photoId, 'file-')) {
            $filename = substr($photoId, strlen('file-'));
            abort_unless($filename !== '' && basename($filename) === $filename, 404);
            $path = "trail-gallery/{$trail->id}/{$filename}";
            abort_unless(Storage::disk('public')->exists($path), 404);
            Storage::disk('public')->delete($path);
        } else {
            abort(404);
        }

        return response()->json(['message' => 'Trail photo deleted.', 'photos' => TrailGallery::photos($trail)]);
    }

    private function freshTrailWithGallery(Trail $trail): Trail
    {
        return TrailGallery::attach($trail->fresh('owner:id,name,email'));
    }

    public function verifyTrail(Request $request, $id)
    {
        $data = $request->validate(['status' => 'required|in:pending,verified,rejected']);
        $trail = Trail::findOrFail($id);
        $trail->update(['status' => $data['status']]);

        return response()->json($trail->fresh('owner:id,name,email'));
    }

    public function toggleTrailVisibility(Request $request, $id)
    {
        $trail = Trail::findOrFail($id);
        $trail->update(['is_active' => ! $trail->is_active]);

        return response()->json($trail->fresh('owner:id,name,email'));
    }

    public function bookings()
    {
        return response()->json(
            Booking::with(['trail:id,name,location', 'user:id,name,email'])->orderByDesc('id')->get()
        );
    }

    public function reports()
    {
        return response()->json(Reports::summary(Booking::with('trail:id,name')->get()));
    }
}
