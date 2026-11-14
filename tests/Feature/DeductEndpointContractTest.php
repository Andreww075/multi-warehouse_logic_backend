<?php

declare(strict_types=1);

use App\Actions\DeductStockAction;
use App\Domain\Stock\DeductStockCommand;
use App\Domain\Stock\DeductStockResult;
use App\Models\User;
use App\Support\CorrelationId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const DEDUCT_ENDPOINT = '/api/v1/inventories/deduct';

beforeEach(function (): void {
    Sanctum::actingAs(User::factory()->create());
});

it('rejects unauthenticated requests with 401 (RF-01)', function (): void {
    // Anula la autenticación de actingAs del beforeEach.
    auth()->forgetGuards();

    $response = $this->postJson(DEDUCT_ENDPOINT, [
        'product_id' => (string) Str::uuid(),
        'warehouse_id' => (string) Str::uuid(),
        'quantity' => 1,
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $response->assertStatus(401);
});

it('rejects invalid payloads with 422 per-field errors (RF-02)', function (): void {
    $response = $this->postJson(DEDUCT_ENDPOINT, [
        'product_id' => 'not-a-uuid',
        'warehouse_id' => null,
        'quantity' => 0,
        'idempotency_key' => 'also-not-uuid',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['product_id', 'warehouse_id', 'quantity', 'idempotency_key']);
});

it('rejects quantity below the minimum with 422 (RF-02)', function (): void {
    $response = $this->postJson(DEDUCT_ENDPOINT, [
        'product_id' => (string) Str::uuid(),
        'warehouse_id' => (string) Str::uuid(),
        'quantity' => -3,
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

it('responds with the exact flat contract on success (RF-13)', function (): void {
    $productId = (string) Str::uuid();
    $warehouseId = (string) Str::uuid();
    $idempotencyKey = (string) Str::uuid();

    $stub = new class extends DeductStockAction
    {
        public static ?DeductStockCommand $last = null;

        public function __construct()
        {
            // El stub no necesita el lock manager; solo intercambiar execute().
        }

        public function execute(DeductStockCommand $command): DeductStockResult
        {
            self::$last = $command;

            return new DeductStockResult(
                productId: $command->productId,
                warehouseId: $command->warehouseId,
                onHand: 10,
                reserved: 3,
                available: 7,
                movementId: '44444444-4444-4444-4444-444444444444',
            );
        }
    };

    $this->app->instance(DeductStockAction::class, $stub);

    $response = $this->postJson(DEDUCT_ENDPOINT, [
        'product_id' => $productId,
        'warehouse_id' => $warehouseId,
        'quantity' => 3,
        'idempotency_key' => $idempotencyKey,
    ]);

    $response->assertStatus(200)->assertExactJson([
        'product_id' => $productId,
        'warehouse_id' => $warehouseId,
        'on_hand' => 10,
        'reserved' => 3,
        'available' => 7,
        'movement_id' => '44444444-4444-4444-4444-444444444444',
    ]);

    // El FormRequest mapea exactamente el payload validado al comando tipado.
    expect($stub::$last)->toBeInstanceOf(DeductStockCommand::class)
        ->and($stub::$last->productId)->toBe($productId)
        ->and($stub::$last->warehouseId)->toBe($warehouseId)
        ->and($stub::$last->quantity)->toBe(3)
        ->and($stub::$last->idempotencyKey)->toBe($idempotencyKey);
});

it('echoes the correlation header on the real route (RF-18)', function (): void {
    $stub = new class extends DeductStockAction
    {
        public function __construct()
        {
            // El stub no necesita el lock manager; solo intercambiar execute().
        }

        public function execute(DeductStockCommand $command): DeductStockResult
        {
            return new DeductStockResult($command->productId, $command->warehouseId, 10, 0, 10, (string) Str::uuid());
        }
    };

    $this->app->instance(DeductStockAction::class, $stub);

    $correlationId = (string) Str::uuid();

    $response = $this->postJson(
        DEDUCT_ENDPOINT,
        [
            'product_id' => (string) Str::uuid(),
            'warehouse_id' => (string) Str::uuid(),
            'quantity' => 1,
            'idempotency_key' => (string) Str::uuid(),
        ],
        [CorrelationId::HEADER => $correlationId],
    );

    $response->assertStatus(200)
        ->assertHeader(CorrelationId::HEADER, $correlationId);
});

it('rejects a non-integer quantity with 422 (RF-02, T-20)', function (): void {
    $response = $this->postJson(DEDUCT_ENDPOINT, [
        'product_id' => (string) Str::uuid(),
        'warehouse_id' => (string) Str::uuid(),
        'quantity' => 1.5,
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

it('generates a fresh correlation id when the header is absent on the real route (RF-18, T-21)', function (): void {
    $stub = new class extends DeductStockAction
    {
        public function __construct()
        {
            // El stub no necesita el lock manager; solo intercambiar execute().
        }

        public function execute(DeductStockCommand $command): DeductStockResult
        {
            return new DeductStockResult($command->productId, $command->warehouseId, 10, 0, 10, (string) Str::uuid());
        }
    };

    $this->app->instance(DeductStockAction::class, $stub);

    $response = $this->postJson(DEDUCT_ENDPOINT, [
        'product_id' => (string) Str::uuid(),
        'warehouse_id' => (string) Str::uuid(),
        'quantity' => 1,
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $response->assertStatus(200);

    $responseCorrelationId = (string) $response->headers->get(CorrelationId::HEADER);

    expect(Str::isUuid($responseCorrelationId))->toBeTrue();
});
