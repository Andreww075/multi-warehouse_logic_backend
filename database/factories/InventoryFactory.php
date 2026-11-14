<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inventory>
 */
class InventoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_id' => fn () => Warehouse::factory()->create()->getKey(),
            'product_id' => fn () => Product::factory()->create()->getKey(),
            'on_hand' => 0,
            'reserved' => 0,
        ];
    }

    /**
     * Estado de stock para fixtures (T-19 / Plan P-07): stock físico y reservado.
     */
    public function stock(int $onHand, int $reserved = 0): static
    {
        return $this->state(fn (array $attributes): array => [
            'on_hand' => $onHand,
            'reserved' => $reserved,
        ]);
    }
}
