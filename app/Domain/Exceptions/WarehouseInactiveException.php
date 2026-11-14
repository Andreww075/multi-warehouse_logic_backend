<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * RF-05: el almacén existe pero está inactivo.
 * Mapeo HTTP (decidido en el handler): 409 Conflict.
 */
final class WarehouseInactiveException extends AbstractDomainException {}
