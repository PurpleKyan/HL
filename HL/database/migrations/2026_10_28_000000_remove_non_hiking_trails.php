<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Eco-Park and Waterfall destinations are supported; no records are removed.
    }

    public function down(): void
    {
        // Non-hiking trail records cannot be restored without their original data.
    }
};
