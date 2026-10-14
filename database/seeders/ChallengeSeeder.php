<?php

namespace Database\Seeders;

use App\Models\Achievement;
use App\Models\Challenge;
use Illuminate\Database\Seeder;

class ChallengeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['cook-together', 'Cook together', 'Choose a recipe and make a meal side by side.', 'food', 'easy', 20],
            ['movie-night', 'Movie night', 'Pick a film together and make time to enjoy it.', 'quality_time', 'easy', 30],
            ['new-restaurant', 'Try a new restaurant', 'Discover a new place to eat together.', 'food', 'medium', 40],
            ['photo-together', 'Take a photo together', 'Capture a little moment that feels like you.', 'creativity', 'easy', 20],
            ['date-night', 'Go on a date', 'Plan a date that gives you time to reconnect.', 'quality_time', 'medium', 50],
            ['sunrise', 'Watch sunrise', 'Find a safe spot and enjoy the start of a day together.', 'adventure', 'hard', 60],
            ['new-place', 'Visit somewhere new', 'Explore a place neither of you has visited.', 'adventure', 'hard', 70],
            ['no-phone-dinner', 'No-phone dinner', 'Put your phones away and share an uninterrupted meal.', 'quality_time', 'easy', 30],
        ] as [$key,$title,$description,$category,$difficulty,$points]) {
            Challenge::query()->firstOrCreate(['key' => $key], compact('title', 'description', 'category', 'difficulty', 'points') + ['active' => true]);
        }
        foreach ([
            ['first-step', 'First Step', 'Complete your first challenge.', 'heart', 'completed_count', 1],
            ['shared-rhythm', 'Shared Rhythm', 'Complete three challenges together.', 'sparkles', 'completed_count', 3],
            ['curious-hearts', 'Curious Hearts', 'Complete challenges in three categories.', 'compass', 'category_count', 3],
            ['love-in-action', 'Love in Action', 'Earn 200 love points.', 'trophy', 'total_points', 200],
            ['eight-moments', 'Eight Moments', 'Complete eight challenges together.', 'crown', 'completed_count', 8],
        ] as [$key,$title,$description,$badge,$metric,$threshold]) {
            Achievement::query()->firstOrCreate(['key' => $key], compact('title', 'description', 'badge', 'metric', 'threshold'));
        }
    }
}
