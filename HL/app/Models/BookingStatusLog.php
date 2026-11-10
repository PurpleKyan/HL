<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingStatusLog extends Model
{
    protected $fillable = ['booking_id', 'changed_by', 'status'];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
