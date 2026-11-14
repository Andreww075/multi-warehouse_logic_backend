# ✅ Task List — F1: Deducción de Stock

**Derivada de:** `specs/001/spec.md` v1.1 + `specs/001/plan.md` · **Granularidad:** tareas < 30 min · **Orden:** dependencia estricta (no empezar una sin las anteriores en ✅).
**Gates transversales:** toda tarea de código cierra con `./vendor/bin/pest` (ámbito) + `./vendor/bin/pint --test` + `./vendor/bin/phpstan` (nivel 7) en verde.

---

## M0 — Entorno (bloqueante)

- [x] **T-01 — PostgreSQL operativo y `.env` en pgsql** *(habilita: todas)*
  Levantar PostgreSQL nativo, cambiar `DB_CONNECTION=pgsql`, configurar credenciales.
  **Hecho cuando:** `php artisan migrate:fresh` corre sin errores contra PostgreSQL (`SELECT version()` ≥ 16).
  ✅ **Completada (2026-11-14):** PostgreSQL 18.7 operativo; BDs `inventory_db` + `inventory_db_test` creadas; `.env` y `phpunit.xml` apuntan a pgsql; `migrate:fresh` ejecutó las 3 migraciones base; Pest y Pint en verde.

- [x] **T-02 — Redis operativo** *(habilita: RF-06, RF-09, RF-15, RF-16)*
  Levantar Redis nativo ≥ 7 en `127.0.0.1:6379` (cliente `predis` ya instalado).
  **Hecho cuando:** un script de humo (tinker/test) ejecuta `SET`/`GET`/`DEL` sobre Redis con éxito.
  ✅ **Completada (2026-11-14):** Memurai (Redis 8.2.10) operativo en `127.0.0.1:6379`; humo `PING` + `SET`/`GET`/`DEL` verificado con `predis` (el cliente de la aplicación).

- [x] **T-03 — Sanctum instalado y migrado** *(RF-01)* — ⚠️ requiere autorización de gobernanza para Composer.
  `composer require laravel/sanctum` + `install:api`.
  **Hecho cuando:** `composer show laravel/sanctum` devuelve versión y la tabla `personal_access_tokens` existe tras migrar.
  ✅ **Completada (2026-11-14):** autorización otorgada por el propietario. Sanctum v4.4.0 instalado; `install:api` publicó y ejecutó la migración; tabla `personal_access_tokens` verificada en `inventory_db`; trait `HasApiTokens` + `strict_types` añadidos a `User`; fixes de gates (line endings en `bootstrap/app.php`, defensa de tipo en `config/sanctum.php`). Gates: Pest 2/2 · Pint ✅ · PHPStan 7 ✅.

- [x] **T-04 — php.ini limpio** *(habilita: gates sin ruido)*
  Borrar línea 942 `extension=php_pdo_mysql.dll` (duplicada) y subir `memory_limit` ≥ 512M (para PHPStan).
  **Hecho cuando:** `php --version`, `pest` y `phpstan --memory-limit=1G` corren **sin el warning de pdo_mysql**.
  ✅ **Completada (2026-11-14):** editado manualmente por el propietario con permisos de admin. `memory_limit=512M` verificado con `php -i`; warning de pdo_mysql eliminado ( Pest, Pint y php --version corren limpios); PHPStan nivel 7 pasa **sin** flag de memoria.

## M1 — Persistencia

- [x] **T-05 — Migración `warehouses` + `products`** *(RF-03, RF-04, RF-14 · Spec §3)*
  PK uuid, `name`/`sku` unique, `is_active`.
  **Hecho cuando:** `\d warehouses` y `\d products` muestran PK uuid, unique e `is_active`; `migrate:fresh` verde.
  ✅ **Completada (2026-11-14):** migraciones `2026_11_14_000001/000002` ejecutadas en `inventory_db`; PK uuid verificada por catálogo; uniques `warehouses_name_unique` y `products_sku_unique` presentes; `is_active` con default true.

- [x] **T-06 — Migración `inventories`** *(RF-07, RF-07a · Spec §3)*
  FKs locales, `UNIQUE(warehouse_id, product_id)`, defaults 0, y las tres `CHECK` (`on_hand ≥ 0`, `reserved ≥ 0`, `reserved ≤ on_hand`) con SQL crudo.
  **Hecho cuando:** `\d inventories` muestra UNIQUE y las 3 CHECK; insertar un estado inválido manualmente falla.
  ✅ **Completada (2026-11-14):** migración `2026_11_14_000003` con FKs a `warehouses`/`products`, UNIQUE compuesto y 3 CHECK verificadas por `pg_constraint`; prueba activa: inserción con `on_hand = -5` rechazada (SQLSTATE 23514), par duplicado rechazado, defaults 0/0 correctos. Fixtures de prueba eliminados tras la verificación.

