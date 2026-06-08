<?php

namespace Database\Seeders;

use App\Models\PlacementTest;
use App\Models\PlacementTestQuestion;
use Illuminate\Database\Seeder;

/**
 * Seeds one active English placement test with 70 original EMC-authored questions.
 *
 * Level distribution (mirrors the scoring service LEVEL_MAP):
 *   Q 1 – 6  → Beginner         (score 1-6)
 *   Q 7 – 20 → Elementary       (score 7-20)
 *   Q21 – 34 → Pre-Intermediate (score 21-34)
 *   Q35 – 48 → Intermediate     (score 35-48)
 *   Q49 – 62 → Upper Intermediate (score 49-62)
 *   Q63 – 70 → Advanced         (score 63-70)
 */
class PlacementTestSeeder extends Seeder
{
    public function run(): void
    {
        $test = PlacementTest::firstOrCreate(
            ['title' => 'EMC English Placement Test'],
            [
                'description'      => 'A 70-question diagnostic test that assesses your English proficiency level from Beginner to Advanced.',
                'language'         => 'en',
                'duration_minutes' => 30,
                'total_questions'  => 70,
                'is_active'        => true,
            ]
        );

        // Wipe existing questions so the seeder is re-runnable
        $test->questions()->delete();

        foreach ($this->questions() as $q) {
            PlacementTestQuestion::create(array_merge($q, ['placement_test_id' => $test->id]));
        }
    }

