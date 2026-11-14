<?php

declare(strict_types=1);

namespace App\Domain\Stock;

/**
 * Marcador de resultado de `DeductStockAction::execute()` (M5).
 *
 * Unión sellada de dos desenlaces, ambos con HTTP 200 pero contratos distintos:
 * - `DeductStockResult`      → deducción nueva (RF-19);
 * - `IdempotentReplayResult` → replay idempotente con datos de la primera
 *   ejecución (RF-10 / P-06).
 *
 * El controlador discrimina por `instanceof` y elige el Resource; el dominio
 * no conoce HTTP (Art. IV.1).
 */
interface DeductStockOutcome {}