- [x] **T-07 — Migración `inventory_movements`** *(RF-10, RF-12 · Spec §3)*
  Sin `updated_at`, `UNIQUE(idempotency_key)`, `CHECK quantity > 0`, `CHECK type = 'outbound'`, índice `(warehouse_id, product_id)`.
  **Hecho cuando:** `\d inventory_movements` muestra las restricciones; la tabla no tiene columna `updated_at`.
  ✅ **Completada (2026-11-14):** migración `2026_11_14_000004` ejecutada; catálogo verifica: `updated_at` ausente, `UNIQUE(idempotency_key)`, 2 CHECK (`quantity > 0`, `type = 'outbound'`), índice compuesto `inventory_movements_warehouse_product_index`, `created_at` con `useCurrent`.

- [x] **T-08 — Trigger append-only** *(RF-12 · Plan P-09)*
  Trigger PostgreSQL que rechaza `UPDATE`/`DELETE` sobre `inventory_movements`.
  **Hecho cuando:** un `UPDATE` y un `DELETE` manuales sobre la tabla fallan con error de BD (verificable en psql).
  ✅ **Completada (2026-11-14):** migración `2026_11_14_000005` con función + 2 triggers (fila: UPDATE/DELETE; statement: TRUNCATE). Prueba activa: `UPDATE`, `DELETE` y `TRUNCATE` rechazados (SQLSTATE P0001); el intento posterior de limpiar los fixtures falló por FK — la inmutabilidad impidió borrar el movimiento de prueba. Esquema regenerado con `migrate:fresh` (trigger recreado y verificado en `pg_trigger`). Gates: Pest · Pint · PHPStan ✅.

- [x] **T-09 — Modelos Eloquent tipados** *(RF-07, RF-10 · Art. II)*
  `Warehouse`, `Product`, `Inventory`, `InventoryMovement` con `declare(strict_types=1)`, casts uuid, sin mutadores que permitan mutar movimientos.
  **Hecho cuando:** `phpstan` nivel 7 en verde y los 4 modelos resuelven relaciones con tipado completo.
  ✅ **Completada (2026-11-14):** 4 modelos con `strict_types`, trait `HasUuids`, atributos `#[Fillable]` (estilo del skeleton), casts estrictos (`is_active` boolean, stocks integer), relaciones genéricas (Larastan). `InventoryMovement`: `timestamps=false`, eventos `updating`/`deleting` que lanzan `RuntimeException` (refuerzo app de RF-12). Humo runtime PASS: uuids, casts int/bool, guard de inmutabilidad (UPDATE/DELETE rechazados). Hallazgo documentado: Eloquent no relee defaults de BD tras `create` (verificado con `refresh()`). Esquema regenerado limpio con `migrate:fresh`. Gates: Pest · Pint · PHPStan 7 ✅.

## M2 — Excepciones

- [x] **T-10 — 8 excepciones de dominio** *(RF-03, RF-04, RF-05, RF-08, RF-09, RF-14, RF-15, RF-17)*
  `ProductNotFoundException`, `WarehouseNotFoundException`, `WarehouseInactiveException`, `ProductInactiveException`, `InsufficientStockException`, `StockLockAcquisitionException`, `StockInfrastructureUnavailableException`, `IdempotencyKeyConflictException`.
  **Hecho cuando:** las 8 clases existen, tipadas, sin referencia a HTTP (sin status codes en el dominio), `phpstan` verde.
  ✅ **Completada (2026-11-14):** creadas en `app/Domain/Exceptions/`, todas `final` extendiendo `AbstractDomainException` (base con `withContext()`/`context()` para RF-13); cero referencias a HTTP en el dominio; PHPStan 7 ✅.

- [x] **T-11 — Mapeo en exception handler + logging estructurado** *(RF-13 · Art. IV.1)*
  Un único punto de mapeo dominio→HTTP (`404`/`409`/`503` + `Retry-After: 5`); log estructurado de toda respuesta no-2xx con `correlation_id` y `product_id`/`warehouse_id` anulables.
  **Hecho cuando:** un test de humo lanza cada excepción y obtiene el código HTTP exacto con su payload de log.
  ✅ **Completada (2026-11-14):** `DomainExceptionMapper` (match explícito de las 8; `default → 500` ruidoso para excepciones sin mapear), `CorrelationId` (header `X-Correlation-ID` validado con fallback UUID), render en `bootstrap/app.php` tipado con `AbstractDomainException`. Smoke test `tests/Feature/ExceptionMappingTest.php`: 3 tests / 8 mapeos / headers Retry-After / payload de log con contexto — todo verde. Correcciones en el camino: creado `tests/Pest.php` faltante (sin él los tests no booteaban Laravel), import faltante en el mapper, imports no usados. Gates: Pest 5/5 (32 aserciones) · Pint · PHPStan 7 ✅.

