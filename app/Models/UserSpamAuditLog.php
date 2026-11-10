<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSpamAuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'role',
        'reason',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
