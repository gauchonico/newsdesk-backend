<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Master Tags Table
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Unique tag tracking
            $table->string('slug')->unique(); // Helpful for clean URLs later
            $table->timestamps();
        });

        // 2. Many-to-Many Pivot Table Linking Stories to Tags
        Schema::create('story_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->onDelete('cascade');
            $table->foreignId('tag_id')->constrained()->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_tag');
        Schema::dropIfExists('tags');
    }
};