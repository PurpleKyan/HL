<?php

namespace Tests\Feature;

use App\Models\Trail;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTrailUpdateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_update_trail_location_picture_and_prices(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $guide = User::factory()->create(['role' => 'guide']);
        $trail = Trail::create([
            'user_id' => $guide->id,
            'name' => 'Test Trail',
            'location' => 'Old location',
            'category' => 'Mountain',
            'difficulty' => 'Easy',
            'trip_type' => 'Day Hike',
            'description' => 'Test description',
            'elevation' => 100,
            'distance_km' => 2,
            'duration_hours' => 2,
            'entrance_fee' => 100,
            'guide_fee' => 500,
            'max_daily_visitors' => 20,
            'status' => 'pending',
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/trails/{$trail->id}", [
            'location' => 'New Location',
            'latitude' => 7.18,
            'longitude' => 125.68,
            'image_url' => 'https://example.test/trail.jpg',
            'entrance_fee' => 275,
            'guide_fee' => 900,
        ])
            ->assertOk()
            ->assertJsonPath('location', 'New Location')
            ->assertJsonPath('entrance_fee', 275)
            ->assertJsonPath('guide_fee', 900);

        $this->assertDatabaseHas('trails', [
            'id' => $trail->id,
            'location' => 'New Location',
            'image_url' => 'https://example.test/trail.jpg',
            'entrance_fee' => 275,
            'guide_fee' => 900,
        ]);
    }

    public function test_admin_trail_update_rejects_invalid_prices(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $guide = User::factory()->create(['role' => 'guide']);
        $trail = Trail::create([
            'user_id' => $guide->id,
            'name' => 'Test Trail',
            'location' => 'Old location',
            'category' => 'Mountain',
            'difficulty' => 'Easy',
            'trip_type' => 'Day Hike',
            'description' => 'Test description',
            'entrance_fee' => 100,
            'guide_fee' => 500,
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/trails/{$trail->id}", [
            'location' => 'New Location',
            'image_url' => 'not-a-url',
            'entrance_fee' => -1,
            'guide_fee' => -500,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image_url', 'entrance_fee', 'guide_fee']);
    }
}
