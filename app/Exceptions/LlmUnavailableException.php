<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * El proveedor no está disponible: saturado, caído, sin credencial válida o sin red. Es el único
 * fallo que justifica ir al respaldo, porque un JSON inválido o un schema mal escrito fallarían
 * igual en el otro proveedor.
 */
final class LlmUnavailableException extends LlmGenerationException {}
