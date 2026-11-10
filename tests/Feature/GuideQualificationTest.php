<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Review;
use App\Models\Trail;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuideQualificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guide_registration_requires_and_privately_stores_qualification_photo(): void
    {
        Storage::fake('local');

        $this->postJson('/api/register', $this->guideRegistrationData())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['qualification_photo']);

        $this->postJson('/api/register', [
            ...$this->guideRegistrationData(),
            'qualification_photo' => UploadedFile::fake()->create('certificate.jpg', 10, 'image/jpeg'),
        ])
            ->assertCreated()
            ->assertJsonPath('account_status', 'pending');

        $guide = User::where('email', 'guide@gmail.com')->firstOrFail();
        $this->assertSame('pending', $guide->account_status);
        $this->assertStringStartsWith('guide-qualifications/', $guide->guide_qualification_path);
        Storage::disk('local')->assertExists($guide->guide_qualification_path);
    }

    public function test_only_an_administrator_can_view_a_guide_qualification_photo(): void
    {
        Storage::fake('local');
        $guide = User::factory()->create([
            'role' => 'guide',
            'account_status' => 'pending',
            'guide_qualification_path' => UploadedFile::fake()->create('certificate.png', 10, 'image/png')->store('guide-qualifications', 'local'),
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'hiker']));
        $this->get("/api/admin/users/{$guide->id}/guide-qualification")->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonFragment(['id' => $guide->id, 'has_guide_qualification' => true]);
        $this->get("/api/admin/users/{$guide->id}/guide-qualification")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->patchJson("/api/admin/users/{$guide->id}/guide-review", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('account_status', 'approved');
    }

    public function test_hiker_registration_does_not_require_a_qualification_photo(): void
    {
        $this->postJson('/api/register', [
            ...$this->guideRegistrationData(),
            'email' => 'hiker@gmail.com',
            'role' => 'hiker',
            'phone' => '09123456789',
        ])
            ->assertCreated()
            ->assertJsonPath('user.role', 'hiker')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('users', ['email' => 'hiker@gmail.com', 'phone' => '09123456789']);
    }

    public function test_registration_rejects_an_email_that_is_already_registered_regardless_of_case(): void
    {
        User::factory()->create(['email' => 'existing@gmail.com']);

        foreach (['existing@gmail.com', 'Existing@Gmail.com'] as $email) {
            $this->postJson('/api/register', [
                ...$this->guideRegistrationData(),
                'email' => $email,
                'role' => 'hiker',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['email'])
                ->assertJsonPath('errors.email.0', 'This email is already registered. Please log in or use a different email address.');
        }

        $this->assertDatabaseCount('users', 1);
    }

    public function test_email_availability_check_reports_existing_and_available_gmail_addresses(): void
    {
        User::factory()->create(['email' => 'existing@gmail.com']);

        $this->postJson('/api/register/check-email', ['email' => 'existing@gmail.com'])
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('message', 'This email is already registered. Please log in or use a different email address.');

        $this->postJson('/api/register/check-email', ['email' => 'new@gmail.com'])
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('message', null);
    }

    public function test_hiker_registration_rejects_non_gmail_email_numeric_name_and_non_eight_character_password(): void
    {
        $validData = [
            'name' => 'Hiker Applicant',
            'email' => 'hiker.applicant@gmail.com',
            'role' => 'hiker',
            'password' => 'hike1234',
            'password_confirmation' => 'hike1234',
        ];

        $this->postJson('/api/register', [...$validData, 'email' => 'hiker@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/register', [...$validData, 'name' => 'Hiker 2026'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->postJson('/api/register', [
            ...$validData,
            'password' => 'longer-password',
            'password_confirmation' => 'longer-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_guide_registration_rejects_non_gmail_email_and_non_eight_character_password(): void
    {
        $this->postJson('/api/register', [
            ...$this->guideRegistrationData(),
            'email' => 'guide.applicant@example.org',
            'role' => 'guide',
            'password' => 'longer-password-123',
            'password_confirmation' => 'longer-password-123',
            'qualification_photo' => UploadedFile::fake()->create('certificate.jpg', 10, 'image/jpeg'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_registration_rejects_phone_numbers_that_do_not_start_with_09_or_have_the_wrong_length(): void
    {
        foreach (['08123456789', '0912345678', '091234567890', '09abc456789'] as $phone) {
            $this->postJson('/api/register', [
                ...$this->guideRegistrationData(),
                'email' => 'hiker'.strlen($phone).substr(md5($phone), 0, 6).'@gmail.com',
                'role' => 'hiker',
                'phone' => $phone,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['phone']);
        }
    }

    public function test_admin_cannot_approve_a_guide_without_qualification_photo(): void
    {
        $guide = User::factory()->create([
            'role' => 'guide',
            'account_status' => 'pending',
            'is_active' => false,
            'guide_visible' => false,
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->patchJson("/api/admin/users/{$guide->id}/guide-review", ['status' => 'approved'])
            ->assertUnprocessable()
            ->assertJson(['message' => 'This guide has not submitted a qualification photo.']);
        $this->assertSame('pending', $guide->fresh()->account_status);
    }

    public function test_admin_can_delete_guide_account_and_qualification_photo(): void
    {
        Storage::fake('local');
        $qualificationPath = UploadedFile::fake()
            ->create('certificate.png', 10, 'image/png')
            ->store('guide-qualifications', 'local');
        $guide = User::factory()->create([
            'role' => 'guide',
            'account_status' => 'rejected',
            'guide_qualification_path' => $qualificationPath,
        ]);
        $guide->createToken('guide-session');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->deleteJson("/api/admin/users/{$guide->id}")
            ->assertOk()
            ->assertJson(['message' => 'User account and linked history deleted.']);

        $this->assertDatabaseMissing('users', ['id' => $guide->id]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $guide->id,
            'tokenable_type' => User::class,
        ]);
        Storage::disk('local')->assertMissing($qualificationPath);
    }

    public function test_admin_can_delete_hiker_account(): void
    {
        $hiker = User::factory()->create(['role' => 'hiker']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->deleteJson("/api/admin/users/{$hiker->id}")
            ->assertOk()
            ->assertJson(['message' => 'User account and linked history deleted.']);

        $this->assertDatabaseMissing('users', ['id' => $hiker->id]);
    }

    public function test_admin_can_delete_pending_guide_account(): void
    {
        $guide = User::factory()->create([
            'role' => 'guide',
            'account_status' => 'pending',
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->deleteJson("/api/admin/users/{$guide->id}")
            ->assertOk()
            ->assertJson(['message' => 'User account and linked history deleted.']);

        $this->assertDatabaseMissing('users', ['id' => $guide->id]);
    }

    public function test_admin_can_delete_guide_with_owned_trail_and_related_records(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $qualificationPath = UploadedFile::fake()
            ->create('certificate.png', 10, 'image/png')
            ->store('guide-qualifications', 'local');
        $guide = User::factory()->create([
            'role' => 'guide',
            'account_status' => 'approved',
            'guide_qualification_path' => $qualificationPath,
        ]);
        $hiker = User::factory()->create(['role' => 'hiker']);
        $trail = Trail::create([
            'user_id' => $guide->id,
            'name' => 'Existing trail',
            'location' => 'Davao',
            'category' => 'Mountain',
            'difficulty' => 'Easy',
            'trip_type' => 'Day Hike',
            'description' => 'This trail will be removed with its owner.',
        ]);
        $galleryPhotoPath = UploadedFile::fake()
            ->create('gallery.jpg', 10, 'image/jpeg')
            ->store("trail-gallery/{$trail->id}", 'public');
        $reviewPhotoPath = UploadedFile::fake()
            ->create('review.jpg', 10, 'image/jpeg')
            ->store('reviews', 'public');
        $booking = Booking::create([
            'booking_code' => 'BOOKING-TEST-003',
            'user_id' => $hiker->id,
            'guide_id' => $guide->id,
            'trail_id' => $trail->id,
            'trek_date' => '2026-12-01',
            'group_size' => 1,
        ]);
        $review = Review::create([
            'booking_id' => $booking->id,
            'trail_id' => $trail->id,
            'user_id' => $hiker->id,
            'rating' => 5,
            'photos' => ['/storage/'.$reviewPhotoPath],
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->deleteJson("/api/admin/users/{$guide->id}")
            ->assertOk()
            ->assertJson(['message' => 'User account and linked history deleted.']);

        $this->assertDatabaseMissing('users', ['id' => $guide->id]);
        $this->assertDatabaseMissing('trails', ['id' => $trail->id]);
        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        Storage::disk('local')->assertMissing($qualificationPath);
        Storage::disk('public')->assertMissing($galleryPhotoPath);
        Storage::disk('public')->assertMissing($reviewPhotoPath);
    }

    public function test_admin_can_delete_guide_assigned_to_bookings_on_another_trail(): void
    {
        $guide = User::factory()->create([
            'role' => 'guide',
            'account_status' => 'rejected',
        ]);
        $trailOwner = User::factory()->create([
            'role' => 'guide',
            'account_status' => 'approved',
        ]);
        $hiker = User::factory()->create(['role' => 'hiker']);
        $trail = Trail::create([
            'user_id' => $trailOwner->id,
            'name' => 'Existing trail',
            'location' => 'Davao',
            'category' => 'Mountain',
            'difficulty' => 'Easy',
            'trip_type' => 'Day Hike',
            'description' => 'Trail history must be preserved.',
        ]);
        $booking = Booking::create([
            'booking_code' => 'BOOKING-TEST-001',
            'user_id' => $hiker->id,
            'guide_id' => $guide->id,
            'trail_id' => $trail->id,
            'trek_date' => '2026-12-01',
            'group_size' => 1,
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->deleteJson("/api/admin/users/{$guide->id}")
            ->assertOk()
            ->assertJson(['message' => 'User account and linked history deleted.']);

        $this->assertDatabaseMissing('users', ['id' => $guide->id]);
        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    public function test_admin_can_delete_hiker_and_linked_bookings_and_reviews(): void
    {
        Storage::fake('public');
        $guide = User::factory()->create([
            'role' => 'guide',
            'account_status' => 'approved',
        ]);
        $hiker = User::factory()->create(['role' => 'hiker']);
        $trail = Trail::create([
            'user_id' => $guide->id,
            'name' => 'Existing trail',
            'location' => 'Davao',
            'category' => 'Mountain',
            'difficulty' => 'Easy',
            'trip_type' => 'Day Hike',
            'description' => 'The guide-owned trail remains after hiker deletion.',
        ]);
        $reviewPhotoPath = UploadedFile::fake()
            ->create('review.jpg', 10, 'image/jpeg')
            ->store('reviews', 'public');
        $booking = Booking::create([
            'booking_code' => 'BOOKING-TEST-002',
            'user_id' => $hiker->id,
            'guide_id' => $guide->id,
            'trail_id' => $trail->id,
            'trek_date' => '2026-12-01',
            'group_size' => 1,
        ]);
        $review = Review::create([
            'booking_id' => $booking->id,
            'trail_id' => $trail->id,
            'user_id' => $hiker->id,
            'rating' => 4,
            'photos' => ['/storage/'.$reviewPhotoPath],
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->deleteJson("/api/admin/users/{$hiker->id}")
            ->assertOk()
            ->assertJson(['message' => 'User account and linked history deleted.']);

        $this->assertDatabaseMissing('users', ['id' => $hiker->id]);
        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        $this->assertDatabaseHas('trails', ['id' => $trail->id]);
        Storage::disk('public')->assertMissing($reviewPhotoPath);
    }

    public function test_admin_cannot_delete_an_admin_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->deleteJson("/api/admin/users/{$admin->id}")->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_non_admin_cannot_delete_user_account(): void
    {
        $guide = User::factory()->create([
            'role' => 'guide',
            'account_status' => 'rejected',
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'hiker']));

        $this->deleteJson("/api/admin/users/{$guide->id}")->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $guide->id]);
    }

    /**
     * @return array<string, string>
     */
    private function guideRegistrationData(): array
    {
        return [
            'name' => 'Guide Applicant',
            'email' => 'guide@gmail.com',
            'role' => 'guide',
            'password' => 'hike1234',
            'password_confirmation' => 'hike1234',
        ];
    }
}
