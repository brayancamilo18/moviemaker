<?php

declare(strict_types=1);

namespace App\Services\Llm;

use InvalidArgumentException;

/**
 * Traduce los schemas del repo, escritos en el dialecto de Gemini, al JSON Schema estricto que
 * pide `output_config.format` de Anthropic:
 *
 * - los tipos bajan a minúsculas (`OBJECT` → `object`),
 * - cada objeto declara `additionalProperties: false`, que Anthropic exige,
 * - y todo objeto sin `required` pasa a exigir todas sus propiedades, porque la hidratación del
 *   repo las da por presentes y con un schema estricto el modelo puede omitir legítimamente lo
 *   que no se le pide.
 *
 * Se quedan fuera las restricciones que Anthropic no admite (longitudes, mínimos y máximos), que
 * hoy ningún schema usa.
 */
final class AnthropicSchema
{
    /**
     * @var array<string, string>
     */
    private const TYPES = [
        'OBJECT' => 'object',
        'ARRAY' => 'array',
        'STRING' => 'string',
        'INTEGER' => 'integer',
        'NUMBER' => 'number',
        'BOOLEAN' => 'boolean',
    ];

    /**
     * @var list<string>
     */
    private const UNSUPPORTED = [
        'minLength',
        'maxLength',
        'minItems',
        'maxItems',
        'minimum',
        'maximum',
        'pattern',
        'format',
        'propertyOrdering',
        'nullable',
    ];

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function translate(array $schema): array
    {
        return self::node($schema, 'raíz');
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function node(array $node, string $path): array
    {
        $type = self::type($node, $path);
        $translated = ['type' => $type];

        if (isset($node['description']) && is_string($node['description'])) {
            $translated['description'] = $node['description'];
        }

        if (isset($node['enum']) && is_array($node['enum'])) {
            $translated['enum'] = array_values($node['enum']);
        }

        foreach (self::UNSUPPORTED as $key) {
            unset($node[$key]);
        }

        if ($type === 'object') {
            return $translated + self::object($node, $path);
        }

        if ($type === 'array') {
            return $translated + self::items($node, $path);
        }

        return $translated;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function object(array $node, string $path): array
    {
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];

        if ($properties === []) {
            throw new InvalidArgumentException("El objeto de {$path} no declara properties.");
        }

        $translated = [];

        foreach ($properties as $name => $property) {
            if (! is_array($property)) {
                throw new InvalidArgumentException("La propiedad {$path}.{$name} no es un objeto.");
            }

            $translated[$name] = self::node($property, "{$path}.{$name}");
        }

        $required = is_array($node['required'] ?? null) ? array_values($node['required']) : [];

        return [
            'properties' => $translated,
            'required' => $required === [] ? array_keys($translated) : $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function items(array $node, string $path): array
    {
        if (! is_array($node['items'] ?? null)) {
            throw new InvalidArgumentException("El array de {$path} no declara items.");
        }

        return ['items' => self::node($node['items'], "{$path}[]")];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function type(array $node, string $path): string
    {
        $type = is_string($node['type'] ?? null) ? strtoupper($node['type']) : '';

        if (! isset(self::TYPES[$type])) {
            throw new InvalidArgumentException(
                "El schema de {$path} tiene un tipo que Anthropic no admite: ".
                ($type === '' ? '(vacío)' : $type),
            );
        }

        return self::TYPES[$type];
    }
}
