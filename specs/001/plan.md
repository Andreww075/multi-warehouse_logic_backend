# 🗺️ Plan de Implementación — F1: Deducción de Stock (Checkout Simulado)

**Basado en:** `specs/001/spec.md` v1.1 · **Rige el CÓMO:** `docs/constitution.md`
**Naturaleza:** este plan define módulos, decisiones técnicas justificadas (con la alternativa descartada) y estrategia de tests. Sin código.

---

## 1. Módulos y orden de construcción

Las dependencias entre módulos definen el orden. Cada módulo termina cuando pasa su gate (§5).

### M0 — Prerrequisitos de entorno (cubre Spec §2)
Setup nativo sin Docker (Art. V.3): PostgreSQL operativo + `DB_CONNECTION=pgsql` en `.env`, Redis operativo, instalación de Sanctum vía Composer (requiere la autorización de gobernanza pendiente), `REDIS_CLIENT=predis` ya configurado.
**RF cubiertos:** habilita RF-01, RF-06, RF-07. Sin M0, ningún test de §6.1 es ejecutable.

### M1 — Migraciones, modelos y persistencia (cubre Spec §3)
- Migraciones de `warehouses`, `products` (con `is_active`), `inventories` (con las tres restricciones `CHECK`), `inventory_movements` (sin `updated_at`, con `UNIQUE(idempotency_key)`).
- Restricción **append-only a nivel BD** para `inventory_movements` (RF-12): trigger/restricción PostgreSQL.
- Modelos Eloquent tipados estrictos (Art. II), cast uuid, sin mutadores que permitan mutar movimientos.
- Índices: unique `(warehouse_id, product_id)` en `inventories`; unique `idempotency_key` en movimientos; índice de consulta `(warehouse_id, product_id)` sobre movimientos para auditoría futura.
**RF cubiertos:** RF-12 (constraint BD), RF-07a (la fila `inventories` es opcional para el dominio), soporte de datos para RF-07, RF-10.

### M2 — Dominio de excepciones y mapeo HTTP (cubre Spec §4, errores)
- Clases de excepción de dominio: `ProductNotFoundException` (404), `WarehouseNotFoundException` (404), `WarehouseInactiveException` (409), `ProductInactiveException` (409), `InsufficientStockException` (409), `StockLockAcquisitionException` (503 + `Retry-After: 5`), `StockInfrastructureUnavailableException` (503 + `Retry-After: 5`), `IdempotencyKeyConflictException` (409).
- Un solo punto de mapeo en el exception handler de Laravel; **prohibido** `abort()`/`HttpException` en dominio (Art. IV.1).
- Logging estructurado de toda respuesta no-2xx con `correlation_id` y `product_id`/`warehouse_id` anulables (RF-13).
**RF cubiertos:** RF-03, RF-04, RF-05, RF-08, RF-09, RF-12(lóg. parcial), RF-13, RF-14, RF-15, RF-17.

### M3 — Infraestructura de locks distribuidos (cubre Spec RF-06, RF-09, RF-15, RF-16)
- Componente `StockLockManager` sobre `Redis::lock` de Illuminate (cliente `predis`), encapsulando: clave canónica `lock:stock:{sku}:{warehouse_id}`, espera bloqueante máx. 5 s, TTL de propiedad 5 s, liberación en `finally` **con verificación de propiedad** (token) y conversión de fallos de conexión Redis → `StockInfrastructureUnavailableException` (RF-15).
- Responsabilidad única: el Action no conoce Redis, solo el contrato del manager. Facilita sustituir backend de lock en F2+ sin tocar dominio.
**RF cubiertos:** RF-06, RF-09, RF-15, RF-16.

### M4 — Capa HTTP delgada (cubre Spec §4, entrada y salida)
- `DeductStockFormRequest`: reglas de RF-02 (`uuid`, `integer`, `min:1`, `max:1_000_000`, `idempotency_key` uuid obligatoria), `authorize()` basado en la política `authenticated` única (RF-01).
- Ruta `POST /api/v1/inventories/deduct` bajo middleware Sanctum + middleware de correlación (RF-18): lee `X-Correlation-ID`, genera UUID si falta, lo propaga a la respuesta y lo deposita en el contexto de log.
- `InventoryDeductionResource` (JsonResource) con el contrato de RF-19.
- Controlador de una sola instrucción de delegación: FormRequest → Action → Resource (Art. III.2). Cero queries.
**RF cubiertos:** RF-01, RF-02, RF-18, RF-19 (forma), RF-13 (contexto disponible).

