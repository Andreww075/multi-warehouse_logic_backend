<?php

declare(strict_types=1);

use App\Actions\DeductStockAction;
use App\Domain\Exceptions\IdempotencyKeyConflictException;
use App\Domain\Exceptions\InsufficientStockException;
use App\Domain\Exceptions\StockInfrastructureUnavailableException;
use App\Domain\Exceptions\StockLockAcquisitionException;
use App\Domain\Stock\DeductStockCommand;
use App\Domain\Stock\DeductStockResult;
use App\Domain\Stock\IdempotentReplayResult;
use App\Infrastructure\Stock\StockLockManager;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Cache\RedisLock;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const DEDUCT_STOCK_ENDPOINT = '/api/v1/inventories/deduct';
const MOVEMENT_QUANTITY_CHECK = '22222222-2222-4222-8222-222222222222';

function stockFixture(int $onHand, int $reserved = 0, string $sku = 'SKU-IPHONE-15', bool $withInventory = true): array
{
    $warehouse = Warehouse::query()->create(['name' => 'CDMX Norte', 'is_active' => true]);
    $product = Product::query()->create(['sku' => $sku, 'name' => 'iPhone 15', 'is_active' => true]);

    if (! $withInventory) {
        return [$warehouse, $product, null];
    }

    $inventory = Inventory::query()->create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'on_hand' => $onHand,
        'reserved' => $reserved,
    ]);

    return [$warehouse, $product, $inventory];
}

function commandFor(Warehouse $warehouse, Product $product, int $quantity, string $idempotencyKey): DeductStockCommand
{
    return new DeductStockCommand(
        productId: $product->id,
        warehouseId: $warehouse->id,
        quantity: $quantity,
        idempotencyKey: $idempotencyKey,
        correlationId: MOVEMENT_QUANTITY_CHECK,
    );
}

it('deducts stock with exact before/after, inside the Redis lock (RF-07, RF-19, Art. I.2)', function (): void {
    [$warehouse, $product, $inventory] = stockFixture(onHand: 10, reserved: 2);
    $idempotencyKey = (string) Str::uuid();

    // Sonda: ¿el lock está en Redis durante las consultas transaccionales?
    $lockHeld = [];
    DB::listen(function (QueryExecuted $query) use (&$lockHeld, $product, $warehouse): void {
        $lockHeld[] = (bool) Redis::connection()->exists(StockLockManager::key($product->sku, $warehouse->id));
    });

    $result = app(DeductStockAction::class)->execute(commandFor($warehouse, $product, 3, $idempotencyKey));

    expect($result)->toBeInstanceOf(DeductStockResult::class)
        ->and($result->onHand)->toBe(7)
        ->and($result->reserved)->toBe(2)
        ->and($result->available)->toBe(5);

    $inventory->refresh();
    expect($inventory->on_hand)->toBe(7);

    $movement = InventoryMovement::query()->where('idempotency_key', $idempotencyKey)->sole();
    expect($result->movementId)->toBe($movement->id)
        ->and($movement->quantity)->toBe(3)
        ->and($movement->quantity_before)->toBe(10)
        ->and($movement->quantity_after)->toBe(7)
        ->and($movement->type)->toBe('outbound')
        ->and($movement->correlation_id)->toBe(MOVEMENT_QUANTITY_CHECK)
        ->and($movement->warehouse_id)->toBe($warehouse->id)
        ->and($movement->product_id)->toBe($product->id)
        // Art. I.1: el stock solo se lee/muta bajo lock (la sonda lo vio en Redis).
        ->and($lockHeld)->toContain(true);
});