## M3 — Locks

- [x] **T-12 — `StockLockManager`** *(RF-06, RF-09, RF-15, RF-16 · Plan P-02, P-03)*
  Clave `lock:stock:{sku}:{warehouse_id}`, espera bloqueante 5 s, TTL de propiedad 5 s, liberación con verificación de propiedad, conversión de fallo de conexión Redis → `StockInfrastructureUnavailableException`.
  **Hecho cuando:** unit tests verifican clave/TTL/agotamiento de espera (→ `StockLockAcquisitionException`) y liberación segura de lock expirado.
  ✅ **Completada (2026-11-14):** `app/Infrastructure/Stock/` con `StockLockManager` + `StockLockHandle`. Ajuste justificado de P-03: se usa el mecanismo de lock de Illuminate (`RedisLock`, token de propiedad + liberación comparada) directo sobre la conexión Redis — no vía `Cache::store('redis')`, porque el Repository reenvía `lock()` sin tipado (incompatible con PHPStan 7) y añade prefijo de cache a la clave canónica. 6 unit tests contra Redis real (sin fakes): clave canónica, ciclo adquirir/liberar, timeout → RF-09, liberación tras expiración no toca el lock del nuevo dueño (RF-16 probado), re-adquisición, fallo de conexión → RF-15. Gates: Pest 11/11 (43 aserciones) · Pint · PHPStan 7 ✅.

## M4 — Capa HTTP

- [x] **T-13 — Middleware de correlación** *(RF-18, RF-13)*
  Lee `X-Correlation-ID`, genera UUID si falta, lo ecoa en la respuesta y lo inyecta en el contexto de log.
  **Hecho cuando:** una respuesta de prueba devuelve `X-Correlation-ID` igual al enviado, y otra (sin header) devuelve uno nuevo.
  ✅ **Completada (2026-11-14):** `app/Http/Middleware/CorrelationIdMiddleware.php` (reutiliza `App\Support\CorrelationId` de T-11; `Log::withContext(['correlation_id' => ...])` + eco en header), registrado como alias `correlation` en `bootstrap/app.php`. 4 tests: eco literal de header válido, UUID nuevo si falta, UUID nuevo si header inválido, inyección en contexto de log (RF-13). Eco verificado también en la ruta real (ver T-15).

- [x] **T-14 — `DeductStockFormRequest` + ruta Sanctum** *(RF-01, RF-02)*
  Reglas: `product_id` uuid, `warehouse_id` uuid, `quantity` integer 1–1.000.000, `idempotency_key` uuid obligatoria; política `authenticated`.
  **Hecho cuando:** peticiones sin token → 401; payloads inválidos → 422 con los errores esperados.
  ✅ **Completada (2026-11-14):** `app/Http/Requests/DeductStockRequest.php` (`authorize()` = true porque la política es solo autenticación vía middleware; `toCommand()` mapea `validated()` a `DeductStockCommand` — nunca `all()` crudo, Art. II.2) + DTOs de dominio `app/Domain/Stock/DeductStockCommand.php` y `DeductStockResult.php` (contratos M4/M5). Ruta `POST /api/v1/inventories/deduct` con `auth:sanctum` + `correlation` en `routes/api.php`. Tests: sin token → 401; payload con 4 errores → 422 con los 4 campos; `quantity` negativa → 422.

- [x] **T-15 — `InventoryDeductionResource` + controlador delgado** *(RF-19 · Art. III.2)*
  Contrato: `product_id`, `warehouse_id`, `on_hand`, `reserved`, `available`, `movement_id`. Controlador con una sola delegación.
  **Hecho cuando:** la ruta responde con el contrato exacto en una prueba con Action stub, y `phpstan` no detecta lógica en el controlador.
  ✅ **Completada (2026-11-14):** `app/Http/Resources/InventoryDeductionResource.php` (contrato plano con `static $wrap = null`, simétrico al contrato de error del `DomainExceptionMapper`) + `app/Http/Controllers/InventoryController::deduct()` (una sola delegación: FormRequest → Action → Resource). `DeductStockAction` creado como placeholder de M4 (lanza `LogicException`; implementación en M5) — no `final` para permitir el stub en pruebas. Tests con Action stub: 200 con `assertExactJson` del contrato completo + verificación de que el comando tipado recibe el payload validado campo a campo + eco del header de correlación en la ruta real. Gates: Pest 20/20 (68 aserciones) · Pint · PHPStan 7 ✅.