### M5 — Action de dominio `DeductStockAction` (cubre Spec §4, regla crítica + idempotencia)
- Orquestación estricta del orden: (1) validaciones de referencia y estado (RF-03/04/05/14 — **antes** del lock), (2) adquisición del lock vía M3 (RF-06), (3) `DB::transaction` con relea `FOR UPDATE` (RF-07), (4) verificación de idempotencia ya dentro del lock (RF-10/RF-11), (5) deducción + inserción de movimiento con `quantity_before/after` de `on_hand`, (6) release en `finally` (RF-16).
- Sin `mixed` (Art. II.2); entrada/salida como DTO tipado, no arrays crudos.
- Fallo de violación `UNIQUE(idempotency_key)` en carrera → `IdempotencyKeyConflictException` si el par difiere, respuesta idempotente si coincide (RF-17).
**RF cubiertos:** RF-03–RF-11, RF-14, RF-16, RF-17, RF-19 (datos).

### M6 — Fixtures: factories y seeders
Factories de warehouses/products/inventories/movements + seeders de prueba usados por toda la suite. La creación de filas de `inventories` queda confinada a fixtures (Spec §2.4).
**RF cubiertos:** habilita §6.1 completo.

### M7 — Suite de tests Pest (cubre Spec §6.1) — ver §4 de este plan.

### M8 — Gates y pulido final
`./vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan` (nivel 7) en verde; revisión contra la Definición de terminado (Spec §6.2). Cubre Art. II.4.

---

## 2. Modelo de datos (resumen operativo)

| Tabla | Claves / restricciones | Notas de implementación |
|---|---|---|
| `warehouses` | PK uuid; `name` unique; `is_active` bool | Índice único en `name`. |
| `products` | PK uuid; `sku` unique; `is_active` bool | Fuente local de `sku` para la clave de lock (RF-06). |
| `inventories` | PK uuid; FK `warehouse_id`, FK `product_id`; UNIQUE `(warehouse_id, product_id)`; `CHECK on_hand ≥ 0`; `CHECK reserved ≥ 0`; `CHECK reserved ≤ on_hand` | `on_hand`/`reserved` unsigned integers con default 0. Las CHECK se escriben en la migración con SQL crudo (statement), no en el modelo. |
| `inventory_movements` | PK uuid; UNIQUE `idempotency_key`; índice `(warehouse_id, product_id)`; `CHECK quantity > 0` | **Sin `updated_at`**; el modelo no exponde `update()`/`delete()` operativos. Append-only reforzado con trigger PostgreSQL (M1). `type` restringido a `outbound` en esta versión (CHECK o enum PG). |

---

## 3. Decisiones técnicas justificadas (con alternativa descartada)