it('throws InsufficientStockException leaving stock and history intact (RF-08)', function (): void {
    [$warehouse, $product, $inventory] = stockFixture(onHand: 2);
    $idempotencyKey = (string) Str::uuid();

    try {
        app(DeductStockAction::class)->execute(commandFor($warehouse, $product, 5, $idempotencyKey));
    } catch (InsufficientStockException $exception) {
        expect($exception->context()['available'])->toBe(2)
            ->and($exception->context()['quantity'])->toBe(5);
    }

    $inventory->refresh();
    expect($inventory->on_hand)->toBe(2)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

it('treats a missing inventory row as zero stock without creating it (RF-07a / P-07)', function (): void {
    [$warehouse, $product] = stockFixture(onHand: 10, withInventory: false);

    $idempotencyKey = (string) Str::uuid();

    expect(fn (): DeductStockResult => app(DeductStockAction::class)->execute(commandFor($warehouse, $product, 1, $idempotencyKey)))
        ->toThrow(InsufficientStockException::class);

    expect(Inventory::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

it('replays with the persisted first-execution data and deducts nothing again (RF-10 / P-06)', function (): void {
    [$warehouse, $product] = stockFixture(onHand: 10);
    $idempotencyKey = (string) Str::uuid();
    $action = app(DeductStockAction::class);

    $first = $action->execute(commandFor($warehouse, $product, 4, $idempotencyKey));

    $second = $action->execute(commandFor($warehouse, $product, 4, $idempotencyKey));

    expect($first)->toBeInstanceOf(DeductStockResult::class)
        ->and($second)->toBeInstanceOf(IdempotentReplayResult::class)
        ->and($second->movementId)->toBe($first->movementId)
        ->and($second->quantity)->toBe(4)
        ->and($second->quantityBefore)->toBe(10)
        ->and($second->quantityAfter)->toBe(6)
        ->and($second->createdAt)->toBeString()->not->toBe('');

    expect(InventoryMovement::query()->count())->toBe(1)
        ->and(Inventory::query()->sole()->on_hand)->toBe(6);
});

it('rejects a consumed idempotency_key reused with a different pair (RF-17)', function (): void {
    [$warehouseA, $productA] = stockFixture(onHand: 10, sku: 'SKU-A');
    $warehouseB = Warehouse::query()->create(['name' => 'Guadalajara Sur', 'is_active' => true]);
    $productB = Product::query()->create(['sku' => 'SKU-B', 'name' => 'iPhone 16', 'is_active' => true]);
    Inventory::query()->create([
        'warehouse_id' => $warehouseB->id,
        'product_id' => $productB->id,
        'on_hand' => 10,
        'reserved' => 0,
    ]);
    $idempotencyKey = (string) Str::uuid();

    app(DeductStockAction::class)->execute(commandFor($warehouseA, $productA, 1, $idempotencyKey));

    expect(fn (): DeductStockResult => app(DeductStockAction::class)->execute(commandFor($warehouseB, $productB, 1, $idempotencyKey)))
        ->toThrow(IdempotencyKeyConflictException::class);

    expect(InventoryMovement::query()->count())->toBe(1)
        ->and(Inventory::query()->where('warehouse_id', $warehouseA->id)->firstOrFail()->on_hand)->toBe(9);
});

it('reuses the idempotency_key after a business failure without movement (RF-11)', function (): void {
    [$warehouse, $product] = stockFixture(onHand: 3);
    $idempotencyKey = (string) Str::uuid();
    $action = app(DeductStockAction::class);

    // Primer intento: stock insuficiente → 409, sin movimiento (la clave queda libre).
    expect(fn (): DeductStockResult => $action->execute(commandFor($warehouse, $product, 5, $idempotencyKey)))
        ->toThrow(InsufficientStockException::class);

    expect(InventoryMovement::query()->count())->toBe(0);

    // Segundo intento con la MISMA clave y cantidad viable: procesa como nuevo.
    $retry = $action->execute(commandFor($warehouse, $product, 2, $idempotencyKey));

    expect($retry)->toBeInstanceOf(DeductStockResult::class)
        ->and($retry->onHand)->toBe(1)
        ->and(InventoryMovement::query()->count())->toBe(1);
});

it('serves RF-19 on the first call and RF-10 replay on the second, via HTTP', function (): void {
    Sanctum::actingAs(User::factory()->create());
    [$warehouse, $product] = stockFixture(onHand: 10);
    $idempotencyKey = (string) Str::uuid();
    $correlationId = '33333333-3333-4333-8333-333333333333';
    $payload = [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 2,
        'idempotency_key' => $idempotencyKey,
    ];

    $first = $this->postJson(DEDUCT_STOCK_ENDPOINT, $payload, ['X-Correlation-ID' => $correlationId]);

    $first->assertStatus(200)->assertExactJson([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'on_hand' => 8,
        'reserved' => 0,
        'available' => 8,
        'movement_id' => $first->json('movement_id'),
    ]);

    $second = $this->postJson(DEDUCT_STOCK_ENDPOINT, $payload, ['X-Correlation-ID' => $correlationId]);

    $second->assertStatus(200)->assertExactJson([
        'movement_id' => $first->json('movement_id'),
        'quantity' => 2,
        'quantity_before' => 10,
        'quantity_after' => 8,
        'created_at' => $second->json('created_at'),
    ]);

    // El replay no refleja compras posteriores ni incluye estado vigente.
    expect($second->json('created_at'))->toBeString()->not->toBe('')
        ->and($second->json())->not->toHaveKey('on_hand');

    $movement = InventoryMovement::query()->where('idempotency_key', $idempotencyKey)->sole();
    expect($movement->correlation_id)->toBe($correlationId)
        ->and(InventoryMovement::query()->count())->toBe(1)
        ->and(Inventory::query()->sole()->on_hand)->toBe(8);
});

it('renders the lock-acquisition failure as 503 with Retry-After when the lock is held (RF-09 / T-23)', function (): void {
    [$warehouse, $product, $inventory] = stockFixture(10, 0);

    $held = new RedisLock(Redis::connection(), StockLockManager::key($product->sku, $warehouse->id), 10);
    expect($held->get())->toBeTrue();

    $exception = null;

    try {
        app(DeductStockAction::class)->execute(commandFor($warehouse, $product, 1, (string) Str::uuid()));
    } catch (StockLockAcquisitionException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(StockLockAcquisitionException::class);

    $response = app(ExceptionHandler::class)->render(Request::create(DEDUCT_STOCK_ENDPOINT, 'POST'), $exception);

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Retry-After'))->toBe('5');

    $held->release();
});

it('renders the infrastructure-unavailable failure as 503 with Retry-After when Redis is unreachable (RF-15 / T-23)', function (): void {
    [$warehouse, $product, $inventory] = stockFixture(10, 0);

    config(['database.redis.default.port' => 6399]);
    $this->app->forgetInstance('redis');

    $exception = null;

    try {
        app(DeductStockAction::class)->execute(commandFor($warehouse, $product, 1, (string) Str::uuid()));
    } catch (StockInfrastructureUnavailableException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(StockInfrastructureUnavailableException::class);

    $response = app(ExceptionHandler::class)->render(Request::create(DEDUCT_STOCK_ENDPOINT, 'POST'), $exception);

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Retry-After'))->toBe('5');

    // Restauración del estado de la suite tras la simulación de caída.
    config(['database.redis.default.port' => 6379]);
    $this->app->forgetInstance('redis');
});
