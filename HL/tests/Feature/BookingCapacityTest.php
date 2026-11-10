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

class BookingCapacityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_booking_group_size_cannot_exceed_60(): void
    {
        $hiker = User::factory()->create(['role' => 'hiker']);
        $guide = User::factory()->create(['role' => 'guide', 'is_active' => true]);
        $trail = $this->createTrail($guide);
        $trail->update(['max_daily_visitors' => 60]);

        Sanctum::actingAs($hiker);

        $this->postJson('/api/bookings', [
            'trail_id' => $trail->id,
            'guide_id' => $guide->id,
            'trek_date' => now()->addDays(3)->toDateString(),
            'group_size' => 60,
        ])->assertCreated()
            ->assertJsonPath('group_size', 60);

        $this->postJson('/api/bookings', [
            'trail_id' => $trail->id,
            'guide_id' => $guide->id,
            'trek_date' => now()->addDays(3)->toDateString(),
            'group_size' => 61,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('group_size');
    }

    public function test_completed_bookings_still_count_toward_daily_capacity(): void
    {
        $hiker = User::factory()->create(['role' => 'hiker']);
        $guide = User::factory()->create(['role' => 'guide', 'is_active' => true]);
        $trail = $this->createTrail($guide);
        $date = now()->addDays(3)->toDateString();

        Booking::create([
            'booking_code' => 'HV-TEST-COMPLETE',
            'user_id' => $hiker->id,
            'guide_id' => $guide->id,
            'trail_id' => $trail->id,
            'trek_date' => $date,
            'group_size' => 4,
            'total_cost' => 900,
            'status' => 'Completed',
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'hiker']));

        $this->postJson('/api/bookings', [
            'trail_id' => $trail->id,
            'guide_id' => $guide->id,
            'trek_date' => $date,
            'group_size' => 2,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Not enough slots on that date. Only 1 slot(s) left (daily limit: 5).');
    }

    public function test_hiker_can_delete_owned_completed_booking_review_and_photos(): void
    {
        Storage::fake('public');
        $hiker = User::factory()->create(['role' => 'hiker']);
        $otherHiker = User::factory()->create(['role' => 'hiker']);
        $guide = User::factory()->create(['role' => 'guide', 'is_active' => true]);
        $trail = $this->createTrail($guide);
        $booking = $this->createBooking($hiker, $guide, $trail, 'HV-DELETE-HIKER');
        $otherBooking = $this->createBooking($otherHiker, $guide, $trail, 'HV-KEEP-OTHER');
        $review = Review::create([
            'booking_id' => $booking->id,
            'trail_id' => $trail->id,
            'user_id' => $hiker->id,
            'rating' => 3,
            'photos' => [Storage::disk('public')->url('reviews/hiker-review.jpg')],
        ]);
        Review::create([
            'booking_id' => $otherBooking->id,
            'trail_id' => $trail->id,
            'user_id' => $otherHiker->id,
            'rating' => 5,
        ]);
        Storage::disk('public')->put('reviews/hiker-review.jpg', 'photo');
        $trail->update(['avg_rating' => 4, 'reviews_count' => 2]);

        Sanctum::actingAs($hiker);

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Completed booking and its review deleted.');

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        $this->assertDatabaseHas('bookings', ['id' => $otherBooking->id]);
        $this->assertDatabaseHas('reviews', ['booking_id' => $otherBooking->id]);
        $this->assertDatabaseMissing('booking_status_logs', ['booking_id' => $booking->id]);
        Storage::disk('public')->assertMissing('reviews/hiker-review.jpg');
        $this->assertSame(1, $trail->fresh()->reviews_count);
        $this->assertSame(5.0, (float) $trail->fresh()->avg_rating);
    }

    public function test_hiker_cannot_delete_another_users_or_incomplete_booking(): void
    {
        $hiker = User::factory()->create(['role' => 'hiker']);
        $otherHiker = User::factory()->create(['role' => 'hiker']);
        $guide = User::factory()->create(['role' => 'guide', 'is_active' => true]);
        $trail = $this->createTrail($guide);
        $otherBooking = $this->createBooking($otherHiker, $guide, $trail, 'HV-OTHER-OWNER');
        $pendingBooking = $this->createBooking($hiker, $guide, $trail, 'HV-PENDING', 'Pending');

        Sanctum::actingAs($hiker);

        $this->deleteJson("/api/bookings/{$otherBooking->id}")->assertNotFound();
        $this->deleteJson("/api/bookings/{$pendingBooking->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only completed bookings can be deleted.');

        $this->assertDatabaseHas('bookings', ['id' => $otherBooking->id]);
        $this->assertDatabaseHas('bookings', ['id' => $pendingBooking->id]);
    }

    public function test_guide_can_delete_only_completed_booking_history_for_owned_trail(): void
    {
        Storage::fake('public');
        $hiker = User::factory()->create(['role' => 'hiker']);
        $guide = User::factory()->create(['role' => 'guide', 'is_active' => true]);
        $otherGuide = User::factory()->create(['role' => 'guide', 'is_active' => true]);
        $trail = $this->createTrail($guide);
        $otherTrail = $this->createTrail($otherGuide);
        $booking = $this->createBooking($hiker, $guide, $trail, 'HV-GUIDE-DELETE');
        $otherBooking = $this->createBooking($hiker, $otherGuide, $otherTrail, 'HV-GUIDE-OTHER');
        $review = Review::create([
            'booking_id' => $booking->id,
            'trail_id' => $trail->id,
            'user_id' => $hiker->id,
            'rating' => 4,
            'photos' => ['https://example.test/storage/reviews/guide-review.jpg'],
        ]);
        Storage::disk('public')->put('reviews/guide-review.jpg', 'photo');

        Sanctum::actingAs($guide);

        $this->deleteJson("/api/guide/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Completed booking history and its review deleted.');
        $this->deleteJson("/api/guide/bookings/{$otherBooking->id}")->assertNotFound();

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        $this->assertDatabaseHas('bookings', ['id' => $otherBooking->id]);
        Storage::disk('public')->assertMissing('reviews/guide-review.jpg');
    }

    private function createBooking(User $hiker, User $guide, Trail $trail, string $code, string $status = 'Completed'): Booking
    {
        $booking = Booking::create([
            'booking_code' => $code,
            'user_id' => $hiker->id,
            'guide_id' => $guide->id,
            'trail_id' => $trail->id,
            'trek_date' => now()->subDays(2)->toDateString(),
            'group_size' => 1,
            'total_cost' => 600,
            'status' => $status,
        ]);
        $booking->logs()->create(['status' => $status]);

        return $booking;
    }

    private function createTrail(User $guide): Trail
    {
        return Trail::create([
            'user_id' => $guide->id,
            'name' => 'Capacity Test Trail',
            'location' => 'Test Location',
            'category' => 'Mountain',
            'difficulty' => 'Easy',
            'trip_type' => 'Day Hike',
            'description' => 'Trail used to test daily booking capacity.',
            'elevation' => 100,
            'distance_km' => 2,
            'duration_hours' => 2,
            'entrance_fee' => 100,
            'guide_fee' => 500,
            'max_daily_visitors' => 5,
            'status' => 'verified',
            'is_active' => true,
        ]);
    }
}
