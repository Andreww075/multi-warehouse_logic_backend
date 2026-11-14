<?php

declare(strict_types=1);

use App\Actions\DeductStockAction;
use App\Domain\Exceptions\ProductInactiveException;
use App\Domain\Exceptions\ProductNotFoundException;
use App\Domain\Exceptions\WarehouseInactiveException;
use App\Domain\Exceptions\WarehouseNotFoundException;
use App\Domain\Stock\DeductStockCommand;
use App\Infrastructure\Stock\StockLockManager;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Factory de Redis que FALLA si se toca: prueba que las validaciones pre-lock
 * (RF-03/04/05/14, T-16) no adquieren lock ni transacción.
 */
function redisMustNotBeTouched(): Factory
{
    return new class implements Factory
    {
        public function connection($name = null): never
        {
            throw new RuntimeException('redis NO debe tocarse antes del lock (T-16)');
        }
    };
}

function preLockAction(): DeductStockAction
{
    return new DeductStockAction(new StockLockManager(redisMustNotBeTouched()));
}

function warehouseFixture(bool $active = true, string $name = 'CDMX Norte'): Warehouse
{
    return Warehouse::query()->create(['name' => $name, 'is_active' => $active]);
}

function productFixture(bool $active = true, string $sku = 'SKU-IPHONE-15'): Product
{
    return Product::query()->create(['sku' => $sku, 'name' => 'iPhone 15', 'is_active' => $active]);
}

function preLockCommand(string $productId, string $warehouseId): DeductStockCommand
{
    return new DeductStockCommand(
        productId: $productId,
        warehouseId: $warehouseId,
        quantity: 1,
        idempotencyKey: '11111111-1111-4111-8111-111111111111',
        correlationId: '22222222-2222-4222-8222-222222222222',
    );
}

it('throws ProductNotFoundException pre-lock without touching Redis (RF-03)', function (): void {
    $warehouse = warehouseFixture();

    preLockAction()->execute(preLockCommand('99999999-9999-4999-8999-999999999999', $warehouse->id));
})->throws(ProductNotFoundException::class);

it('throws WarehouseNotFoundException pre-lock for a missing warehouse (RF-04)', function (): void {
    $product = productFixture();

    preLockAction()->execute(preLockCommand($product->id, '99999999-9999-4999-8999-999999999998'));
})->throws(WarehouseNotFoundException::class);

it('checks the product before the warehouse: both missing gives ProductNotFound (RF-03 first)', function (): void {
    preLockAction()->execute(preLockCommand('99999999-9999-4999-8999-999999999999', '99999999-9999-4999-8999-999999999998'));
})->throws(ProductNotFoundException::class);

it('throws WarehouseInactiveException pre-lock for an inactive warehouse (RF-05)', function (): void {
    $warehouse = warehouseFixture(active: false);
    $product = productFixture();

    preLockAction()->execute(preLockCommand($product->id, $warehouse->id));
})->throws(WarehouseInactiveException::class);

it('throws ProductInactiveException pre-lock for an inactive product (RF-14)', function (): void {
    $warehouse = warehouseFixture();
    $product = productFixture(active: false);

    preLockAction()->execute(preLockCommand($product->id, $warehouse->id));
})->throws(ProductInactiveException::class);

it('mutates nothing when pre-lock validations fail (RF-03/04/05/14)', function (): void {
    $warehouse = warehouseFixture(active: false);
    $product = productFixture(active: false);

    foreach ([
        '99999999-9999-4999-8999-999999999999', // producto inexistente
        $product->id,                           // producto inactivo
    ] as $productId) {
        try {
            preLockAction()->execute(preLockCommand($productId, $warehouse->id));
        } catch (RuntimeException) {
            // 404/409 esperados; el Factory sondea Redis solo si se toca.
        }
    }

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and(Inventory::query()->count())->toBe(0);
});
