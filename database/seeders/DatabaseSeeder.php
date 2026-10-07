<?php

// database/seeders/DatabaseSeeder.php
namespace Database\Seeders;

use App\Models\User;
use App\Models\UserRole;
use App\Models\Platform;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create the Platforms
        $platforms = [
            ['name' => 'NTV', 'type' => 'TV', 'hsl_color' => '142 70% 45%'],
            ['name' => 'KFM', 'type' => 'Radio', 'hsl_color' => '25 95% 53%'],
            ['name' => 'SPARK TV', 'type' => 'TV', 'hsl_color' => '0 84% 60%'],
            ['name' => 'SQOOP', 'type' => 'Web', 'hsl_color' => '221 83% 53%'],
        ];

        foreach ($platforms as $platform) {
            Platform::create($platform);
        }

        // 2. Create the Admin (Super User)
        $admin = User::create([
            'name' => 'Nicholas Admin',
            'email' => 'admin@superdesk.me',
            'password' => Hash::make('password123'),
        ]);
        UserRole::create(['user_id' => $admin->id, 'role' => 'admin']);

        // 3. Create a Reporter
        $reporter = User::create([
            'name' => 'Staff Reporter',
            'email' => 'reporter@superdesk.me',
            'password' => Hash::make('password123'),
        ]);
        UserRole::create(['user_id' => $reporter->id, 'role' => 'reporter']);
        $reporter->platforms()->sync(Platform::whereIn('name', ['NTV', 'KFM'])->pluck('id'));

        // 4. Create a Desk Editor (reviews NTV + KFM)
        $editor = User::create([
            'name' => 'Desk Editor',
            'email' => 'editor@superdesk.me',
            'password' => Hash::make('password123'),
        ]);
        UserRole::create(['user_id' => $editor->id, 'role' => 'editor']);
        $editor->platforms()->sync(Platform::whereIn('name', ['NTV', 'KFM'])->pluck('id'));

        $this->command->info('Super Desk Seeding Complete!');
    }
}