| # | Decisión | Por qué | Alternativa descartada | Por qué se descarta |
|---|---|---|---|---|
| P-01 | Clave de lock `lock:stock:{sku}:{warehouse_id}` | Canon del Art. I.1 de la Constitución; permite reutilizar el gestor de locks entre servicios y features. | `lock:stock:{warehouse_id}:{product_id}` (v1.0 de la spec) | Se desviaba del formato canónico sin beneficio; inconsistencia inter-servicios futura. |
| P-02 | Espera bloqueante de adquisición 5 s **separada** del TTL de propiedad 5 s | Distingue "contención" (→ esperar) de "fallo de propiedad" (→ 503). Hace determinista el test de concurrencia. | Adquisición fail-fast (un intento) | Bajo contención de checkout real produciría tormentas de 503 que el test §6.1.1 declara inválidas; obligaría a reintentar en cliente. |
| P-03 | `Redis::lock` de Illuminate sobre `predis` | Mecanismo SET NX EX con token de propiedad ya resuelto y probado por el framework; RF-16 (liberación por propiedad) sale gratis. | Redlock multi-nodo | Topología de un solo nodo Redis en este entorno; complejidad y consenso innecesarios para F1. |
| P-04 | Defensa en profundidad: lock Redis **+** `FOR UPDATE` dentro de la transacción | Si Redis se degrada o el lock expira a mitad de proceso (RF-16), la fila bloqueada mantiene la invariante. | Solo Redis (más rápido) | Un lock expirado permitiría sobreventa. Solo `FOR UPDATE` (sin Redis) | Serializa en BD por transacción, pero no protege lecturas fuera de transacción ni features futuros multi-instancia; además la Constitución exige Redis (Art. I.1). |
| P-05 | Idempotencia por `UNIQUE(idempotency_key)` en `inventory_movements` | Dedupe atómico por construcción, dentro de la misma transacción que la venta; cero estado adicional. | Tabla aparte de claves procesadas | Estado extra que sincronizar y segunda fuente de verdad a mantener consistente. Cache Redis de claves | TTL y volatilidad rompen la garantía permanente; Redis puede caer justo cuando más se necesita la idempotencia. |
| P-06 | Respuesta idempotente = datos persistidos de la primera ejecución (RF-10) | Determinista, auditable y reconstruible desde el único registro que existe. | Estado *actual* del inventario en el replay | No determinista: depende de compras intermedias; el caller no puede distinguir su venta. Tabla de snapshots de respuestas | Duplica persistencia; el movimiento ya contiene lo necesario. |
| P-07 | Fila `inventories` inexistente → 409, sin crearla (RF-07a) | La creación de inventario es responsabilidad del `inbound` (F2); deducir nunca debe crear estado. | Upsert con creación a 0 | Ocultaría errores de datos y crearía filas fantasma fuera del flujo dueño. |
| P-08 | Restricciones `CHECK` de stock en PostgreSQL | La invariante sobrevive a cualquier bug de aplicación (mismo criterio que RF-12). | Solo validación en Action/FormRequest | Un race o un bug la violaría silenciosamente. |
| P-09 | Append-only con trigger PostgreSQL | Cumple RF-12 al nivel exigido ("en la base de datos"). | Solo guards de Eloquent / policies | Evitables con SQL crudo o con otro modelo; no sobrevive a errores de aplicación. |
| P-10 | Excepciones de dominio + un único handler de mapeo | Cumple Art. IV.1; los códigos HTTP son una decisión de la frontera HTTP, no del dominio. | `abort()` / `HttpException` desde el Action | Prohibido por la Constitución; acopla dominio a HTTP. |
| P-11 | Sanctum con política `authenticated` única | Autenticación real mínima sin construir auth-service (Spec §2.3). | Endpoint abierto | Un endpoint que muta stock no puede quedar sin actor atribuible. Tokens caseros | Reinventar seguridad; Sanctum es estándar del framework. |
| P-12 | Cliente `predis` vía Composer | Ya instalado; nativo en Windows sin gestionar DLL. | Extensión `phpredis` | Requiere instalar/compilar extensión manualmente en el host (Art. V.3, sin Docker). |
| P-13 | UUID v4 como PK en las 4 tablas | Especificado en la Spec §3; evita filtrar volumen de negocio y facilita merges entre servicios. | Bigserial / bigint | IDs secuenciales exponen métricas internas y complican la futura extracción de servicios. |
| P-14 | `X-Correlation-ID` entrante/saliente con fallback UUID (RF-18) | Trazabilidad cross-servicio desde el día uno (Art. IV.3) a coste de un middleware. | Correlación solo interna en logs | Imposible seguir una venta entre servicios cuando existan. |
| P-15 | Columna `reserved` presente pero sin uso en F1 | Evita una migración destructiva en F2 y documenta la intención del modelo (Spec §3). | Aplazar la columna a F2 | Segunda migración + riesgo de drift entre entornos; coste hoy es cero. |

---

## 4. Estrategia de tests (Pest)

### 4.1 Pirámide y alcance
1. **Unit (dominio):** `DeductStockAction` y `StockLockManager` aislados; excepciones de dominio y su mapeo; verificación de `quantity_before/after`. Los locks se ejercitan contra Redis real (no fakes: un fake de lock invalidaría la invariante que se quiere demostrar).
2. **Feature (HTTP):** FormRequest + controlador + resource + headers de correlación, con base de datos PostgreSQL real y `RefreshDatabase`.
3. **Concurrencia (obligatorio, Spec §6.1.1):** descrita en 4.2.
4. **Integridad:** append-only (trigger) e invariantes `CHECK`.

