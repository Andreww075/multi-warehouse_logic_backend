<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * RF-14: el producto existe pero está descontinuado (is_active = false).
 * Mapeo HTTP (decidido en el handler): 409 Conflict.
 */
final class ProductInactiveException extends AbstractDomainException {}
