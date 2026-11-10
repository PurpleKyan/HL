<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trail extends Model
{
    protected $fillable = [
        'user_id', 'name', 'location', 'category', 'difficulty', 'trip_type', 'description',
        'elevation', 'distance_km', 'duration_hours', 'entrance_fee', 'guide_fee', 'permits',
        'max_daily_visitors', 'latitude', 'longitude', 'image_url', 'features',
        'safety_advisory', 'lnt_notes', 'status', 'is_active', 'avg_rating', 'reviews_count',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_active' => 'boolean',
            'elevation' => 'integer',
            'max_daily_visitors' => 'integer',
            'reviews_count' => 'integer',
            'distance_km' => 'float',
            'duration_hours' => 'float',
            'entrance_fee' => 'float',
            'guide_fee' => 'float',
            'latitude' => 'float',
            'longitude' => 'float',
            'avg_rating' => 'float',
        ];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

}
