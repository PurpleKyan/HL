<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HikingOnlyTrailTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guide_can_create_supported_categories(): void
    {
        foreach (["Mountain", "Waterfall", "Eco-Park"] as $category) {
            Sanctum::actingAs(User::factory()->create(["role" => "guide"]));

            $this->postJson("/api/guide/trails", [
                "name" => $category." destination",
                "location" => "Davao",
                "category" => $category,
                "difficulty" => "Easy",
                "trip_type" => "Day Hike",
                "description" => "A supported destination category.",
                "elevation" => 100,
                "distance_km" => 2,
                "duration_hours" => 2,
                "entrance_fee" => 100,
                "guide_fee" => 500,
                "max_daily_visitors" => 20,
            ])
                ->assertCreated()
                ->assertJsonPath("category", $category);
        }
    }
}