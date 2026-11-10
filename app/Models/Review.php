<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = ['booking_id', 'trail_id', 'user_id', 'rating', 'comment', 'photos'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'photos' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function trail()
    {
        return $this->belongsTo(Trail::class);
    }
}
