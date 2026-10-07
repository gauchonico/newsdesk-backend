<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'active')) {
                // true by default — existing users remain accessible immediately
                $table->boolean('active')->default(true)->after('password');
            }

            if (! Schema::hasColumn('users', 'reporter_kind')) {
                $table->string('reporter_kind')->nullable()->after('active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('users', 'active')        ? 'active'        : null,
                Schema::hasColumn('users', 'reporter_kind') ? 'reporter_kind' : null,
            ]);
            if ($cols) $table->dropColumn(array_values($cols));
        });
    }
};