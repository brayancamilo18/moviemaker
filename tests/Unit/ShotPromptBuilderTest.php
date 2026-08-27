<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataObjects\Shot;
use App\DataObjects\VisualBible;
use App\Services\Image\ShotPromptBuilder;
use Tests\TestCase;

final class ShotPromptBuilderTest extends TestCase
{
    public function test_a_realistic_bible_keeps_the_negatives_and_the_style_suffix(): void
    {
        $prompt = $this->app->make(ShotPromptBuilder::class)->build(
            $this->shot(
                subject: 'both',
                threatStage: 'reveal',
                characterSlugs: ['the-walker', 'the-widow'],
            ),
            $this->richBible(),
        );

        $this->assertStringContainsString('no clear facial features', $prompt);
        $this->assertStringContainsString('no direct eye contact', $prompt);
        $this->assertStringContainsString('no crowds of people', $prompt);
        $this->assertStringContainsString($this->styleSuffixTail(), $prompt);
        $this->assertStringContainsString($this->styleSuffix(), $prompt);
    }

    public function test_the_prompt_stays_within_the_configured_word_cap(): void
    {
        $builder = $this->app->make(ShotPromptBuilder::class);
        $max = (int) config('stories.images.max_prompt_words');
        $bible = $this->richBible();

        $shots = [
            $this->shot(order: 1, subject: 'both', threatStage: 'reveal', characterSlugs: ['the-walker', 'the-widow']),
            $this->shot(order: 2, subject: 'protagonist', characterSlugs: ['the-walker']),
            $this->shot(order: 3, subject: 'environment'),
        ];

        foreach ($builder->previewAll($shots, $bible) as $index => $prompt) {
            $this->assertLessThanOrEqual(
                $max,
                count(preg_split('/\s+/u', trim($prompt), -1, PREG_SPLIT_NO_EMPTY) ?: []),
                "El prompt del plano {$shots[$index]->order} pasa del tope de palabras.",
            );
        }
    }

    public function test_framing_options_rotate_with_the_shot_order(): void
    {
        $builder = $this->app->make(ShotPromptBuilder::class);
        $bible = $this->leanBible();
        $options = $bible->characters[0]['framingOptions'];

        $this->assertCount(4, $options);

        foreach ([1, 2, 3, 4] as $order) {
            $prompt = $builder->build(
                $this->shot(order: $order, subject: 'protagonist', characterSlugs: ['the-walker']),
                $bible,
            );

            $expected = $options[$order % count($options)];

            $this->assertStringContainsString(
                $expected,
                $prompt,
                "El plano {$order} debería usar el encuadre «{$expected}».",
            );

            foreach ($options as $index => $option) {
                if ($index === $order % count($options)) {
                    continue;
                }

                $this->assertStringNotContainsString($option, $prompt);
            }
        }
    }

    public function test_a_character_slug_missing_from_the_bible_is_ignored(): void
    {
        $prompt = $this->app->make(ShotPromptBuilder::class)->build(
            $this->shot(subject: 'protagonist', characterSlugs: ['the-innkeeper', 'the-walker']),
            $this->leanBible(),
        );

        $this->assertStringContainsString('tall thin man in an olive raincoat', $prompt);
        $this->assertStringNotContainsString('the-innkeeper', $prompt);
    }

    private function styleSuffix(): string
    {
        return trim((string) config('stories.image_style_suffix'));
    }

    private function styleSuffixTail(): string
    {
        $words = preg_split('/\s+/u', $this->styleSuffix(), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_slice($words, -4));
    }

    /**
     * Biblia del tamaño que pide de verdad VisualBibleGenerator: setting de 20 palabras, dos
     * personajes con bodyDescriptor largo y seis entradas en avoid.
     */
    private function richBible(): VisualBible
    {
        return VisualBible::fromArray([
            'setting' => 'abandoned ranch on open grassland under a low sky beyond a broken fence line and a dry riverbed at dusk',
            'era' => '1990s rural',
            'timeOfDay' => 'overcast dusk',
            'weather' => 'heavy fog',
            'palette' => ['rust brown', 'bone white', 'charcoal'],
            'characters' => [
                [
                    'slug' => 'the-walker',
                    'bodyDescriptor' => 'tall thin man in a worn olive raincoat with hunched shoulders, short dark hair and muddy boots',
                    'framingOptions' => ['seen from behind', 'silhouette against light'],
                ],
                [
                    'slug' => 'the-widow',
                    'bodyDescriptor' => 'short heavy woman in a faded grey shawl with a tight braid, stooped back and cracked leather sandals',
                    'framingOptions' => ['seen from behind', 'reflected in dark glass'],
                ],
            ],
            'recurringObjects' => [],
            'avoid' => [
                'neon signs',
                'modern cars',
                'visible weapons',
                'legible signage',
                'clean bright interiors',
                'crowds of people',
            ],
            'threat' => [
                'nature' => 'a tall whistle-thin silhouette in the grass',
                'stages' => [
                    ['stage' => 'hint', 'descriptor' => 'a maybe-shape among the distant trees'],
                    ['stage' => 'presence', 'descriptor' => 'a hand on the doorframe just outside the light'],
                    ['stage' => 'reveal', 'descriptor' => 'a backlit covered figure filling the frame'],
                ],
            ],
        ]);
    }

    /**
     * Biblia corta: cabe entera en el presupuesto, así que sirve para comprobar lo que hace el
     * builder cuando no tiene que descartar nada.
     */
    private function leanBible(): VisualBible
    {
        return VisualBible::fromArray([
            'setting' => 'abandoned ranch on open grassland',
            'era' => '1990s rural',
            'timeOfDay' => 'overcast dusk',
            'weather' => 'heavy fog',
            'palette' => ['rust brown', 'charcoal'],
            'characters' => [[
                'slug' => 'the-walker',
                'bodyDescriptor' => 'tall thin man in an olive raincoat',
                'framingOptions' => [
                    'seen from behind',
                    'silhouette against light',
                    'reflected in dark glass',
                    'blurred in the foreground',
                ],
            ]],
            'recurringObjects' => [],
            'avoid' => [],
            'threat' => [
                'nature' => 'a tall whistle-thin silhouette in the grass',
                'stages' => [
                    ['stage' => 'hint', 'descriptor' => 'a maybe-shape among the distant trees'],
                ],
            ],
        ]);
    }

    /**
     * @param  list<string>  $characterSlugs
     */
    private function shot(
        int $order = 1,
        string $subject = 'environment',
        ?string $threatStage = null,
        array $characterSlugs = [],
    ): Shot {
        return new Shot(
            order: $order,
            sceneOrder: 1,
            start: 0.0,
            end: 4.0,
            sourceText: 'The door closed behind me in the empty hall.',
            framing: 'wide establishing',
            motion: 'static',
            subject: $subject,
            threatStage: $threatStage,
            description: 'a dim hallway vanishing into fog at dusk',
            characterSlugs: $characterSlugs,
            imagePath: null,
        );
    }
}