## M5 — Dominio

- [x] **T-16 — `DeductStockAction`: DTO y validaciones pre-lock** *(RF-03, RF-04, RF-05, RF-14)*
  DTO tipado de entrada/salida; existencia y vigencia de producto/almacén resueltas **antes** de adquirir el lock.
  **Hecho cuando:** unit tests de existencia/estado devuelven las 4 excepciones correctas sin tocar Redis ni transacción.
  ✅ **Completada (2026-11-14):** validaciones pre-lock implementadas en `app/Actions/DeductStockAction` (orden producto→almacén→inactivos; 404 antes de 409). 6 unit tests con factory de Redis que **falla si se toca**: cubren las 4 excepciones + orden 404-before-409 y ausencia de mutación. Redis ni transacción quedan tocados en las validaciones pre-lock (P-03/T-12).

- [x] **T-17 — `DeductStockAction`: lock → transacción → release** *(RF-06, RF-07, RF-07a, RF-08, RF-10, RF-16 · Art. I.1/I.2)*
  `DB::transaction` con relea `FOR UPDATE`, validación `available = on_hand - reserved`, decremento de `on_hand`, inserción del movimiento con `before/after` de `on_hand`, fila inexistente → 409 sin crear, release en `finally` con propiedad.
  **Hecho cuando:** feature tests de deducción feliz (before/after exactos), stock insuficiente (409 sin mutar) y fila inexistente (409 sin crear) pasan.
  ✅ **Completada (2026-11-14):** transacción única con `lockForUpdate()` (P-04), `available = on_hand - reserved ≥ quantity` (RF-08), `decrement` atómico + movimiento append-only con before/after exactos (RF-12), fila inexistente → 409 sin crear (RF-07a/P-07).

- [x] **T-18 — Idempotencia dentro del lock** *(RF-10, RF-11, RF-17 · Plan P-05, P-06)*
  Chequeo de clave consumida tras obtener el lock; replay con datos persistidos de la primera ejecución; violación UNIQUE en carrera → `IdempotencyKeyConflictException` si el par difiere.
  **Hecho cuando:** tests de replay (200 con datos de la primera ejecución), misma key par distinto (409) y key tras fallo reutilizable pasan.
  ✅ **Completada (2026-11-14):** chequeo de idempotencia dentro del lock+transacción (RF-10/11); replay devuelve `IdempotentReplayResult` con los datos persistidos (P-06) vía `InventoryMovementReplayResource`; UNIQUE en carrera → re-lectura → replay/conflicto (RF-17); key tras fallo de negocio reutilizable sin movimiento (RF-11).

## M6 — Fixtures

- [x] **T-19 — Factories y seeders** *(habilita §6.1 completo · Spec §2.4)*
  Factories de las 4 tablas; helper de fixture que crea warehouse activo, producto activo e inventario con stock N.
  **Hecho cuando:** `RefreshDatabase` + fixture produce estados reproducibles y deterministas en cada test.
  ✅ **Completada (2026-11-14):** factories en `database/factories/` (`WarehouseFactory`, `ProductFactory`, `InventoryFactory` con estado `stock(onHand, reserved)`, `InventoryMovementFactory`); `HasFactory` + genéricos (`@use HasFactory<XFactory>`) restaurado en los 4 modelos. Helper `App\Support\StockFixture::create(onHand, reserved)` crea warehouse activo + producto activo + inventario con stock N (confinado a fixtures, Spec §2.4). Test: fixture determinista (stock 10/reserved 2 → available 8). Gates: Pest 34/34 (130 aserciones) · Pint · PHPStan 7 ✅.

## M7 — Tests (matriz §4.3 del plan)

- [x] **T-20 — Feature: entrada, existencia y estado** *(RF-01, RF-02, RF-03, RF-04, RF-05, RF-14)*
  401 sin token; 422 (quantity 0/negativa/no-entera, key ausente/no-uuid); 404×2; 409×2 — sin locks ni mutaciones en los fallos de referencia.
  **Hecho cuando:** los ~10 tests de la fila correspondiente de la matriz pasan.
  ✅ **Completada (2026-11-14):** tests existentes consolidados: 401 (sin token), 422 (payload con 4 errores, `quantity` 0, negativa y **no-entera (1.5)** añadido), 404 (producto/almacén) y 409 (inactivos) vía suite pre-lock. Fallos de referencia sin locks ni mutación (factory Redis que falla si se toca). Gates: Pest 41/41 ✅.

