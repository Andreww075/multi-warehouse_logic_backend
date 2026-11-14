# 📋 Spec F1 — inventory-service: Deducción de Stock (Checkout Simulado)

**Estado:** Aprobada · **Versión:** 1.1 · **Fecha:** 2026-11-12 · **Servicio:** `inventory-service`
**Cambios v1.1:** resolución de los 4 hallazgos 🔴, 6 🟡 y 6 ⚪ de la revisión QA (ver §7 Registro de cambios).

> Esta spec describe **solo el QUÉ y el POR QUÉ**. El CÓMO (código) se rige por `docs/constitution.md`.

---

## 1. Contexto y objetivo

**QUÉ:** Primera funcionalidad del backend: un endpoint que deduce stock de un producto en un almacén concreto, representando la venta de un artículo en un checkout.

**POR QUÉ:** La venta del último artículo bajo compra simultánea de múltiples usuarios es el escenario que justifica toda la arquitectura (Redis + transacciones atómicas). Construir primero esta rebanada obliga a implementar las invariantes de la Constitución (Art. I) en su forma más pura, y deja el molde de dominio (tablas, excepciones, FormRequests, Actions) que reutilizarán todos los features futuros.

---

## 2. Prerrequisitos de entorno (resuelve D1, D3)

Esta feature es **inejecutable** sin el siguiente entorno. Los criterios de finalización (§6) lo asumen:

1. **PostgreSQL ≥ 16** operativo y `DB_CONNECTION=pgsql` en `.env`. SQLite no es aceptable: no implementa bloqueos pesimistas de fila reales (`FOR UPDATE`), pilar de la defensa en profundidad (RF-07).
2. **Redis ≥ 7** operativo en la dirección de `.env`, con `REDIS_CLIENT=predis`.
3. **Laravel Sanctum** instalado vía Composer (requiere autorización explícita según gobernanza) para RF-01.
4. Datos de prueba pre-seedeados: los fixtures de los tests crean warehouses, products e inventories. La creación de filas de `inventories` NO es parte de esta feature (pertenece al futuro `inbound`); los tests la simulan vía seeders.

---

## 3. Modelo de datos

| Tabla | Propósito | Detalles clave |
|---|---|---|
| `warehouses` | Almacenes de la red | `id` (uuid PK), `name` (unique), `is_active` |
| `products` | Catálogo local mínimo (única fuente de verdad de productos para este servicio) | `id` (uuid PK), `sku` (unique), `name`, `is_active`. **Por qué:** Art. III.1 prohíbe consultar la BD de otro servicio; el servicio necesita validar existencia y vigencia localmente. *(resuelve C4)* |
| `inventories` | Stock por almacén y producto | `warehouse_id` (FK), `product_id` (FK), `on_hand` (stock físico, default 0), `reserved` (stock reservado, default 0; existe por decisión de diseño pero en esta spec siempre vale 0), `UNIQUE(warehouse_id, product_id)`. **Restricciones de integridad a nivel BD:** `CHECK (on_hand ≥ 0)`, `CHECK (reserved ≥ 0)` y `CHECK (reserved ≤ on_hand)`. **Por qué:** la garantía de stock no negativo no debe depender solo de la aplicación (Art. I). *(resuelve C6)* |
| `inventory_movements` | Auditoría **append-only** | `type` (`outbound` en esta spec), `quantity` (>0), `quantity_before`, `quantity_after`, `idempotency_key` (unique), `correlation_id`, `created_at`. **Sin `updated_at`**. **`quantity_before/after` se refieren SIEMPRE a `on_hand`** (stock físico); con `reserved = 0` en esta spec coinciden con `available`. *(resuelve A3)* |

**Decisión de idempotencia:** la deduplicación se resuelve con un `UNIQUE` sobre `idempotency_key` en `inventory_movements`. **Por qué:** hace la dedupe atómica por construcción y evita estado adicional. **Alcance declarado (resuelve B3):** solo las ejecuciones **exitosas** consumen la clave. Una clave cuyo intento anterior falló (409 stock insuficiente u otro error de negocio) **sigue siendo reutilizable**: el reintento tras un fallo es un nuevo intento de venta, no un duplicado.

