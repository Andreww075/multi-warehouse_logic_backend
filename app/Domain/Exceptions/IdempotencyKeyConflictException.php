<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * RF-17: la idempotency_key ya consumida llegó con un par (warehouse, product) distinto.
 * Mapeo HTTP (decidido en el handler): 409 Conflict.
 */
final class IdempotencyKeyConflictException extends AbstractDomainException {}
