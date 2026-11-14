<?php

declare(strict_types=1);

use App\Actions\InboundStockAction;
use App\Domain\Stock\InboundStockCommand;
use App\Domain\Stock\InboundStockResult;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Support\StockFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates the inventory row on inbound when none exists and records the inbound movement (T-26)', function (): void {
    [$warehouse, $product, $inventory] = StockFixture::create(0, 0, 'SKU-IN-CREATE-'.(string) Str::uuid(), false);

    $result = app(InboundStockAction::class)->execute(
        new InboundStockCommand($product->id, $warehouse->id, 5, (string) Str::uuid()),
    );

    expect($result)->toBeInstanceOf(InboundStockResult::class)
        ->and($result->quantityBefore)->toBe(0)
        ->and($result->quantityAfter)->toBe(5);

    $fresh = Inventory::query()
        ->where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->first();

    expect($fresh)->not->toBeNull()
        ->and($fresh->on_hand)->toBe(5);
});

it('increments the existing inventory on inbound and records the inbound movement (T-26)', function (): void {
    [$warehouse, $product, $inventory] = StockFixture::create(10, 2, 'SKU-IN-UPDATE-'.(string) Str::uuid());

    $result = app(InboundStockAction::class)->execute(
        new InboundStockCommand($product->id, $warehouse->id, 4, (string) Str::uuid()),
    );

    expect($result)->toBeInstanceOf(InboundStockResult::class)
        ->and($result->quantityBefore)->toBe(10)
        ->and($result->quantityAfter)->toBe(14);

    $inventory->refresh();
    expect($inventory->on_hand)->toBe(14);

    $movement = InventoryMovement::query()
        ->where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->where('type', 'inbound')
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->quantity)->toBe(4)
        ->and($movement->quantity_before)->toBe(10)
        ->and($movement->quantity_after)->toBe(14);
});
