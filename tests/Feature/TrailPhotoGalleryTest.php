<?php

namespace Tests\Feature;

use App\Models\Trail;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrailPhotoGalleryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_upload_and_delete_trail_gallery_photos(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $trail = $this->createTrail();
        Sanctum::actingAs($admin);

        $upload = $this->withHeaders(['Accept' => 'application/json'])->post(
            "/api/admin/trails/{$trail->id}/photos",
            ['photos' => [
                UploadedFile::fake()->create('trail-one.jpg', 10, 'image/jpeg'),
                UploadedFile::fake()->create('trail-two.jpg', 10, 'image/jpeg'),
            ]]
        );

        $upload->assertCreated()->assertJsonCount(2);
        $photoId = $upload->json('0.id');
        foreach ($upload->json() as $photo) {
            Storage::disk('public')->assertExists("trail-gallery/{$trail->id}/".substr($photo['id'], strlen('file-')));
        }

        $this->getJson("/api/trails/{$trail->id}")
            ->assertOk()
            ->assertJsonCount(2, 'photos')
            ->assertJsonStructure(['photos' => [['id', 'url']]]);

        $this->deleteJson("/api/admin/trails/{$trail->id}/photos/{$photoId}")
            ->assertOk()
            ->assertJsonCount(1, 'photos');
        $remainingPhoto = $upload->json('1.id');
        Storage::disk('public')->assertMissing("trail-gallery/{$trail->id}/".substr($photoId, strlen('file-')));
        Storage::disk('public')->assertExists("trail-gallery/{$trail->id}/".substr($remainingPhoto, strlen('file-')));
    }

    public function test_non_admin_cannot_upload_trail_gallery_photos(): void
    {
        Storage::fake('public');
        $hiker = User::factory()->create(['role' => 'hiker']);
        $trail = $this->createTrail();
        Sanctum::actingAs($hiker);

        $this->withHeaders(['Accept' => 'application/json'])->post(
            "/api/admin/trails/{$trail->id}/photos",
            ['photos' => [UploadedFile::fake()->create('not-allowed.jpg', 10, 'image/jpeg')]]
        )->assertForbidden();

        $this->assertSame([], Storage::disk('public')->allFiles("trail-gallery/{$trail->id}"));
    }

    private function createTrail(): Trail
    {
        $guide = User::factory()->create(['role' => 'guide']);

        return Trail::create([
            'user_id' => $guide->id,
            'name' => 'Gallery Test Trail',
            'location' => 'Test Location',
            'category' => 'Mountain',
            'difficulty' => 'Easy',
            'trip_type' => 'Day Hike',
            'description' => 'Trail used to test gallery photos.',
            'elevation' => 100,
            'distance_km' => 2,
            'duration_hours' => 2,
            'entrance_fee' => 100,
            'guide_fee' => 500,
            'max_daily_visitors' => 20,
            'status' => 'verified',
            'is_active' => true,
        ]);
    }
}
