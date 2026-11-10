<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('trails')
            ->whereIn('name', ['Mt. Mayo', 'Mt. Candalaga'])
            ->delete();
    }

    public function down(): void
    {
        // Removed dashboard records cannot be restored without their original details.
    }
};
