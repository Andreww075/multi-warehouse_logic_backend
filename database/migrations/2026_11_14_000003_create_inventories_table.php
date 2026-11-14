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
        Schema::create('inventories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('warehouse_id')->constrained('warehouses');
            $table->foreignUuid('product_id')->constrained('products');
            $table->unsignedBigInteger('on_hand')->default(0);
            $table->unsignedBigInteger('reserved')->default(0);
            $table->unique(['warehouse_id', 'product_id']);
            $table->timestamps();
        });

        // Restricciones de integridad a nivel BD (Spec F1 §3, resuelve C6 del QA):
        // la garantía de stock no negativo no depende solo de la aplicación.
        DB::statement('ALTER TABLE inventories ADD CONSTRAINT check_inventories_on_hand_non_negative CHECK (on_hand >= 0)');
        DB::statement('ALTER TABLE inventories ADD CONSTRAINT check_inventories_reserved_non_negative CHECK (reserved >= 0)');
        DB::statement('ALTER TABLE inventories ADD CONSTRAINT check_inventories_reserved_le_on_hand CHECK (reserved <= on_hand)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
