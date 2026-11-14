<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * RF-12 / Art. I.3 de la Constitución: `inventory_movements` es append-only.
     * La garantía vive en la base de datos (no solo en la aplicación): un trigger
     * rechaza UPDATE y DELETE a nivel de fila, y TRUNCATE a nivel de statement.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_inventory_movements_append_only() RETURNS trigger AS $body$
            BEGIN
                RAISE EXCEPTION 'inventory_movements es append-only: operacion % prohibida (Art. I.3)', TG_OP;
            END;
            $body$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_inventory_movements_append_only_row
            BEFORE UPDATE OR DELETE ON inventory_movements
            FOR EACH ROW
            EXECUTE FUNCTION enforce_inventory_movements_append_only();

            CREATE TRIGGER trg_inventory_movements_append_only_truncate
            BEFORE TRUNCATE ON inventory_movements
            FOR EACH STATEMENT
            EXECUTE FUNCTION enforce_inventory_movements_append_only();
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_inventory_movements_append_only_row ON inventory_movements;
            DROP TRIGGER IF EXISTS trg_inventory_movements_append_only_truncate ON inventory_movements;
            DROP FUNCTION IF EXISTS enforce_inventory_movements_append_only();
        SQL);
    }
};
