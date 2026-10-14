<?php

namespace Database\Seeders;

use App\Models\DailyQuestion;
use Illuminate\Database\Seeder;

class DailyQuestionSeeder extends Seeder
{
    public function run(): void
    {
        $questions = [
            ['memories', 'What is one small moment with us that you would love to relive?'],
            ['connection', 'What helps you feel listened to when we talk?'],
            ['dreams', 'Where would you like us to spend a quiet weekend together?'],
            ['everyday', 'What little habit of ours makes you smile?'],
            ['gratitude', 'What is something you appreciate about us this week?'],
            ['fun', 'If we could try a new hobby together, what would you choose?'],
            ['memories', 'What do you remember most clearly about our first days together?'],
            ['connection', 'What kind of support would feel good to you today?'],
            ['dreams', 'What is a shared goal you would like us to make time for?'],
            ['everyday', 'What would your ideal slow morning together look like?'],
            ['gratitude', 'What is one thing your partner did recently that meant a lot to you?'],
            ['fun', 'Which meal would you love for us to cook together?'],
            ['memories', 'Which photo of us tells a story you love?'],
            ['connection', 'How do you like to reconnect after a busy day?'],
            ['dreams', 'What new place would you like us to explore nearby?'],
            ['everyday', 'What is one small thing we could do to make this week easier?'],
            ['gratitude', 'What strength do you see in your partner?'],
            ['fun', 'What would you put on a playlist for our next adventure?'],
            ['memories', 'What shared challenge are you proud we got through?'],
            ['connection', 'What makes you feel safe to be yourself with me?'],
            ['dreams', 'What tradition would you like us to start?'],
            ['everyday', 'What is a simple date we could enjoy at home?'],
            ['gratitude', 'What have you learned from your partner?'],
            ['fun', 'If we had an afternoon with no plans, how would you spend it?'],
            ['memories', 'What is a funny moment between us that still makes you laugh?'],
            ['connection', 'What is something you would like us to understand better about each other?'],
            ['dreams', 'What would you like our next year together to feel like?'],
            ['everyday', 'What is your favorite way for us to spend time without screens?'],
            ['gratitude', 'What is one way our relationship has helped you grow?'],
            ['fun', 'What surprise adventure would you plan for the two of us?'],
        ];
        foreach ($questions as [$category,$question]) {
            DailyQuestion::query()->firstOrCreate(['question' => $question], ['category' => $category, 'active' => true]);
        }
    }
}
