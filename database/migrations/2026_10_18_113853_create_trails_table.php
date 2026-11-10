<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // guide / officer who owns it
            $table->string('name');
            $table->string('location');
            $table->string('category');   // Hiking trail type: Mountain
            $table->string('difficulty'); // Easy | Moderate | Hard
            $table->string('trip_type');  // Day Hike | Overnight
            $table->text('description');
            $table->unsignedInteger('elevation')->default(0);
            $table->decimal('distance_km', 7, 2)->default(0);
            $table->decimal('duration_hours', 6, 1)->default(0);
            $table->decimal('entrance_fee', 10, 2)->default(0);   // per hiker
            $table->decimal('guide_fee', 10, 2)->default(0);      // per group
            $table->text('permits')->nullable();
            $table->unsignedInteger('max_daily_visitors')->default(30);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->json('features')->nullable();
            $table->text('safety_advisory')->nullable();
            $table->text('lnt_notes')->nullable();
            $table->string('status')->default('pending'); // pending | verified | rejected
            $table->boolean('is_active')->default(true);
            $table->decimal('avg_rating', 3, 2)->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trails');
    }
};
