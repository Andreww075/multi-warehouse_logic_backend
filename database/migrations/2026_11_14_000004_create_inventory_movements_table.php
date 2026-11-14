<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('warehouse_id')->constrained('warehouses');
            $table->foreignUuid('product_id')->constrained('products');
            $table->string('type');
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('quantity_before');
            $table->unsignedBigInteger('quantity_after');
            $table->uuid('idempotency_key')->unique();
            $table->uuid('correlation_id');
            // Auditoria append-only: solo created_at, sin updated_at (Spec F1 §3).
            $table->timestamp('created_at')->useCurrent();
            // Indice de consulta para auditoria futura (Spec F1 §3).
            $table->index(['warehouse_id', 'product_id'], 'inventory_movements_warehouse_product_index');
        });

        // Restricciones a nivel BD (Spec F1 §3): quantity estrictamente positiva
        // y tipo de movimiento restringido a outbound en esta version (Plan P-05/P-08).
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT check_inventory_movements_quantity_positive CHECK (quantity > 0)');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT check_inventory_movements_type_outbound CHECK (type = 'outbound')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
