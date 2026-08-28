<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Llm\AnthropicSchema;
use App\Services\Story\StorySchema;
use InvalidArgumentException;
use Tests\TestCase;

final class AnthropicSchemaTest extends TestCase
{
    public function test_the_types_come_down_to_lowercase_at_every_depth(): void
    {
        $translated = AnthropicSchema::translate([
            'type' => 'OBJECT',
            'properties' => [
                'scenes' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'order' => ['type' => 'INTEGER'],
                            'gain' => ['type' => 'NUMBER'],
                            'loop' => ['type' => 'BOOLEAN'],
                            'text' => ['type' => 'STRING'],
                        ],
                        'required' => ['order', 'text'],
                    ],
                ],
            ],
            'required' => ['scenes'],
        ]);

        $this->assertSame('object', $translated['type']);
        $this->assertSame('array', $translated['properties']['scenes']['type']);

        $item = $translated['properties']['scenes']['items'];

        $this->assertSame('object', $item['type']);
        $this->assertSame('integer', $item['properties']['order']['type']);
        $this->assertSame('number', $item['properties']['gain']['type']);
        $this->assertSame('boolean', $item['properties']['loop']['type']);
        $this->assertSame('string', $item['properties']['text']['type']);
    }

    public function test_every_object_closes_the_door_to_extra_properties(): void
    {
        $translated = AnthropicSchema::translate(StorySchema::get());

        $this->assertFalse($translated['additionalProperties']);
        $this->assertFalse($translated['properties']['scenes']['items']['additionalProperties']);
        $this->assertFalse(
            $translated['properties']['scenes']['items']['properties']['ambience']['additionalProperties'],
        );
    }

    public function test_an_object_without_required_ends_up_requiring_everything(): void
    {
        // Con un schema estricto, lo que no se pide el modelo puede omitirlo, y la hidratación del
        // repo da por presentes todas las propiedades que declara el schema.
        $translated = AnthropicSchema::translate([
            'type' => 'OBJECT',
            'properties' => [
                'query' => ['type' => 'STRING'],
                'intensity' => ['type' => 'STRING'],
            ],
        ]);

        $this->assertSame(['query', 'intensity'], $translated['required']);
    }

    public function test_a_declared_required_is_respected(): void
    {
        $translated = AnthropicSchema::translate([
            'type' => 'OBJECT',
            'properties' => [
                'query' => ['type' => 'STRING'],
                'intensity' => ['type' => 'STRING'],
            ],
            'required' => ['query'],
        ]);

        $this->assertSame(['query'], $translated['required']);
    }

    public function test_the_descriptions_and_the_enums_survive(): void
    {
        $translated = AnthropicSchema::translate([
            'type' => 'OBJECT',
            'properties' => [
                'verdict' => [
                    'type' => 'STRING',
                    'description' => 'One of three verdicts.',
                    'enum' => ['publish', 'revise', 'discard'],
                ],
            ],
        ]);

        $verdict = $translated['properties']['verdict'];

        $this->assertSame('One of three verdicts.', $verdict['description']);
        $this->assertSame(['publish', 'revise', 'discard'], $verdict['enum']);
    }

    public function test_the_restrictions_that_anthropic_rejects_are_dropped(): void
    {
        $translated = AnthropicSchema::translate([
            'type' => 'OBJECT',
            'properties' => [
                'tags' => [
                    'type' => 'ARRAY',
                    'minItems' => 2,
                    'maxItems' => 3,
                    'items' => [
                        'type' => 'STRING',
                        'minLength' => 3,
                        'pattern' => '^[a-z]+$',
                        'nullable' => false,
                    ],
                ],
            ],
        ]);

        $tags = $translated['properties']['tags'];

        $this->assertSame(['type', 'items'], array_keys($tags));
        $this->assertSame(['type'], array_keys($tags['items']));
    }

    public function test_an_unknown_type_is_named_with_its_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('raíz.scenes[].order');

        AnthropicSchema::translate([
            'type' => 'OBJECT',
            'properties' => [
                'scenes' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'order' => ['type' => 'TIMESTAMP'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_the_story_schema_keeps_its_required_list_and_its_nesting(): void
    {
        $translated = AnthropicSchema::translate(StorySchema::get());

        $this->assertSame(
            ['title', 'hook', 'description', 'tags', 'thumbnailPrompt', 'pronunciations', 'scenes'],
            $translated['required'],
        );
        $this->assertSame('string', $translated['properties']['tags']['items']['type']);
        $this->assertSame(
            ['order', 'narration', 'visualSummary', 'ambience'],
            $translated['properties']['scenes']['items']['required'],
        );
    }
}
