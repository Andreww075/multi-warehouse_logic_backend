<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Stock\DeductStockCommand;
use App\Support\CorrelationId;
use Illuminate\Foundation\Http\FormRequest;

/**
 * RF-02: validación de entrada del endpoint de deducción.
 *
 * `authorize()` retorna true porque la política es "authenticated única"
 * (RF-01): la autenticación Sanctum es obligatoria por middleware de ruta,
 * no hay reglas de autorización por rol ni por scope.
 */
final class DeductStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /**
     * Mapeo explícito a comando de dominio: nunca `$request->all()` crudo
     * (Art. II.2). `validated()` retorna mixed por contrato del framework;
     * el moldeado ocurre una única vez, en esta frontera HTTP.
     */
    public function toCommand(): DeductStockCommand
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return new DeductStockCommand(
            productId: (string) $validated['product_id'],
            warehouseId: (string) $validated['warehouse_id'],
            quantity: (int) $validated['quantity'],
            idempotencyKey: (string) $validated['idempotency_key'],
            correlationId: CorrelationId::fromAttributes($this),
        );
    }
}
