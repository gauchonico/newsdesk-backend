<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            // Since platforms uses 1, 2, 3, this matches perfectly with BigInteger
            if (!Schema::hasColumn('stories', 'platform_id')) {
                $table->foreignId('platform_id')->nullable()->after('user_id')->constrained('platforms')->onDelete('cascade');
            }
            
            if (!Schema::hasColumn('stories', 'summary')) {
                $table->text('summary')->nullable()->after('title');
            }
            if (!Schema::hasColumn('stories', 'is_community')) {
                $table->boolean('is_community')->default(false)->after('tags');
            }
            if (!Schema::hasColumn('stories', 'media_url')) {
                $table->string('media_url')->nullable()->after('is_community');
            }
            if (!Schema::hasColumn('stories', 'media_type')) {
                $table->string('media_type')->nullable()->after('media_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropForeign(['platform_id']);
            $table->dropColumn(['platform_id', 'summary', 'is_community', 'media_url', 'media_type']);
        });
    }
};