<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    /**
     * Define the model's default state (movimiento outbound de auditoría).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_id' => fn () => Warehouse::factory()->create()->getKey(),
            'product_id' => fn () => Product::factory()->create()->getKey(),
            'type' => 'outbound',
            'quantity' => 1,
            'quantity_before' => 0,
            'quantity_after' => 0,
            'idempotency_key' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
        ];
    }
}
