<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * F2 (T-26): el CHECK de `inventory_movements.type` se relaja para admitir
     * movimientos `inbound` (recepción de stock) además del `outbound` (F1).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT check_inventory_movements_type_outbound');

        DB::statement(
            "ALTER TABLE inventory_movements ADD CONSTRAINT check_inventory_movements_type_inbound_outbound CHECK (type IN ('inbound', 'outbound'))",
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT check_inventory_movements_type_inbound_outbound');

        DB::statement(
            "ALTER TABLE inventory_movements ADD CONSTRAINT check_inventory_movements_type_outbound CHECK (type = 'outbound')",
        );
    }
};
