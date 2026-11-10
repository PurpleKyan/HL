<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Review;
use App\Models\Trail;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewDeletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_review_deletion_removes_public_photo_and_recalculates_trail_rating(): void
    {
        Storage::fake('public');
        $hiker = User::factory()->create(['role' => 'hiker']);
        $guide = User::factory()->create(['role' => 'guide', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        $trail = Trail::create([
            'user_id' => $guide->id,
            'name' => 'Review cleanup trail',
            'location' => 'Test location',
            'category' => 'Mountain',
            'difficulty' => 'Easy',
            'trip_type' => 'Day Hike',
            'description' => 'Trail used to test review cleanup.',
            'elevation' => 100,
            'distance_km' => 2,
            'duration_hours' => 2,
            'entrance_fee' => 100,
            'guide_fee' => 500,
            'max_daily_visitors' => 5,
            'status' => 'verified',
            'is_active' => true,
        ]);
        $booking = Booking::create([
            'booking_code' => 'HV-REVIEW-CLEANUP',
            'user_id' => $hiker->id,
            'guide_id' => $guide->id,
            'trail_id' => $trail->id,
            'trek_date' => now()->subDays(2)->toDateString(),
            'group_size' => 1,
            'total_cost' => 600,
            'status' => 'Completed',
        ]);
        $review = Review::create([
            'booking_id' => $booking->id,
            'trail_id' => $trail->id,
            'user_id' => $hiker->id,
            'rating' => 4,
            'photos' => ['https://example.test/storage/reviews/admin-review.jpg'],
        ]);
        Storage::disk('public')->put('reviews/admin-review.jpg', 'photo');
        $trail->update(['avg_rating' => 4, 'reviews_count' => 1]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Review deleted.');

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        Storage::disk('public')->assertMissing('reviews/admin-review.jpg');
        $this->assertSame(0, $trail->fresh()->reviews_count);
        $this->assertSame(0.0, (float) $trail->fresh()->avg_rating);
    }
}
