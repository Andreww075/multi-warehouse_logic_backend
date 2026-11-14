<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * RF-08 / RF-07a: stock disponible insuficiente, o fila de inventario inexistente.
 * Mapeo HTTP (decidido en el handler): 409 Conflict.
 */
final class InsufficientStockException extends AbstractDomainException {}
