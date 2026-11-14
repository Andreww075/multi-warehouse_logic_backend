<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeductStockAction;
use App\Domain\Stock\DeductStockResult;
use App\Http\Requests\DeductStockRequest;
use App\Http\Resources\InventoryDeductionResource;
use App\Http\Resources\InventoryMovementReplayResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Controlador delgado (Art. III): valida vía FormRequest, delega en el
 * Action de dominio y presenta vía Resource. Sin lógica de negocio aquí.
 *
 * Dos desenlaces, ambos 200 (contratos planos, Art. IV.1):
 * - deducción nueva → RF-19 (estado resultante + movement_id);
 * - replay idempotente → RF-10 (datos persistidos de la primera ejecución).
 */
final class InventoryController extends Controller
{
    /**
     * POST /api/v1/inventories/deduct (RF-02).
     */
    public function deduct(DeductStockRequest $request, DeductStockAction $action): JsonResource
    {
        $outcome = $action->execute($request->toCommand());

        return $outcome instanceof DeductStockResult
            ? new InventoryDeductionResource($outcome)
            : new InventoryMovementReplayResource($outcome);
    }
}
