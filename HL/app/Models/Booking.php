<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Booking extends Model
{
    protected $fillable = [
        'booking_code', 'user_id', 'guide_id', 'trail_id', 'trek_date', 'group_size',
        'itinerary', 'special_requirements', 'total_cost', 'status',
    ];

    protected function casts(): array
    {
        return [
            'trek_date' => 'date:Y-m-d',
            'total_cost' => 'float',
            'group_size' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function guide()
    {
        return $this->belongsTo(User::class, 'guide_id');
    }

    public function trail()
    {
        return $this->belongsTo(Trail::class);
    }

    public function logs()
    {
        return $this->hasMany(BookingStatusLog::class)->orderBy('id');
    }

    public function review()
    {
        return $this->hasOne(Review::class);
    }

    /** Change the status and save a date/time-stamped log entry. */
    public function changeStatus(string $status, ?int $userId = null): void
    {
        DB::transaction(function () use ($status, $userId): void {
            $this->update(['status' => $status]);
            $this->logs()->create(['status' => $status, 'changed_by' => $userId]);
        });
    }
}
