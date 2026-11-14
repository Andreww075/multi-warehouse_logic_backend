<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Base de las excepciones de negocio del dominio de stock.
 *
 * La Constitución (Art. IV.1) prohíbe que el dominio conozca HTTP: los códigos
 * de estado se deciden en un único punto de mapeo (App\Support\DomainExceptionMapper,
 * invocado desde el exception handler). Esta clase solo transporta el contexto
 * estructurado que exige RF-13 (Art. IV.3).
 */
abstract class AbstractDomainException extends RuntimeException
{
    /** @var array<string, string|int|null> */
    private array $context = [];

    /**
     * Adjunta contexto estructurado para trazabilidad (RF-13).
     *
     * @param  array<string, string|int|null>  $context
     */
    public function withContext(array $context): static
    {
        $this->context = $context;

        return $this;
    }

    /**
     * @return array<string, string|int|null>
     */
    public function context(): array
    {
        return $this->context;
    }
}
