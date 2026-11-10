<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Review;
use App\Models\Trail;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ---------- Demo accounts (password: password123) ----------
        // Create demo accounts only when missing; reseeding must not reset credentials.
        $admin = User::firstOrCreate(['name' => 'System Admin'], ['email' => 'admin@gmail.com', 'password' => 'password123', 'role' => 'admin', 'is_active' => true, 'phone' => '09170000001']);
        $guide = User::firstOrCreate(['name' => 'Kian Pardillo'], ['email' => 'kian@gmail.com', 'password' => 'password123', 'role' => 'guide', 'account_status' => 'approved', 'is_active' => true, 'guide_visible' => true, 'phone' => '09170000002']);
        $guide2 = User::firstOrCreate(['name' => 'Angelito Tocmo'], ['email' => 'tocmo@gmail.com', 'password' => 'password123', 'role' => 'guide', 'account_status' => 'approved', 'is_active' => true, 'guide_visible' => true, 'phone' => '09170000003']);
        $guide3 = User::firstOrCreate(['name' => 'Jerv Morado'], ['email' => 'jerv@gmail.com', 'password' => 'password123', 'role' => 'guide', 'account_status' => 'approved', 'is_active' => true, 'guide_visible' => true, 'phone' => '09170000004']);
        $hiker = User::firstOrCreate(['name' => 'Trisha Canete'], ['email' => 'trisha@gmail.com', 'password' => 'password123', 'role' => 'hiker', 'is_active' => true, 'phone' => '09170000005']);

        $safety = 'Check the weather before you go, bring enough water and a first-aid kit, always hike with an accredited guide, and tell someone your expected return time. Turn back if heavy rain starts.';
        $lnt = 'Pack out all trash, stay on marked trails, do not pick plants or disturb wildlife, keep noise low, and leave every campsite cleaner than you found it.';

        // ---------- SAMPLE DATA ONLY: replace with verified details from the guides / LGU ----------
        $trails = [
            ['Mt. Apo', 'Davao del Sur', 'Mountain', 'Hard', 'Overnight', 'The highest peak in the Philippines. A multi-day trek through mossy forest, sulfur vents and a crater summit.', 2954, 18.0, 36, 700, 2500, 'Protected Area Management Board (PAMB) permit; barangay clearance; medical certificate', 40, 6.9876, 125.2700, ['Scenic View', 'Camping', 'Summit Climb', 'Wildlife'], 'verified'],
            ['Mt. Hamiguitan Range', 'Davao Oriental', 'Mountain', 'Hard', 'Overnight', 'A UNESCO World Heritage wildlife sanctuary known for its pygmy forest and endemic species.', 1620, 14.0, 30, 600, 2200, 'PAMB permit; LGU registration', 30, 6.7175, 126.1730, ['Scenic View', 'Camping', 'Wildlife', 'Summit Climb'], 'verified'],
            ['Mt. Kampalili', 'Davao de Oro', 'Mountain', 'Moderate', 'Overnight', 'A moderate two-day trek ending at a grassy summit with a wide view of the Pacific side.', 1700, 10.0, 20, 250, 1800, 'LGU registration; guide required', 30, 7.2800, 126.1300, ['Scenic View', 'Camping', 'Summit Climb'], 'verified'],
            ['Mt. Talomo', 'Davao City', 'Mountain', 'Moderate', 'Day Hike', 'A popular weekend climb near the city with stream crossings and a ridge viewpoint.', 1100, 7.0, 7, 100, 1000, 'Barangay clearance', 50, 7.1000, 125.3000, ['Scenic View', 'Summit Climb', 'Swimming'], 'verified'],
            ['Maragusan Highlands Trek', 'Davao de Oro', 'Mountain', 'Easy', 'Day Hike', 'A cool-weather hiking route through farms and pine slopes in Maragusan.', 1000, 4.0, 3, 80, 600, 'Barangay clearance', 40, 7.2833, 126.0000, ['Scenic View', 'Family-Friendly', 'Cultural'], 'verified'],
            ['Aliwagwag Falls Eco Park', 'Cateel, Davao Oriental', 'Eco-Park', 'Easy', 'Day Hike', 'A protected eco-park centered on Aliwagwag Falls, with forest pathways, viewing areas and river scenery.', 240, 2.5, 2.5, 100, 500, 'LGU registration; guide recommended', 250, 7.2770, 126.3250, ['Waterfall', 'Swimming', 'Family-Friendly', 'Scenic View'], 'verified'],
            ['Malagos Garden Resort Eco Park', 'Baguio District, Davao City', 'Eco-Park', 'Easy', 'Day Hike', 'A family-oriented garden eco-park with wildlife encounters, cacao and bird experiences, landscaped gardens and nature activities.', 350, 2.0, 2.5, 250, 500, 'Park reservation; entrance fee required', 300, 7.1882, 125.4127, ['Family-Friendly', 'Wildlife', 'Scenic View', 'Cultural'], 'verified'],
            ['Eden Nature Park Eco Park', 'Toril, Davao City', 'Eco-Park', 'Easy', 'Day Hike', 'A mountain eco-park and nature resort with forest trails, gardens, family activities and cool elevated scenery.', 800, 3.0, 3.0, 300, 600, 'Park reservation; entrance package required', 300, 7.0104, 125.3665, ['Family-Friendly', 'Cultural', 'Scenic View', 'Wildlife'], 'verified'],
            ['Lake Carolina Eco Park', 'Maragusan, Davao de Oro', 'Eco-Park', 'Easy', 'Day Hike', 'A peaceful eco-park around Lake Carolina, with picnic areas, lakeside walks and cool mountain scenery.', 300, 2.0, 2.0, 40, 500, 'Barangay clearance', 100, 7.2870, 126.1270, ['Family-Friendly', 'Scenic View', 'Camping', 'Wildlife'], 'verified'],
            ['Hagimit Falls Nature Park', 'Peñaplata, Island Garden City of Samal, Davao del Norte', 'Eco-Park', 'Moderate', 'Day Hike', 'A forested nature park featuring tiered waterfalls, natural pools, rock formations and riverside picnic areas ideal for swimming and nature walks.', 150, 2.0, 2.0, 50, 500, 'Park entrance fee; local guide recommended for swimming areas and trail routes', 100, 7.0666419, 125.7230055, ['Waterfall', 'Swimming', 'Family-Friendly', 'Scenic View'], 'verified'],
        ];

        $ratings = [5, 5, 4, 4, 4, 5, 4, 4, 4, 5];

        $assignedGuides = [$guide, $guide2, $guide3];

        foreach ($trails as $i => $t) {
            $owner = $assignedGuides[$i % count($assignedGuides)];
            $trail = Trail::updateOrCreate(['name' => $t[0]], [
                'user_id' => $owner->id,
                'name' => $t[0], 'location' => $t[1], 'category' => $t[2], 'difficulty' => $t[3], 'trip_type' => $t[4],
                'description' => $t[5], 'elevation' => $t[6], 'distance_km' => $t[7], 'duration_hours' => $t[8],
                'entrance_fee' => $t[9], 'guide_fee' => $t[10], 'permits' => $t[11], 'max_daily_visitors' => $t[12],
                'latitude' => $t[13], 'longitude' => $t[14], 'features' => $t[15], 'status' => $t[16],
                'safety_advisory' => $safety, 'lnt_notes' => $lnt,
            ]);

            // A completed sample trek + review for each verified trail so ratings and reports have data
            if ($trail->status === 'verified') {
                $size = 2 + ($i % 3);
                $bookingCode = 'HV-SEED-'.str_pad((string) $trail->id, 4, '0', STR_PAD_LEFT);
                $booking = Booking::updateOrCreate(['booking_code' => $bookingCode], [
                    'user_id' => $hiker->id,
                    'guide_id' => $owner->id,
                    'trail_id' => $trail->id,
                    'trek_date' => now()->subDays(10 + $i)->toDateString(),
                    'group_size' => $size,
                    'itinerary' => 'Sample completed trek',
                    'total_cost' => ($trail->entrance_fee * $size) + $trail->guide_fee,
                    'status' => 'Completed',
                ]);
                $booking->logs()->updateOrCreate(['status' => 'Pending'], ['changed_by' => $hiker->id]);
                $booking->logs()->updateOrCreate(['status' => 'Approved'], ['changed_by' => $owner->id]);
                $booking->logs()->updateOrCreate(['status' => 'Completed'], ['changed_by' => $owner->id]);
                Review::updateOrCreate(
                    ['booking_id' => $booking->id],
                    ['trail_id' => $trail->id, 'user_id' => $hiker->id, 'rating' => $ratings[$i], 'comment' => 'Great experience, the guide was very helpful!']
                );
                $trail->update(['avg_rating' => $ratings[$i], 'reviews_count' => 1]);
            }
        }
    }
}
