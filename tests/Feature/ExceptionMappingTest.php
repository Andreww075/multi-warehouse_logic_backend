<?php

declare(strict_types=1);

use App\Domain\Exceptions\IdempotencyKeyConflictException;
use App\Domain\Exceptions\InsufficientStockException;
use App\Domain\Exceptions\ProductInactiveException;
use App\Domain\Exceptions\ProductNotFoundException;
use App\Domain\Exceptions\StockInfrastructureUnavailableException;
use App\Domain\Exceptions\StockLockAcquisitionException;
use App\Domain\Exceptions\WarehouseInactiveException;
use App\Domain\Exceptions\WarehouseNotFoundException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

it('maps every domain exception to its semantic HTTP status (RF-13 / Art. IV.1)', function (): void {
    $cases = [
        ProductNotFoundException::class => 404,
        WarehouseNotFoundException::class => 404,
        WarehouseInactiveException::class => 409,
        ProductInactiveException::class => 409,
        InsufficientStockException::class => 409,
        IdempotencyKeyConflictException::class => 409,
        StockLockAcquisitionException::class => 503,
        StockInfrastructureUnavailableException::class => 503,
    ];

    $handler = app(ExceptionHandler::class);

    foreach ($cases as $exceptionClass => $expectedStatus) {
        $request = Request::create('/api/v1/inventories/deduct', 'POST');
        $exception = new $exceptionClass('mensaje de prueba');

        $response = $handler->render($request, $exception);

        $payload = $response->getData(true);

        expect($response->getStatusCode())
            ->toBe($expectedStatus)
            ->and($payload['error'])
            ->toBe(class_basename($exceptionClass))
            ->and($payload['correlation_id'])
            ->toBeString();
    }
});

it('adds Retry-After header to infrastructure-level 503 responses (RF-09 / RF-15)', function (): void {
    $handler = app(ExceptionHandler::class);
    $request = Request::create('/api/v1/inventories/deduct', 'POST');

    foreach ([StockLockAcquisitionException::class, StockInfrastructureUnavailableException::class] as $class) {
        $response = $handler->render($request, new $class('timeout'));

        expect($response->getStatusCode())->toBe(503)
            ->and($response->headers->get('Retry-After'))->toBe('5');
    }
});

it('logs structured context for domain exceptions (RF-13)', function (): void {
    Log::shouldReceive('error')->once()->withArgs(function (string $channel, array $context): bool {
        return $channel === 'domain_exception'
            && isset($context['correlation_id'])
            && $context['exception'] === InsufficientStockException::class
            && $context['product_id'] === 'prod-123'
            && $context['warehouse_id'] === 'wh-456';
    });

    $request = Request::create('/api/v1/inventories/deduct', 'POST');
    $exception = (new InsufficientStockException('sin stock'))
        ->withContext(['product_id' => 'prod-123', 'warehouse_id' => 'wh-456']);

    $response = app(ExceptionHandler::class)->render($request, $exception);

    expect($response->getStatusCode())->toBe(409);
});