---

## 4. Requisitos funcionales (EARS)

### Endpoint y entrada

- **RF-01 (Ubicuo):** El sistema deberá exponer `POST /api/v1/inventories/deduct` protegido con token Bearer (Sanctum) y una única política `authenticated`. *Por qué:* toda mutación de stock debe poder atribuirse a un actor; roles y multi-tenancy son features futuros.
- **RF-02 (Evento):** CUANDO llegue una petición a `POST /inventories/deduct`, EL SISTEMA DEBERÁ validarla mediante un `FormRequest` (campos: `product_id` uuid, `warehouse_id` uuid, `quantity` entero ≥1 y ≤1.000.000, `idempotency_key` uuid obligatoria) antes de invocar la capa de negocio. *Por qué:* Art. IV.2; la clave de idempotencia es obligatoria porque el reintentante (caller) no puede distinguir un timeout de un fallo.

### Validación de existencia y estado

- **RF-03 (Indeseado):** SI el `product_id` no existe en el catálogo local, ENTONCES EL SISTEMA DEBERÁ responder 404 con `ProductNotFoundException`, sin adquirir lock, sin abrir transacción y sin mutar nada. *Por qué:* fallo de referencia barato de detectar; no se debe pagar el coste de locks para una petición inválida.
- **RF-04 (Indeseado):** SI el `warehouse_id` no existe, ENTONCES EL SISTEMA DEBERÁ responder 404 con `WarehouseNotFoundException` (mismas condiciones que RF-03).
- **RF-05 (Indeseado):** SI el almacén existe pero `is_active = false`, ENTONCES EL SISTEMA DEBERÁ responder 409 con `WarehouseInactiveException`. *Por qué:* 409 comunica conflicto de estado del recurso, no ausencia.
- **RF-14 (Indeseado):** SI el producto existe pero `is_active = false`, ENTONCES EL SISTEMA DEBERÁ responder 409 con `ProductInactiveException` (mismas condiciones que RF-05). *Por qué:* simetría de modelado con almacenes; vender un producto descontinuado es un conflicto de estado. *(resuelve C4)*

### Regla crítica de concurrencia

