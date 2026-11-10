<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('guide_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });

        DB::table('bookings')
            ->whereNull('guide_id')
            ->orderBy('id')
            ->get(['id', 'trail_id'])
            ->each(function ($booking) {
                $guideId = DB::table('trails')->where('id', $booking->trail_id)->value('user_id');
                if ($guideId) {
                    DB::table('bookings')->where('id', $booking->id)->update(['guide_id' => $guideId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guide_id');
        });
    }
};
