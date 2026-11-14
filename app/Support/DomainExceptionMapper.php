<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Exceptions\AbstractDomainException;
use App\Domain\Exceptions\IdempotencyKeyConflictException;
use App\Domain\Exceptions\InsufficientStockException;
use App\Domain\Exceptions\ProductInactiveException;
use App\Domain\Exceptions\ProductNotFoundException;
use App\Domain\Exceptions\StockInfrastructureUnavailableException;
use App\Domain\Exceptions\StockLockAcquisitionException;
use App\Domain\Exceptions\WarehouseInactiveException;
use App\Domain\Exceptions\WarehouseNotFoundException;
use ReflectionClass;

/**
 * Único punto de mapeo dominio → HTTP (Art. IV.1 de la Constitución).
 * El dominio lanza excepciones sin conocer HTTP; aquí se decide el código.
 */
final class DomainExceptionMapper
{
    /**
     * @return array{status: int, code: string, headers: array<string, string>}
     */
    public static function map(AbstractDomainException $exception): array
    {
        $status = match ($exception::class) {
            ProductNotFoundException::class,
            WarehouseNotFoundException::class => 404,
            WarehouseInactiveException::class,
            ProductInactiveException::class,
            InsufficientStockException::class,
            // RF-17
            IdempotencyKeyConflictException::class => 409,
            StockLockAcquisitionException::class,
            StockInfrastructureUnavailableException::class => 503,
            // Excepción de dominio sin mapeo declarado: fallar ruidoso, no 409 silencioso.
            default => 500,
        };

        $headers = $exception instanceof StockLockAcquisitionException
            || $exception instanceof StockInfrastructureUnavailableException
            ? ['Retry-After' => '5']
            : [];

        return [
            'status' => $status,
            'code' => (new ReflectionClass($exception))->getShortName(),
            'headers' => $headers,
        ];
    }
}