    private function questions(): array
    {
        return [
            // ── BEGINNER (Q1-6) ───────────────────────────────────────────────
            [
                'order' => 1, 'level_band' => 'beginner',
                'question_text' => 'What ___ your name?',
                'option_a' => 'is', 'option_b' => 'are', 'option_c' => 'am', 'option_d' => 'be',
                'correct_option' => 'a',
            ],
            [
                'order' => 2, 'level_band' => 'beginner',
                'question_text' => 'She ___ a teacher.',
                'option_a' => 'am', 'option_b' => 'are', 'option_c' => 'is', 'option_d' => 'be',
                'correct_option' => 'c',
            ],
            [
                'order' => 3, 'level_band' => 'beginner',
                'question_text' => 'I ___ from Egypt.',
                'option_a' => 'is', 'option_b' => 'are', 'option_c' => 'am', 'option_d' => 'be',
                'correct_option' => 'c',
            ],
            [
                'order' => 4, 'level_band' => 'beginner',
                'question_text' => '___ apple is on the table.',
                'option_a' => 'A', 'option_b' => 'An', 'option_c' => 'The', 'option_d' => '---',
                'correct_option' => 'b',
            ],
            [
                'order' => 5, 'level_band' => 'beginner',
                'question_text' => 'They ___ happy today.',
                'option_a' => 'is', 'option_b' => 'am', 'option_c' => 'are', 'option_d' => 'be',
                'correct_option' => 'c',
            ],
            [
                'order' => 6, 'level_band' => 'beginner',
                'question_text' => 'He ___ not here.',
                'option_a' => 'am', 'option_b' => 'are', 'option_c' => 'is', 'option_d' => 'be',
                'correct_option' => 'c',
            ],

            // ── ELEMENTARY (Q7-20) ────────────────────────────────────────────
            [
                'order' => 7, 'level_band' => 'elementary',
                'question_text' => 'Yesterday, I ___ to school by bus.',
                'option_a' => 'go', 'option_b' => 'went', 'option_c' => 'gone', 'option_d' => 'goes',
                'correct_option' => 'b',
            ],
            [
                'order' => 8, 'level_band' => 'elementary',
                'question_text' => 'She is ___ a book right now.',
                'option_a' => 'read', 'option_b' => 'reads', 'option_c' => 'reading', 'option_d' => 'readed',
                'correct_option' => 'c',
            ],
            [
                'order' => 9, 'level_band' => 'elementary',
                'question_text' => 'We ___ a film last night.',
                'option_a' => 'watched', 'option_b' => 'watch', 'option_c' => 'watching', 'option_d' => 'watches',
                'correct_option' => 'a',
            ],
            [
                'order' => 10, 'level_band' => 'elementary',
                'question_text' => 'He ___ TV at the moment.',
                'option_a' => 'watch', 'option_b' => 'watched', 'option_c' => 'is watching', 'option_d' => 'has watched',
                'correct_option' => 'c',
            ],
            [
                'order' => 11, 'level_band' => 'elementary',
                'question_text' => 'Did you ___ your homework yesterday?',
                'option_a' => 'did', 'option_b' => 'does', 'option_c' => 'do', 'option_d' => 'done',
                'correct_option' => 'c',
            ],
            [
                'order' => 12, 'level_band' => 'elementary',
                'question_text' => 'They ___ not come to class yesterday.',
                'option_a' => 'did', 'option_b' => 'does', 'option_c' => 'didn\'t', 'option_d' => 'don\'t',
                'correct_option' => 'c',
            ],
            [
                'order' => 13, 'level_band' => 'elementary',
                'question_text' => 'The opposite of "hot" is ___.',
                'option_a' => 'warm', 'option_b' => 'cold', 'option_c' => 'cool', 'option_d' => 'wet',
                'correct_option' => 'b',
            ],
            [
                'order' => 14, 'level_band' => 'elementary',
                'question_text' => '___ you like tea or coffee?',
                'option_a' => 'Are', 'option_b' => 'Do', 'option_c' => 'Does', 'option_d' => 'Is',
                'correct_option' => 'b',
            ],
            [
                'order' => 15, 'level_band' => 'elementary',
                'question_text' => 'She ___ to music every morning.',
                'option_a' => 'listen', 'option_b' => 'listens', 'option_c' => 'listening', 'option_d' => 'listened',
                'correct_option' => 'b',
            ],
            [
                'order' => 16, 'level_band' => 'elementary',
                'question_text' => 'He ___ football on Sundays.',
                'option_a' => 'play', 'option_b' => 'played', 'option_c' => 'plays', 'option_d' => 'playing',
                'correct_option' => 'c',
            ],
            [
                'order' => 17, 'level_band' => 'elementary',
                'question_text' => 'The children ___ in the park right now.',
                'option_a' => 'plays', 'option_b' => 'are playing', 'option_c' => 'played', 'option_d' => 'will play',
                'correct_option' => 'b',
            ],
            [
                'order' => 18, 'level_band' => 'elementary',
                'question_text' => 'I ___ a new phone last year.',
                'option_a' => 'buy', 'option_b' => 'buying', 'option_c' => 'bought', 'option_d' => 'buys',
                'correct_option' => 'c',
            ],
            [
                'order' => 19, 'level_band' => 'elementary',
                'question_text' => 'How many ___ do you have?',
                'option_a' => 'brother', 'option_b' => 'brothers', 'option_c' => 'brotheres', 'option_d' => 'brotherz',
                'correct_option' => 'b',
            ],
            [
                'order' => 20, 'level_band' => 'elementary',
                'question_text' => 'There ___ a cat on the roof.',
                'option_a' => 'are', 'option_b' => 'is', 'option_c' => 'am', 'option_d' => 'be',
                'correct_option' => 'b',
            ],

            // ── PRE-INTERMEDIATE (Q21-34) ─────────────────────────────────────
            [
                'order' => 21, 'level_band' => 'pre_intermediate',
                'question_text' => 'I ___ never been to London.',
                'option_a' => 'have', 'option_b' => 'has', 'option_c' => 'had', 'option_d' => 'having',
                'correct_option' => 'a',
            ],
            [
                'order' => 22, 'level_band' => 'pre_intermediate',
                'question_text' => 'She ___ already finished her work.',
                'option_a' => 'have', 'option_b' => 'has', 'option_c' => 'had', 'option_d' => 'is',
                'correct_option' => 'b',
            ],
            [
                'order' => 23, 'level_band' => 'pre_intermediate',
                'question_text' => 'We ___ just arrived at the hotel.',
                'option_a' => 'has', 'option_b' => 'had', 'option_c' => 'have', 'option_d' => 'having',
                'correct_option' => 'c',
            ],
            [
                'order' => 24, 'level_band' => 'pre_intermediate',
                'question_text' => 'You ___ wear a seatbelt in a car. It\'s the law.',
                'option_a' => 'can', 'option_b' => 'must', 'option_c' => 'might', 'option_d' => 'would',
                'correct_option' => 'b',
            ],
            [
                'order' => 25, 'level_band' => 'pre_intermediate',
                'question_text' => 'It ___ rain tomorrow, so take an umbrella.',
                'option_a' => 'must', 'option_b' => 'can', 'option_c' => 'might', 'option_d' => 'shall',
                'correct_option' => 'c',
            ],
            [
                'order' => 26, 'level_band' => 'pre_intermediate',
                'question_text' => 'I ___ swim very well when I was a child.',
                'option_a' => 'can', 'option_b' => 'could', 'option_c' => 'must', 'option_d' => 'should',
                'correct_option' => 'b',
            ],
            [
                'order' => 27, 'level_band' => 'pre_intermediate',
                'question_text' => 'She is interested ___ learning new languages.',
                'option_a' => 'at', 'option_b' => 'in', 'option_c' => 'on', 'option_d' => 'for',
                'correct_option' => 'b',
            ],
            [
                'order' => 28, 'level_band' => 'pre_intermediate',
                'question_text' => 'He has been working here ___ 2020.',
                'option_a' => 'for', 'option_b' => 'since', 'option_c' => 'from', 'option_d' => 'during',
                'correct_option' => 'b',
            ],
            [
                'order' => 29, 'level_band' => 'pre_intermediate',
                'question_text' => 'I\'ve known her ___ five years.',
                'option_a' => 'since', 'option_b' => 'ago', 'option_c' => 'for', 'option_d' => 'from',
                'correct_option' => 'c',
            ],
            [
                'order' => 30, 'level_band' => 'pre_intermediate',
                'question_text' => 'They ___ pass the exam if they study hard.',
                'option_a' => 'would', 'option_b' => 'will', 'option_c' => 'can', 'option_d' => 'must',
                'correct_option' => 'b',
            ],
            [
                'order' => 31, 'level_band' => 'pre_intermediate',
                'question_text' => 'I have been waiting ___ an hour.',
                'option_a' => 'since', 'option_b' => 'for', 'option_c' => 'during', 'option_d' => 'from',
                'correct_option' => 'b',
            ],
            [
                'order' => 32, 'level_band' => 'pre_intermediate',
                'question_text' => 'She ___ left by the time we arrived.',
                'option_a' => 'has', 'option_b' => 'have', 'option_c' => 'had', 'option_d' => 'having',
                'correct_option' => 'c',
            ],
            [
                'order' => 33, 'level_band' => 'pre_intermediate',
                'question_text' => 'You ___ go to the doctor — you look very sick.',
                'option_a' => 'might', 'option_b' => 'could', 'option_c' => 'should', 'option_d' => 'would',
                'correct_option' => 'c',
            ],
            [
                'order' => 34, 'level_band' => 'pre_intermediate',
                'question_text' => 'Have you ever ___ sushi?',
                'option_a' => 'ate', 'option_b' => 'eat', 'option_c' => 'eaten', 'option_d' => 'eating',
                'correct_option' => 'c',
            ],

            // ── INTERMEDIATE (Q35-48) ─────────────────────────────────────────
            [
                'order' => 35, 'level_band' => 'intermediate',
                'question_text' => 'If I ___ rich, I would travel the world.',
                'option_a' => 'am', 'option_b' => 'was', 'option_c' => 'were', 'option_d' => 'be',
                'correct_option' => 'c',
            ],
            [
                'order' => 36, 'level_band' => 'intermediate',
                'question_text' => 'The letter ___ sent by the manager yesterday.',
                'option_a' => 'was', 'option_b' => 'is', 'option_c' => 'were', 'option_d' => 'be',
                'correct_option' => 'a',
            ],
            [
                'order' => 37, 'level_band' => 'intermediate',
                'question_text' => 'The book ___ I bought last week is very interesting.',
                'option_a' => 'who', 'option_b' => 'where', 'option_c' => 'which', 'option_d' => 'whom',
                'correct_option' => 'c',
            ],
            [
                'order' => 38, 'level_band' => 'intermediate',
                'question_text' => 'If he ___ harder, he would have passed the exam.',
                'option_a' => 'studied', 'option_b' => 'had studied', 'option_c' => 'would study', 'option_d' => 'studies',
                'correct_option' => 'b',
            ],
            [
                'order' => 39, 'level_band' => 'intermediate',
                'question_text' => 'A new hospital ___ built in the city next year.',
                'option_a' => 'is being', 'option_b' => 'will be', 'option_c' => 'has been', 'option_d' => 'had been',
                'correct_option' => 'b',
            ],
            [
                'order' => 40, 'level_band' => 'intermediate',
                'question_text' => 'She\'s the woman ___ husband is a famous musician.',
                'option_a' => 'who', 'option_b' => 'whose', 'option_c' => 'which', 'option_d' => 'whom',
                'correct_option' => 'b',
            ],
            [
                'order' => 41, 'level_band' => 'intermediate',
                'question_text' => 'If you ___ me earlier, I would have helped you.',
                'option_a' => 'told', 'option_b' => 'had told', 'option_c' => 'would tell', 'option_d' => 'tell',
                'correct_option' => 'b',
            ],
            [
                'order' => 42, 'level_band' => 'intermediate',
                'question_text' => 'The report ___ being written when the power went out.',
                'option_a' => 'has', 'option_b' => 'is', 'option_c' => 'was', 'option_d' => 'were',
                'correct_option' => 'c',
            ],
            [
                'order' => 43, 'level_band' => 'intermediate',
                'question_text' => 'Unless you ___ now, you\'ll miss the train.',
                'option_a' => 'leave', 'option_b' => 'left', 'option_c' => 'leaving', 'option_d' => 'had left',
                'correct_option' => 'a',
            ],
            [
                'order' => 44, 'level_band' => 'intermediate',
                'question_text' => 'This is the city ___ I was born.',
                'option_a' => 'which', 'option_b' => 'that', 'option_c' => 'where', 'option_d' => 'who',
                'correct_option' => 'c',
            ],
            [
                'order' => 45, 'level_band' => 'intermediate',
                'question_text' => 'By the time she arrived, we ___ for an hour.',
                'option_a' => 'were waiting', 'option_b' => 'had been waiting', 'option_c' => 'have been waiting', 'option_d' => 'are waiting',
                'correct_option' => 'b',
            ],
            [
                'order' => 46, 'level_band' => 'intermediate',
                'question_text' => 'The problem ___ solved after a long discussion.',
                'option_a' => 'is', 'option_b' => 'were', 'option_c' => 'was', 'option_d' => 'has',
                'correct_option' => 'c',
            ],
            [
                'order' => 47, 'level_band' => 'intermediate',
                'question_text' => 'I wish I ___ speak Japanese fluently.',
                'option_a' => 'can', 'option_b' => 'could', 'option_c' => 'would', 'option_d' => 'will',
                'correct_option' => 'b',
            ],
            [
                'order' => 48, 'level_band' => 'intermediate',
                'question_text' => 'The students ___ hand in their projects by Friday.',
                'option_a' => 'must', 'option_b' => 'should', 'option_c' => 'had to', 'option_d' => 'have to',
                'correct_option' => 'd',
            ],

            // ── UPPER INTERMEDIATE (Q49-62) ───────────────────────────────────
            [
                'order' => 49, 'level_band' => 'upper_intermediate',
                'question_text' => 'She managed to ___ the difficult situation calmly.',
                'option_a' => 'cope for', 'option_b' => 'cope with', 'option_c' => 'cope at', 'option_d' => 'cope on',
                'correct_option' => 'b',
            ],
            [
                'order' => 50, 'level_band' => 'upper_intermediate',
                'question_text' => 'The project should ___ completed before the deadline.',
                'option_a' => 'have been', 'option_b' => 'has been', 'option_c' => 'had been', 'option_d' => 'being',
                'correct_option' => 'a',
            ],
            [
                'order' => 51, 'level_band' => 'upper_intermediate',
                'question_text' => 'No sooner ___ she arrived than it started raining.',
                'option_a' => 'did', 'option_b' => 'had', 'option_c' => 'was', 'option_d' => 'has',
                'correct_option' => 'b',
            ],
            [
                'order' => 52, 'level_band' => 'upper_intermediate',
                'question_text' => 'He is used to ___ late every night for his studies.',
                'option_a' => 'work', 'option_b' => 'working', 'option_c' => 'worked', 'option_d' => 'works',
                'correct_option' => 'b',
            ],
            [
                'order' => 53, 'level_band' => 'upper_intermediate',
                'question_text' => 'Had I known the truth, I ___ told you immediately.',
                'option_a' => 'will have', 'option_b' => 'would have', 'option_c' => 'had', 'option_d' => 'have',
                'correct_option' => 'b',
            ],
            [
                'order' => 54, 'level_band' => 'upper_intermediate',
                'question_text' => 'She ___ up smoking three years ago.',
                'option_a' => 'gave', 'option_b' => 'give', 'option_c' => 'gives', 'option_d' => 'given',
                'correct_option' => 'a',
            ],
            [
                'order' => 55, 'level_band' => 'upper_intermediate',
                'question_text' => 'Not only ___ he speak Arabic, but he also speaks French.',
                'option_a' => 'did', 'option_b' => 'does', 'option_c' => 'do', 'option_d' => 'is',
                'correct_option' => 'b',
            ],
            [
                'order' => 56, 'level_band' => 'upper_intermediate',
                'question_text' => 'The announcement ___ been made public by the time we woke up.',
                'option_a' => 'has', 'option_b' => 'had', 'option_c' => 'have', 'option_d' => 'having',
                'correct_option' => 'b',
            ],
            [
                'order' => 57, 'level_band' => 'upper_intermediate',
                'question_text' => 'I\'d rather you ___ tell anyone about this matter.',
                'option_a' => 'don\'t', 'option_b' => 'didn\'t', 'option_c' => 'won\'t', 'option_d' => 'wouldn\'t',
                'correct_option' => 'b',
            ],
            [
                'order' => 58, 'level_band' => 'upper_intermediate',
                'question_text' => 'He tends to ___ with his work even when he is exhausted.',
                'option_a' => 'carry on', 'option_b' => 'carry out', 'option_c' => 'carry up', 'option_d' => 'carry off',
                'correct_option' => 'a',
            ],
            [
                'order' => 59, 'level_band' => 'upper_intermediate',
                'question_text' => 'Rarely ___ such a talented student in this programme.',
                'option_a' => 'we see', 'option_b' => 'do we see', 'option_c' => 'we did see', 'option_d' => 'we have seen',
                'correct_option' => 'b',
            ],
            [
                'order' => 60, 'level_band' => 'upper_intermediate',
                'question_text' => 'She ___ have taken the money — she was at home all day.',
                'option_a' => 'could', 'option_b' => 'couldn\'t', 'option_c' => 'should', 'option_d' => 'wouldn\'t',
                'correct_option' => 'b',
            ],
            [
                'order' => 61, 'level_band' => 'upper_intermediate',
                'question_text' => '___ the bad weather, the outdoor event was a great success.',
                'option_a' => 'Despite', 'option_b' => 'Although', 'option_c' => 'However', 'option_d' => 'Nevertheless',
                'correct_option' => 'a',
            ],
            [
                'order' => 62, 'level_band' => 'upper_intermediate',
                'question_text' => 'The results of the experiment were called ___ question by several researchers.',
                'option_a' => 'into', 'option_b' => 'in', 'option_c' => 'at', 'option_d' => 'under',
                'correct_option' => 'a',
            ],

            // ── ADVANCED (Q63-70) ─────────────────────────────────────────────
            [
                'order' => 63, 'level_band' => 'advanced',
                'question_text' => 'The committee\'s decision was met with ___ opposition from the staff.',
                'option_a' => 'formidable', 'option_b' => 'vehement', 'option_c' => 'negligible', 'option_d' => 'tentative',
                'correct_option' => 'b',
            ],
            [
                'order' => 64, 'level_band' => 'advanced',
                'question_text' => 'The treaty, ___ by both nations, remains controversial to this day.',
                'option_a' => 'ratified', 'option_b' => 'rectified', 'option_c' => 'reiterated', 'option_d' => 'renounced',
                'correct_option' => 'a',
            ],
            [
                'order' => 65, 'level_band' => 'advanced',
                'question_text' => 'Scarcely ___ the meeting when the fire alarm went off.',
                'option_a' => 'had we started', 'option_b' => 'we had started', 'option_c' => 'did we start', 'option_d' => 'we started',
                'correct_option' => 'a',
            ],
            [
                'order' => 66, 'level_band' => 'advanced',
                'question_text' => 'The hypothesis ___ by subsequent experiments.',
                'option_a' => 'was substantiated', 'option_b' => 'was substanced', 'option_c' => 'has substantiated', 'option_d' => 'substantiated itself',
                'correct_option' => 'a',
            ],
            [
                'order' => 67, 'level_band' => 'advanced',
                'question_text' => 'To "beat around the bush" means to ___.',
                'option_a' => 'avoid giving a direct answer', 'option_b' => 'enjoy outdoor activities', 'option_c' => 'tell the truth bluntly', 'option_d' => 'speak very clearly',
                'correct_option' => 'a',
            ],
            [
                'order' => 68, 'level_band' => 'advanced',
                'question_text' => 'It is imperative that the data ___ before publication.',
                'option_a' => 'is verified', 'option_b' => 'are verified', 'option_c' => 'be verified', 'option_d' => 'verified',
                'correct_option' => 'c',
            ],
            [
                'order' => 69, 'level_band' => 'advanced',
                'question_text' => 'The author\'s argument is predicated ___ the assumption that language shapes thought.',
                'option_a' => 'on', 'option_b' => 'in', 'option_c' => 'at', 'option_d' => 'for',
                'correct_option' => 'a',
            ],
            [
                'order' => 70, 'level_band' => 'advanced',
                'question_text' => 'Code-switching refers to ___.',
                'option_a' => 'switching between computer programmes', 'option_b' => 'alternating between two languages within a conversation', 'option_c' => 'changing passwords frequently', 'option_d' => 'translating documents simultaneously',
                'correct_option' => 'b',
            ],
        ];
    }
}
