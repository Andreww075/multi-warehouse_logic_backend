<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Str;

/**
 * Helper de fixture de stock (M6 / T-19, Plan P-06/P-07, Spec §2.4).
 *
 * Crea un warehouse activo, un producto activo y un inventario con el stock
 * solicitado. Confinado a fixtures: ninguna otra capa de la app crea filas de
 * `inventories` (la creación es responsabilidad del inbound en F2).
 *
 * @return array{0: Warehouse, 1: Product, 2: Inventory}
 */
final class StockFixture
{
    /**
     * Crea un warehouse activo, un producto activo y un inventario con stock N.
     *
     * @return array{0: Warehouse, 1: Product, 2: Inventory}
     */
    public static function create(int $onHand, int $reserved = 0): array
    {
        $warehouse = Warehouse::factory()->create([
            'name' => 'Almacén Fixture '.(string) Str::uuid(),
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'sku' => 'SKU-FIXTURE-'.(string) Str::uuid(),
            'name' => 'Producto de Prueba',
            'is_active' => true,
        ]);

        $inventory = Inventory::factory()
            ->stock($onHand, $reserved)
            ->create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
            ]);

        return [$warehouse, $product, $inventory];
    }
}
