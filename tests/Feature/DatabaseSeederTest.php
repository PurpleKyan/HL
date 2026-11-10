<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trail;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seeder_provides_ten_verified_destination_profiles_with_standard_fields(): void
    {
        (new DatabaseSeeder)->run();

        $trails = Trail::where('status', 'verified')->where('is_active', true)->get();

        $this->assertGreaterThanOrEqual(10, $trails->count());
        foreach ($trails as $trail) {
            $this->assertNotEmpty($trail->location);
            $this->assertNotEmpty($trail->difficulty);
            $this->assertGreaterThan(0, $trail->duration_hours);
            $this->assertGreaterThan(0, $trail->distance_km);
            $this->assertNotNull($trail->entrance_fee);
            $this->assertNotNull($trail->permits);
            $this->assertNotNull($trail->latitude);
            $this->assertNotNull($trail->longitude);
        }
    }

    public function test_reseeding_preserves_booking_status_log_order(): void
    {
        $seeder = new DatabaseSeeder;
        $seeder->run();
        $seeder->run();

        $booking = Booking::where('booking_code', 'like', 'HV-SEED-%')
            ->orderBy('id')
            ->firstOrFail();

        $this->assertSame(
            ['Pending', 'Approved', 'Completed'],
            $booking->logs()->pluck('status')->all()
        );
    }

    public function test_seeded_demo_accounts_can_log_in_with_the_documented_password(): void
    {
        (new DatabaseSeeder)->run();

        foreach ([
            ['admin@gmail.com', 'admin'],
            ['kian@gmail.com', 'guide'],
            ['trisha@gmail.com', 'hiker'],
        ] as [$email, $role]) {
            $this->postJson('/api/login', [
                'email' => $email,
                'password' => 'password123',
                'role' => $role,
            ])->assertOk()->assertJsonStructure(['user', 'token']);
        }
    }

    public function test_login_rejects_email_addresses_containing_uppercase_letters(): void
    {
        (new DatabaseSeeder)->run();

        $this->postJson('/api/login', [
            'email' => 'Admin@gmail.com',
            'password' => 'password123',
            'role' => 'admin',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_rejects_email_addresses_that_do_not_end_in_gmail_com(): void
    {
        (new DatabaseSeeder)->run();
        $admin = User::where('name', 'System Admin')->firstOrFail();
        $admin->email = 'admin@outlook.com';
        $admin->save();

        $this->postJson('/api/login', [
            'email' => 'admin@outlook.com',
            'password' => 'password123',
            'role' => 'admin',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_reseeding_does_not_overwrite_demo_account_email_or_password(): void
    {
        (new DatabaseSeeder)->run();
        $admin = User::where('name', 'System Admin')->firstOrFail();
        $admin->email = 'newadmin@gmail.com';
        $admin->password = 'changedpass123';
        $admin->save();

        (new DatabaseSeeder)->run();

        $this->assertSame('newadmin@gmail.com', $admin->fresh()->email);
        $this->assertTrue(password_verify('changedpass123', $admin->fresh()->password));
        $this->postJson('/api/login', [
            'email' => 'newadmin@gmail.com',
            'password' => 'changedpass123',
            'role' => 'admin',
        ])->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_booking_status_changes_are_recorded_with_timestamps(): void
    {
        (new DatabaseSeeder)->run();
        $booking = Booking::where('booking_code', 'like', 'HV-SEED-%')->firstOrFail();

        $booking->changeStatus('Cancelled', $booking->user_id);
        $booking->refresh();

        $this->assertSame('Cancelled', $booking->status);
        $this->assertSame(
            ['Pending', 'Approved', 'Completed', 'Cancelled'],
            $booking->logs()->pluck('status')->all()
        );
        $this->assertNotNull($booking->logs()->latest('id')->firstOrFail()->created_at);
    }
}
