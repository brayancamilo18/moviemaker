<?php

declare(strict_types=1);

namespace App\Services\Llm;

/**
 * Trabajo que se le pide al LLM. Cada proveedor resuelve con esto su modelo y su tope de tokens,
 * porque un identificador de modelo de Gemini no significa nada en Anthropic y al revés.
 *
 * El valor de cada caso es la clave que se busca en `stories.llm.<proveedor>.models` y en
 * `stories.llm.<proveedor>.max_tokens`.
 */
enum LlmTask: string
{
    case Script = 'script';

    case Review = 'review';

    case VisualBible = 'visual_bible';

    case ShotDirection = 'shot_direction';

    case SfxDirection = 'sfx_direction';
}
