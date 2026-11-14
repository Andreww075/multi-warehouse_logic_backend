<?php

declare(strict_types=1);

use App\Models\InventoryMovement;
use App\Support\StockFixture;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('rejects UPDATE and DELETE on inventory_movements at the database level (RF-12 / T-22)', function (): void {
    [$warehouse, $product, $inventory] = StockFixture::create(10, 0);

    $movement = InventoryMovement::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'quantity_before' => 10,
        'quantity_after' => 8,
    ]);

    expect(fn (): bool => (bool) DB::table('inventory_movements')->where('id', $movement->id)->update(['quantity' => 3]))
        ->toThrow(QueryException::class);

    expect(fn (): bool => (bool) DB::table('inventory_movements')->where('id', $movement->id)->delete())
        ->toThrow(QueryException::class);
});

it('rejects invalid inventory states through the database CHECK constraints (RF-12 / T-22)', function (): void {
    [$warehouse, $product, $inventory] = StockFixture::create(10, 0);

    expect(fn (): bool => (bool) DB::table('inventories')->where('id', $inventory->id)->update(['on_hand' => -5]))
        ->toThrow(QueryException::class);
});
