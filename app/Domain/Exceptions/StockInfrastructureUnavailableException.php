<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * RF-15: Redis no es alcanzable (fallo de conexión, no de contención).
 * Mapeo HTTP (decidido en el handler): 503 Service Unavailable + Retry-After: 5.
 */
final class StockInfrastructureUnavailableException extends AbstractDomainException {}