- **RF-06 (Evento):** CUANDO la validación de entrada y existencia pase, EL SISTEMA DEBERÁ adquirir un lock distribuido en Redis (`Redis::lock`) con clave **`lock:stock:{sku}:{warehouse_id}`** (formato canónico del Art. I.1; el `sku` se obtiene del catálogo local ya validado en RF-03), con las dos duraciones definidas por separado: **espera máxima de adquisición 5 s** (bloqueante: espera hasta 5 s a que el lock quede libre) y **TTL de propiedad 5 s**. Deberá leerse cualquier stock solo después de poseer el lock. *Por qué:* Art. I.1; serializa los checkouts concurrentes sobre el mismo par sku-almacén. Separar espera de propiedad elimina la ambigüedad de "TTL" (resuelve A2).
- **RF-07 (Evento):** CUANDO el lock se adquiera, EL SISTEMA DEBERÁ abrir una única transacción (`DB::transaction`) que, en su interior: (a) relea el inventario con bloqueo pesimista de fila (`FOR UPDATE`), (b) valide `available = on_hand - reserved ≥ quantity`, (c) si es suficiente y **existe la fila de inventario**, decremente `on_hand` e inserte el movimiento en `inventory_movements` con `quantity_before`/`quantity_after` de `on_hand` exactos. El lock se liberará en un bloque `finally` con el orden estricto **lock → transacción → release**. *Por qué:* Art. I.1 + I.2; el `FOR UPDATE` es segunda línea de defensa (defensa en profundidad) contra un lock Redis degradado.
- **RF-07a (Indeseado):** SI no existe la fila `inventories` para el par `(warehouse_id, product_id)`, ENTONCES EL SISTEMA DEBERÁ tratarlo como stock disponible 0 → 409 `InsufficientStockException`, sin crear la fila. *Por qué:* la creación de filas de inventario es responsabilidad del `inbound` (fuera de alcance); deducir de stock nunca inexistente debe crear estado. *(resuelve A4)*
- **RF-08 (Indeseado):** SI `available < quantity`, ENTONCES EL SISTEMA DEBERÁ responder 409 con `InsufficientStockException`, dejando el stock y el histórico intactos, y liberando el lock. *Por qué:* es el caso de negocio "se agotó"; 409 Conflict es el código semántico correcto.
- **RF-09 (Indeseado):** SI el lock no se adquiere tras agotar la espera bloqueante de 5 s, ENTONCES EL SISTEMA DEBERÁ responder 503 con `StockLockAcquisitionException` y header `Retry-After: 5`. *Por qué:* es un problema transitorio de infraestructura; el caller debe reintentar, no asumir que perdió la venta. *(resuelve C5: valor fijo = espera de adquisición)*
- **RF-15 (Indeseado):** SI Redis no es alcanzable (fallo de conexión, no de contención), ENTONCES EL SISTEMA DEBERÁ responder 503 con `StockInfrastructureUnavailableException` y header `Retry-After: 5`. *Por qué:* sin Redis esta feature no puede garantizar la invariante "cero sobreventas" (Art. I.1); degradar a silencio sería vender stock sin serialización. Es preferible fallar la venta a violar el inventario. *(resuelve C2)*
- **RF-16 (Indeseado):** SI el lock expira durante la transacción (proceso más lento que el TTL de propiedad), ENTONCES EL SISTEMA DEBERÁ: (a) dejar que la transacción complete o revierta por sus propios medios — la integridad queda garantizada por el `FOR UPDATE` (RF-07), nunca por el lock — y (b) al liberar en `finally`, liberar **solo si el proceso sigue siendo el dueño** del lock (verificación por token de propiedad), sin afectar el lock de otro proceso, registrando el evento como degradación con contexto estructurado (RF-12). *Por qué:* el caso "expira durante" es distinto de "expira antes" (RF-09); la liberación de un lock ajeno rompería la serialización. *(resuelve C1)*

### Idempotencia

- **RF-10 (Evento):** CUANDO llegue una petición con una `idempotency_key` cuya ejecución anterior fue **exitosa** (existe movimiento con esa clave), EL SISTEMA DEBERÁ responder 200 con: (a) los datos **persistidos de la primera ejecución** — `movement_id`, `quantity`, `quantity_before`, `quantity_after`, `created_at` — es decir, el estado resultante **en el momento de la primera venta**, y (b) sin volver a descontar stock ni crear un segundo movimiento. La respuesta idempotente NO refleja compras posteriores de otros usuarios. *Por qué:* los reintentos de red en un checkout no pueden duplicar ventas; y reproducir el estado "actual" haría la respuesta no determinista. El estado vigente se consulta con el futuro endpoint de lectura (fuera de alcance). *(resuelve A1)*
- **RF-11 (Indeseado):** CUANDO dos o más peticiones con la misma `idempotency_key` compitan simultáneamente, EL SISTEMA DEBERÁ serializarlas mediante el mismo lock (RF-06): la segunda, al obtener el lock tras el commit de la primera, detectará la clave consumida y aplicará RF-10. Si la primera falló con error de negocio (sin movimiento), la segunda se procesa como intento nuevo (ver decisión de idempotencia en §3). *Por qué:* elimina el caso no determinado "en cola de espera del lock" del test §6.2 y hace la idempotencia correcta por construcción, no por suerte. *(resuelve B2)*
- **RF-17 (Indeseado):** SI una `idempotency_key` ya consumida llega con un par `(warehouse_id, product_id)` distinto al de su ejecución original, ENTONCES EL SISTEMA DEBERÁ responder 409 con `IdempotencyKeyConflictException`. *Por qué:* la clave es global; reutilizarla con otro objetivo indica un bug del caller, no un reintento. *(resuelve C3)*

