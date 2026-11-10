<?php

namespace Tests\Feature;

use App\Models\Trail;
use App\Models\User;
use App\Support\SpamProtection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpamProtectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_hiker_is_temporarily_restricted_after_two_spam_violations(): void
    {
        $this->assertRoleIsRestrictedAfterTwoAttempts('hiker', 'hiker-booking-request');
    }

    public function test_guide_is_temporarily_restricted_after_two_spam_violations(): void
    {
        $this->assertRoleIsRestrictedAfterTwoAttempts('guide', 'guide-trail-request');
    }

    public function test_expired_temporary_restriction_clears_automatically(): void
    {
        $this->travelTo(now());
        $user = User::factory()->create(['role' => 'hiker']);
        $requestHash = 'hiker-booking-request';

        $this->assertNull(SpamProtection::checkRestriction($user, 'hiker', $requestHash));
        SpamProtection::checkRestriction($user, 'hiker', $requestHash);
        SpamProtection::checkRestriction($user, 'hiker', $requestHash);

        $this->travel(61)->minutes();

        $this->assertNull(SpamProtection::checkRestriction($user->fresh(), 'hiker', $requestHash));
        $this->assertSame('clear', $user->fresh()->spam_validation_status);
        $this->assertSame(0, $user->fresh()->spam_violation_count);
    }

    public function test_booking_endpoint_warns_then_pauses_after_two_spam_violations(): void
    {
        $this->travelTo(now());
        $hiker = User::factory()->create(['role' => 'hiker']);
        $guide = User::factory()->create(['role' => 'guide', 'is_active' => true]);
        $trail = $this->createTrail($guide);
        $payload = [
            'trail_id' => $trail->id,
            'guide_id' => $guide->id,
            'trek_date' => now()->addDays(3)->toDateString(),
            'group_size' => 1,
        ];

        Sanctum::actingAs($hiker);

        $this->postJson('/api/bookings', $payload)->assertCreated();
        $this->postJson('/api/bookings', $payload)
            ->assertTooManyRequests()
            ->assertJsonPath('restricted', false);
        $this->postJson('/api/bookings', $payload)
            ->assertTooManyRequests()
            ->assertJsonPath('restricted', true)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'one hour'));

        $this->assertDatabaseCount('bookings', 1);
        $this->assertSame('review', $hiker->fresh()->spam_validation_status);

        $this->travel(61)->minutes();

        $this->postJson('/api/bookings', $payload)->assertCreated();
        $this->assertSame('clear', $hiker->fresh()->spam_validation_status);
        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_only_admin_can_declare_an_account_flagged(): void
    {
        $user = User::factory()->create([
            'role' => 'hiker',
            'spam_validation_status' => 'review',
            'spam_restriction_expires_at' => now()->addHour(),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$user->id}/validation", ['status' => 'flagged'])
            ->assertOk()
            ->assertJsonPath('spam_validation_status', 'flagged')
            ->assertJsonPath('spam_restriction_expires_at', null);

        $response = SpamProtection::checkRestriction($user->fresh(), 'hiker', 'new-request');

        $this->assertTrue($response['restricted']);
        $this->assertSame('flagged', $user->fresh()->spam_validation_status);
        $this->assertDatabaseHas('user_spam_audit_logs', [
            'user_id' => $user->id,
            'role' => 'hiker',
            'status' => 'flagged',
        ]);
    }

    private function assertRoleIsRestrictedAfterTwoAttempts(string $role, string $requestHash): void
    {
        $this->travelTo(now());
        $user = User::factory()->create(['role' => $role]);

        $this->assertNull(SpamProtection::checkRestriction($user, $role, $requestHash));

        $warning = SpamProtection::checkRestriction($user, $role, $requestHash);
        $this->assertFalse($warning['restricted']);
        $this->assertSame('review', $user->fresh()->spam_validation_status);
        $this->assertNull($user->fresh()->spam_restriction_expires_at);

        $restricted = SpamProtection::checkRestriction($user, $role, $requestHash);

        $this->assertTrue($restricted['restricted']);
        $this->assertSame('review', $user->fresh()->spam_validation_status);
        $this->assertSame(2, $user->fresh()->spam_violation_count);
        $this->assertEquals(now()->addHour()->toDateTimeString(), $user->fresh()->spam_restriction_expires_at->toDateTimeString());
        $this->assertNull($user->fresh()->spam_flagged_at);
        $this->assertDatabaseCount('user_spam_audit_logs', 2);
        $this->assertDatabaseHas('user_spam_audit_logs', [
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'restricted',
        ]);
    }

    private function createTrail(User $guide): Trail
    {
        return Trail::create([
            'user_id' => $guide->id,
            'name' => 'Spam Test Trail',
            'location' => 'Test Location',
            'category' => 'Mountain',
            'difficulty' => 'Easy',
            'trip_type' => 'Day Hike',
            'description' => 'Trail used to test booking spam protection.',
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
