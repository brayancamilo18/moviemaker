<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * El LLM contestó y la respuesta no sirve. No es final porque LlmUnavailableException la extiende:
 * quien solo quiera saber que la generación falló sigue capturando esta.
 */
class LlmGenerationException extends RuntimeException {}