### Inmutabilidad y trazabilidad

- **RF-12 (Ubicuo):** El sistema deberá impedir `UPDATE` y `DELETE` sobre `inventory_movements` mediante una restricción en la base de datos (no solo a nivel de aplicación). *Por qué:* Art. I.3; la garantía debe sobrevivir a errores de aplicación.
- **RF-13 (Ubicuo):** El sistema deberá registrar **toda respuesta no-2xx** (incluidos 422 de validación y 500 genéricos) con contexto estructurado (`correlation_id`, y `product_id`/`warehouse_id` como `null` cuando aún no sean válidos). *Por qué:* Art. IV.3; la concurrencia es difícil de depurar sin correlación. *(resuelve A6)*
- **RF-18 (Evento):** CUANDO el sistema procese cualquier petición, EL SISTEMA DEBERÁ usar como `correlation_id` el valor del header `X-Correlation-ID` si viene, o generar un UUID propio si no; el `correlation_id` efectivo se devolverá siempre en la cabecera de respuesta `X-Correlation-ID`. *Por qué:* habilita trazabilidad de extremo a extremo cuando existan más servicios (Art. IV.3). *(resuelve A7)*
- **RF-19 (Evento):** CUANDO la deducción sea exitosa, EL SISTEMA DEBERÁ responder 200 con el estado resultante del inventario (`product_id`, `warehouse_id`, `on_hand`, `reserved`, `available`) y el `movement_id` creado. *Por qué:* el caller del checkout necesita el estado final sin segunda consulta.

---

## 5. Fuera de alcance (esta spec)

- Recepción de stock (`inbound`), transferencias entre almacenes y ajustes. **Consecuencia declarada:** en esta versión no existe ningún mecanismo de corrección de errores de deducción (ni siquiera el movimiento compensatorio); el histórico inmutable es definitiva hasta que llegue F2. *(resuelve D4)*
- Implementación del flujo de reservar/liberar (`reserved` existe como columna, pero siempre vale 0 aquí) — feature F2 propuesto.
- Implementación de movimientos compensatorios (solo se exige la prohibición de editar el histórico).
- Multi-tenant, roles y permisos (solo `authenticated`).
- auth-service real, catálogo rico (precios, categorías), comunicación entre servicios, versionado de contratos de eventos.
- Endpoint de lectura de inventario (`GET /inventories`) — se necesita para F2, no para F1.
- Soft-delete de almacenes con stock asociado.

---

## 6. Criterios de finalización

### 6.1 Testing (Pest) — obligatorio (asume §2 Prerrequisitos)

1. **Test de concurrencia (OBLIGATORIO e ineludible):** simulación de peticiones simultáneas sobre stock limitado — stock inicial 10, **20 peticiones concurrentes, cada una con `idempotency_key` única**, `quantity 1` → exactamente 10 respuestas 200 y 10 respuestas 409; `on_hand` final = 0; exactamente 10 movimientos en `inventory_movements`; sin cantidades negativas. **Si aparece un solo 503, el run queda invalidado** (fallo de infraestructura o de la espera bloqueante, no un resultado esperado): el test debe re-ejecutarse hasta un run limpio. *Por qué:* es el único test que demuestra la invariante "cero sobreventas" (Art. I.1); la regla del 503 elimina la contradicción con RF-09 (resuelve B1).
2. **Test de idempotencia concurrente:** dos peticiones simultáneas con la misma `idempotency_key` y el mismo par (warehouse, product) → exactamente una deducción; la segunda recibe la respuesta de RF-10 (200, datos persistidos de la primera). La serialización la garantiza RF-11; el test no acepta "comportamientos alternativos". *(cierra B2)*
3. Tests de validación: 422 con `quantity` 0, negativa, no entera, `idempotency_key` ausente **y `idempotency_key` presente pero no-uuid**. *(resuelve B4)*
4. Tests de existencia y estado: 404 producto inexistente, 404 almacén inexistente, 409 almacén inactivo, **409 producto inactivo** — sin locks adquiridos ni filas mutadas.
5. Tests de idempotencia extendidos: (a) misma key con par (warehouse, product) distinto → 409 `IdempotencyKeyConflictException`; (b) key reutilizada tras un primer intento fallido por stock insuficiente → se procesa como intento nuevo (decisión §3). *(cierra B3, C3)*
6. Test de append-only: intento de `UPDATE`/`DELETE` sobre `inventory_movements` falla.
7. Test de invarianza de stock: verificación de las restricciones `CHECK` de `inventories` (`on_hand ≥ 0`, `reserved ≥ 0`, `reserved ≤ on_hand`). *(cierra C6)*
8. **Gates de la Constitución (Art. II.4):** `./vendor/bin/pest` ✅ · `./vendor/bin/pint --test` ✅ · `./vendor/bin/phpstan` nivel 7 ✅.