- [x] **T-21 — Feature: deducción, idempotencia y trazabilidad** *(RF-07, RF-08, RF-10, RF-17, RF-18, RF-19)*
  Feliz con payload completo; replay; key conflict; headers `X-Correlation-ID` eco/fallback.
  **Hecho cuando:** los tests de la matriz para esos RF pasan.
  ✅ **Completada (2026-11-14):** feliz con before/after exactos + replay (RF-10) + conflict (RF-17) + eco de header en ruta real + fallback de correlación sin header (RF-18). Gates: Pest ✅.

- [x] **T-22 — Integridad: append-only + CHECK** *(RF-12, Spec §6.1.7)*
  UPDATE/DELETE sobre movimientos fallan; CHECK de `inventories` rechazan estados inválidos.
  **Hecho cuando:** ambos tests de integridad pasan a nivel BD.
  ✅ **Completada (2026-11-14):** tests BD-level con `DB::table` (bypass de eventos Eloquent): UPDATE/DELETE sobre `inventory_movements` → `QueryException` (trigger); UPDATE con `on_hand = -5` → `QueryException` (CHECK).

- [x] **T-23 — Feature: degradación de infraestructura** *(RF-15, RF-09)*
  Redis caído → 503 + `Retry-After: 5`; lock agotado → 503.
  **Hecho cuando:** los tests de degradación pasan (Redis detenido vía proceso del test, restaurado después).
  ✅ **Completada (2026-11-14):** lock agotado con lock ajeno (real, Redis) → `StockLockAcquisitionException` → handler → **503 + Retry-After: 5**; Redis inalcanzable (puerto 6399) → `StockInfrastructureUnavailableException` → **503 + Retry-After: 5**.

- [x] **T-24 — Test de concurrencia obligatorio** *(Spec §6.1.1 · Plan §4.2)*
  Pool de 20 procesos CLI reales sobre stock 10, keys únicas, `quantity 1`, presupuesto de tiempo máximo.
  **Hecho cuando:** exactamente 10×200 y 10×409, `on_hand` final 0, 10 movimientos, cero 503 en el run (run limpio).
  ✅ **Completada (2026-11-14):** pool de 20 procesos CLI (`tests/Concurrency/DeductStockProcess.php` + `Symfony\Component\Process\Process`) contra stock 10, keys únicas, quantity 1, `on_hand` final 0, 10 movimientos, **10×`ok` (200) + 10×`insufficient` (409), cero errores/503**; presupuesto de tiempo respetado (elapsed < 90 s).

## M8 — Cierre 

- [x] **T-25 — Gates finales y Definición de terminado** *(Art. II.4 · Spec §6.2)*
  Suite completa, Pint, PHPStan; revisión de la matriz RF↔módulo↔test completa.
  **Hecho cuando:** `pest` ✅ · `pint --test` ✅ · `phpstan` nivel 7 ✅ y los 19 RF están cubiertos por al menos un test en verde.
  ✅ **Completada (2026-11-14):** gates finales en verde — Pest **41/41 (151 aserciones)** · Pint `--test` ✅ · PHPStan nivel 7 (0 errores). Matriz RF↔módulo↔test (§4.3 del plan + §6.1 de la spec) completa y en verde: los 19 RF cubiertos (RF-01…RF-19 + §6.1.1/6.1.7). Definición de terminado (Spec §6.2) verificada: sin lógica en controlador (FormRequest → Action → Resource), excepciones de negocio en clases propias con mapper, sin Docker. Fase F1 (MVP del backend multi-almacén) **completa**.

## M9 — Inbound (F2)

- [x] **T-26 — Inbound: movimiento inbound + creación de inventario** *(Plan P-07 · RF-07a)*
  Migración: relajar CHECK de `inventory_movements.type` a `IN ('inbound', 'outbound')`. `InboundStockAction`: valida referencias/estado, crea movimiento inbound + crea/incrementa inventario (`on_hand += quantity`) en transacción. Tests: inbound crea fila inexistente / incrementa existente.
  **Hecho cuando:** inbound crea inventario inexistente → stock = quantity; inbound incrementa inventario existente → before/after exactos.
  ✅ **Completada (2026-11-14):** migración `2026_11_14_000006_relax_inventory_movements_type_check` (CHECK relajado a `IN ('inbound','outbound')`). `App\Actions\InboundStockAction` + DTOs `InboundStockCommand`/`InboundStockResult`. Tests: inbound crea fila inexistente (stock 0→5) y la incrementa (10→14 con before/after exactos). Gates: Pest 43/43 (164 aserciones) · Pint · PHPStan 7 ✅.
