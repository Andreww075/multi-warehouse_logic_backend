<?php

declare(strict_types=1);

use App\Models\Inventory;
use App\Support\StockFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a deterministic stock fixture with the requested stock (T-19)', function (): void {
    [$warehouse, $product, $inventory] = StockFixture::create(10, 2);

    expect($warehouse->is_active)->toBeTrue()
        ->and($product->is_active)->toBeTrue()
        ->and($inventory)->toBeInstanceOf(Inventory::class)
        ->and($inventory->on_hand)->toBe(10)
        ->and($inventory->reserved)->toBe(2)
        ->and($inventory->available())->toBe(8);
});