### 6.2 Definición de terminado

- Ningún controlador contiene lógica de negocio ni queries (Art. III.2) — flujo: FormRequest → Action → Resource.
- Las excepciones de negocio son clases propias mapeadas en el handler de excepciones (Art. IV.1).
- El entorno no usa Docker (Art. V.3).
- La spec y la Constitución se citan en el PR de cierre.

---

## 7. Registro de cambios (v1.0 → v1.1)

| Hallazgo QA | Resolución en v1.1 |
|---|---|
| A1 (ambigüedad respuesta idempotente) | RF-10 redefinido: respuesta con datos persistidos de la primera ejecución; explícito que no refleja compras posteriores; status 200. |
| A2 (TTL vs espera de adquisición) | RF-06 separa espera bloqueante (5 s) de TTL de propiedad (5 s). |
| A3 (before/after de qué) | §3: `quantity_before/after` se refieren siempre a `on_hand`. |
| A4 (fila de inventario inexistente) | RF-07a: tratada como stock 0 → 409, sin crear fila. |
| A5 (clave lock product_id) | RF-06 adopta el canon de la Constitución: `lock:stock:{sku}:{warehouse_id}` (también cierra D2). |
| A6 (alcance del logging) | RF-13: toda respuesta no-2xx, campos `null` cuando no apliquen. |
| A7 (correlation_id sin productor) | RF-18: header `X-Correlation-ID` entrante/saliente, fallback UUID del sistema. |
| B1 (503 vs test exacto 10/10) | §6.1.1: un solo 503 invalida el run; el test exige ejecución limpia. |
| B2 (misma key concurrente) | RF-11: serialización por el mismo lock + resultado determinista. |
| B3 (keys de intentos fallidos) | §3 decisión explícita: solo el éxito consume la clave; el fallo la deja reutilizable. |
| B4 (key no-uuid sin test) | §6.1.3 añade el caso. |
| C1 (lock expira durante transacción) | RF-16: integridad por `FOR UPDATE`, liberación segura por token de propiedad, degradación registrada. |
| C2 (Redis caído) | RF-15: `StockInfrastructureUnavailableException` → 503 + `Retry-After: 5`. |
| C3 (key global con par distinto) | RF-17: 409 `IdempotencyKeyConflictException`. |
| C4 (producto sin is_active) | `products.is_active` + RF-14 (`ProductInactiveException` → 409). |
| C5 (valor de Retry-After) | RF-09/RF-15: valor fijo 5 (segundos). |
| C6 (reserved corrupta) | Restricciones `CHECK` en `inventories` + test §6.1.7. |
| D1 (entorno inexistente) | §2: prerrequisitos obligatorios (PostgreSQL operativo, `pgsql` en `.env`, Redis, Sanctum, seeders). |
| D2 (clave lock desviada del canon) | Resuelto junto con A5. |
| D3 (Sanctum sin declarar) | Declarado como prerrequisito con autorización de gobernanza pendiente en §2. |
| D4 (sin mecanismo de corrección) | §5: consecuencia declarada explícitamente. |
