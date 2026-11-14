<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * RF-09: el lock Redis no se adquirió tras agotar la espera bloqueante.
 * Mapeo HTTP (decidido en el handler): 503 Service Unavailable + Retry-After: 5.
 */
final class StockLockAcquisitionException extends AbstractDomainException {}
