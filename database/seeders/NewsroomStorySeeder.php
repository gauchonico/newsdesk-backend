<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Story;
use App\Models\User;
use App\Models\Platform;
use App\Models\Tag;
use Illuminate\Support\Facades\Hash;

class NewsroomStorySeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create a baseline generic fallback user if your users table is clean empty
        $defaultAuthor = User::firstOrCreate(
            ['email' => 'newsroom.staff@ntv.co.ug'],
            [
                'name' => 'Newsroom Staff',
                'password' => Hash::make('password'),
                'active' => true
            ]
        );

        // 2. Map Mock Platforms directly to your dynamic platform table IDs
        $platformsMap = [];
        $mockPlatformNames = ['NTV', 'SPARK TV', 'KFM', 'SQOOP'];
        $colors = ['142 60% 45%', '25 95% 53%', '0 84% 60%', '217 91% 60%'];

        foreach ($mockPlatformNames as $index => $name) {
            $type = ($name === 'KFM') ? 'Radio' : (($name === 'SQOOP') ? 'Web' : 'TV');
            $p = Platform::firstOrCreate(
                ['name' => $name],
                [
                    'type' => $type,
                    'hsl_color' => $colors[$index],
                    'is_active' => true,
                    'description' => "Official channel for $name broadcasts."
                ]
            );
            $platformsMap[$name] = $p->id;
        }

        // 3. Your array of structured Mock Stories
        $mockStories = [
            [
                'headline' => 'President Signs New Infrastructure Bill Into Law',
                'summary' => 'Major infrastructure bill signed, allocating $2B for roads and bridges.',
                'content' => '<p>The President today signed a landmark infrastructure bill that allocates $2 billion for roads...</p>',
                'platform' => 'NTV',
                'status' => 'live',
                'category' => 'Politics',
                'tags' => ['Political', 'Breaking'],
                'is_community' => false,
            ],
            [
                'headline' => 'Uganda Cranes Qualify for AFCON Quarter Finals',
                'summary' => 'Uganda Cranes advance to AFCON quarters after 2-1 win.',
                'content' => '<p>In a stunning victory, the Uganda Cranes defeated their opponents 2-1 to advance...</p>',
                'platform' => 'SPARK TV',
                'status' => 'approved',
                'category' => 'Sports',
                'tags' => ['Sports', 'Breaking'],
                'is_community' => false,
            ],
            [
                'headline' => 'KFM Morning Show: Rising Fuel Prices Impact Economy',
                'summary' => 'Economic analysts discussed the impact of rising fuel prices...',
                'content' => '<p>Economic analysts discussed the impact of rising fuel prices on the national economy...</p>',
                'platform' => 'KFM',
                'status' => 'pending',
                'category' => 'Business',
                'tags' => ['Political', 'Opinion'],
                'is_community' => false,
            ],
            [
                'headline' => 'Exclusive: Government Plans Digital ID Rollout',
                'summary' => 'Sources within the Ministry of ICT have revealed plans for digital ID rollout.',
                'content' => '<p>Sources within the Ministry of ICT have revealed plans for a nationwide digital ID...</p>',
                'platform' => 'SQOOP',
                'status' => 'held',
                'category' => 'Technology',
                'tags' => ['Exclusive', 'Investigation'],
                'is_community' => false,
            ],
            [
                'headline' => 'Community Report: Flooding in Bwaise Displaces Families',
                'summary' => 'Residents of Bwaise have reported severe flooding that has displaced dozens of families.',
                'content' => '<p>Residents of Bwaise have reported severe flooding that has displaced dozens of families...</p>',
                'platform' => 'NTV',
                'status' => 'pending',
                'category' => 'Environment',
                'tags' => ['Breaking'],
                'is_community' => true,
            ]
        ];

        // 4. Loop over mock entries and save them securely
        foreach ($mockStories as $mockData) {
            $story = Story::create([
                'user_id' => $defaultAuthor->id,
                'platform_id' => $platformsMap[$mockData['platform']] ?? $platformsMap['NTV'],
                'title' => $mockData['headline'],
                'summary' => $mockData['summary'],
                'content' => $mockData['content'],
                'status' => $mockData['status'],
                'category' => $mockData['category'],
                'is_community' => $mockData['is_community'],
            ]);

            // Sync dynamic tags ingestion pipeline
            $tagIds = [];
            foreach ($mockData['tags'] as $tagName) {
                // Find existing tag by name or make a new one dynamically!
                $tag = Tag::firstOrCreate(['name' => trim($tagName)]);
                $tagIds[] = $tag->id;
            }
            
            // Link story to tags inside table
            $story->tags()->sync($tagIds);
        }
    }
}