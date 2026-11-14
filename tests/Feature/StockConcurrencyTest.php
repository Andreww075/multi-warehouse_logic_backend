<?php

declare(strict_types=1);

use App\Models\InventoryMovement;
use App\Support\StockFixture;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * T-24 (M7): Test de concurrencia OBLIGATORIO (Spec §6.1.1, Plan §4.2).
 *
 * Pool de 20 procesos CLI reales sobre stock 10, keys únicas, quantity 1.
 * Cada proceso ejecuta la deducción contra la misma BD (pgsql) y Redis
 * (Memurai) que el entorno de tests, y reporta su estado vía JSON.
 */
it('passes the mandatory concurrency test: 20 real CLI processes vs stock 10 (Spec §6.1.1 / Plan §4.2)', function (): void {
    [$warehouse, $product, $inventory] = StockFixture::create(10, 0);

    $processes = [];
    $start = microtime(true);

    for ($i = 0; $i < 20; $i++) {
        $process = new Process(
            command: [PHP_BINARY, base_path('tests/Concurrency/DeductStockProcess.php'), (string) json_encode([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 1,
                'idempotency_key' => (string) Str::uuid(),
                'correlation_id' => (string) Str::uuid(),
            ])],
            cwd: base_path(),
            env: [
                'APP_ENV' => 'testing',
                'APP_DEBUG' => 'true',
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => '127.0.0.1',
                'DB_PORT' => '5432',
                'DB_DATABASE' => 'inventory_db_test',
                'DB_USERNAME' => 'postgres',
                'DB_PASSWORD' => env('DB_PASSWORD') ?? '',
                'REDIS_HOST' => '127.0.0.1',
                'REDIS_PORT' => '6379',
                'REDIS_CLIENT' => 'predis',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
            ],
            timeout: 120,
        );

        $process->start();
        $processes[] = $process;
    }

    foreach ($processes as $process) {
        $process->wait();
    }

    $elapsed = microtime(true) - $start;

    $statuses = [];
    foreach ($processes as $process) {
        $payload = json_decode((string) $process->getOutput(), true);
        $statuses[] = is_array($payload) && isset($payload['status']) ? (string) $payload['status'] : 'error';
    }

    $successes = 0;
    $insufficient = 0;
    $errores = 0;

    foreach ($statuses as $status) {
        if ($status === 'ok') {
            $successes++;
        } elseif ($status === 'insufficient') {
            $insufficient++;
        } else {
            $errores++;
        }
    }

    expect($successes)->toBe(10)
        ->and($insufficient)->toBe(10)
        ->and($errores)->toBe(0)
        ->and($elapsed)->toBeLessThan(90.0);

    expect($inventory->refresh()->on_hand)->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(10);
});
