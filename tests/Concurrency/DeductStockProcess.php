<?php

declare(strict_types=1);

use App\Actions\DeductStockAction;
use App\Domain\Exceptions\InsufficientStockException;
use App\Domain\Exceptions\StockInfrastructureUnavailableException;
use App\Domain\Exceptions\StockLockAcquisitionException;
use App\Domain\Stock\DeductStockCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$data = json_decode((string) ($argv[1] ?? '{}'), true);

if (! is_array($data)) {
    echo json_encode(['status' => 'error', 'message' => 'payload invalido']);
    exit(1);
}

$command = new DeductStockCommand(
    productId: (string) $data['product_id'],
    warehouseId: (string) $data['warehouse_id'],
    quantity: (int) $data['quantity'],
    idempotencyKey: (string) $data['idempotency_key'],
    correlationId: (string) ($data['correlation_id'] ?? Str::uuid()),
);

try {
    $result = app(DeductStockAction::class)->execute($command);

    echo json_encode(['status' => 'ok', 'movement_id' => $result->movementId]);
} catch (InsufficientStockException $exception) {
    echo json_encode(['status' => 'insufficient']);
} catch (StockLockAcquisitionException $exception) {
    echo json_encode(['status' => 'lock_timeout']);
} catch (StockInfrastructureUnavailableException $exception) {
    echo json_encode(['status' => 'infra']);
} catch (Throwable $exception) {
    echo json_encode(['status' => 'error', 'message' => $exception->getMessage()]);
}