### 4.2 Simulación de concurrencia en host nativo Windows (sin Docker, sin `pcntl`)
**Estrategia elegida:** multiproceso por lanzamiento de procesos CLI reales — un pool de N procesos PHP independientes ejecuta, cada uno, la misma deducción contra la misma BD y Redis, y el test padre agrega los resultados y verifica el estado final de BD.
**Por qué:** es la única forma en este entorno (Windows nativo, sin `pcntl`, sin extensión `parallel`) de producir contención *real* sobre el lock y la fila, que es lo que el test debe demostrar; una simulación secuencial no probaría nada sobre concurrencia.
**Alternativas descartadas:** `pcntl_fork` (no existe en Windows), extensión `parallel`/`Swoole` (no instaladas; añadir paquetes requiere gobernanza), simulación secuencial disfrazada de concurrente (no demuestra la invariante, falso positivo), colas de Laravel (la latencia de worker diluiría la contención y mediría otra cosa).
**Parametrización:** stock 10 vs 20 procesos × `quantity 1`, keys únicas por proceso; aserción: exactamente 10×éxito y 10×`InsufficientStockException`, `on_hand` final 0, 10 movimientos; cualquier 503 invalida el run (se re-ejecuta). El test declara un presupuesto de tiempo máximo para no colgar la suite.
**Variante HTTP (si el presupuesto lo permite):** repetir el experimento sobre `php artisan serve` con pool de clientes HTTP, para cubrir también middleware/route en contención. Opcional, no gating.

### 4.3 Matriz RF → tests (Spec §6.1)
| RF | Test(es) | Nivel |
|---|---|---|
| RF-01 | Feature: sin token → 401 | Feature |
| RF-02 | Feature: 422 quantity 0/negativa/no-entera; key ausente y key no-uuid | Feature |
| RF-03/RF-04 | Feature: 404 producto/almacén inexistente, sin locks ni mutaciones | Feature |
| RF-05 | Feature: 409 almacén inactivo | Feature |
| RF-14 | Feature: 409 producto inactivo | Feature |
| RF-06/RF-09 | Unit: agotamiento de espera → `StockLockAcquisitionException`; Unit: clave y TTL correctos | Unit |
| RF-15 | Unit/Feature: Redis caído → 503 + `Retry-After: 5` | Feature |
| RF-07/RF-07a/RF-08 | Feature: deducción feliz con before/after exactos; fila inexistente → 409 sin crear; stock insuficiente → 409 sin mutar | Feature |
| RF-10/RF-11 | Feature: replay exitoso con datos persistidos; §4.2 variante misma-key concurrente | Feature |
| RF-17 | Feature: key consumida con par distinto → 409 | Feature |
| RF-12 | Integridad: UPDATE/DELETE sobre movimientos fallan a nivel BD | Integridad |
| RF-13/RF-18 | Feature: log estructurado de errores; header `X-Correlation-ID` eco/fallback | Feature |
| RF-19 | Feature: payload de éxito completo (estado + movement_id) | Feature |
| §6.1.1 | Test de concurrencia obligatorio (§4.2) | Concurrencia |
| §6.1.7 | Integridad: CHECK constraints rechazan estados inválidos | Integridad |

### 4.4 Gates por módulo (Art. II.4)
Cada módulo (M1–M7) cierra con: `./vendor/bin/pest` (suite de su ámbito) ✅ · `./vendor/bin/pint --test` ✅ · `./vendor/bin/phpstan` nivel 7 ✅. Sin gates en verde no se avanza al módulo siguiente.

---

## 5. Riesgos y dependencias críticas

1. **M0 es bloqueante:** sin PostgreSQL y Redis reales operativos no se puede empezar ni un test. Primera acción del plan.
2. **Sanctum requiere autorización de gobernanza** (Art. V.1 + regla de Composer de `AGENTS.md`) antes de M4.
3. **El test de concurrencia multiproceso** puede ser sensible a tiempos del host: se acota con presupuesto máximo y regla de re-ejecución ante 503 (Spec §6.1.1).
4. **SQLite está vetado** para esta feature (Spec §2): cualquier resto de `DB_CONNECTION=sqlite` en `.env.testing` debe eliminarse en M0.

---

## 6. Definición de terminado (resumen operativo)

Spec §6.2 + matriz de trazabilidad RF↔módulo↔test completa y en verde + gates de la Constitución en verde + mención de Constitución y Spec en el PR de cierre.
