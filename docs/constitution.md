# 📜 Backend Constitution - Inventory SaaS Microservices

## Preámbulo
Esta Constitución define los principios vinculantes del backend distribuido (PHP 8.3+, Laravel, PostgreSQL, Redis). Ningún agente de IA, PR, refactoring o feature nueva puede violar estos artículos. Una violación bloquea el merge, sin excepciones.

---

## Artículo I: Invariantes de Concurrencia e Integridad de Datos

1. **Cero Sobreventas (Zero Over-selling):** Toda operación que mute stock (descuento, reserva, transferencia, ajuste) DEBE adquirir primero un **Redis Distributed Lock** con clave por `producto + almacén` (ej. `lock:stock:{sku}:{warehouse_id}`), con TTL definido y liberación garantizada en un bloque `finally`.
2. **Atomicidad Transaccional:** Toda mutación que afecte múltiples tablas (inventario + movimiento de auditoría) DEBE correr dentro de una transacción de base de datos (`DB::transaction(...)`). Orden estricto obligatorio: **lock → transacción → release**. Está prohibido adquirir locks dentro de la transacción.
3. **Inmutabilidad Append-Only:** Las tablas de auditoría y movimientos (`inventory_movements`, `audit_logs`) son de solo lectura e inserción (`append-only`). Está estrictamente prohibido ejecutar `UPDATE` o `DELETE` sobre ellas. Las correcciones se realizan mediante **movimientos compensatorios** (contra-movimiento), nunca editando el histórico.

---

## Artículo II: Tipado Estricto y Calidad de Código PHP

1. **Declaración Obligatoria:** Todo archivo PHP del backend DEBE iniciar con `declare(strict_types=1);`.
2. **Tipado Fuerte en Firmas:** El uso de tipos de datos es obligatorio en parámetros, valores de retorno y propiedades. Prohibido `mixed` salvo justificación documentada en el PR. Prohibido tipo `array` sin docblock de forma (ej. `@param array<int, Item>`).
3. **Compatibilidad PHPStan:** El código debe pasar el análisis estático (PHPStan, nivel ≥ 7) sin errores ni supresiones (`@phpstan-ignore`) sin justificación explícita.
4. **Verificación Automática:** La suite de tests (Pest) + formateo (Pint) + análisis estático (PHPStan) deben pasar antes de cerrar cualquier tarea.

---

## Artículo III: Arquitectura y Límites de Microservicios (Domain-Driven Design)

1. **Aislamiento de Bases de Datos:** Cada microservicio es dueño exclusivo de su base de datos (ej. `inventory_db`, `auth_db`). Está prohibido realizar cross-joins, FKs cruzadas o referencias directas a tablas de otro servicio (ej: `inventory_service` no puede consultar directamente la tabla de usuarios de `auth_service`). El acceso a datos externos es únicamente vía API o eventos.
2. **Controladores Delgados:** Los controladores tienen una única responsabilidad: recibir la petición HTTP, delegar la ejecución a una clase de Action o Service, y retornar un `JsonResource` o respuesta HTTP. Cero lógica de negocio, cero queries Eloquent inline en controladores.
3. **Comunicación Desacoplada:** La comunicación entre microservicios debe ser asíncrona mediante eventos en Redis Streams / colas Laravel / RabbitMQ. Las llamadas HTTP síncronas en cadena están prohibidas; cuando sean imprescindibles, requieren timeout, circuit breaker y tolerancia a fallos.
4. **Contratos de Dominio:** Cada servicio expone contratos versionados (esquemas de eventos / DTOs). Los breaking changes requieren una nueva versión del contrato.

---

## Artículo IV: Seguridad y Manejo de Errores

1. **Excepciones de Negocio Tipadas:** Cada error de dominio tiene su excepción personalizada que mapea a un código HTTP semántico correcto (ej. `InsufficientStockException` → 409 Conflict, `InvalidWarehouseException` → 404 Not Found, validaciones → 422 Unprocessable Entity). Prohibido lanzar `HttpException` genéricas desde la capa de dominio.
2. **Validación Exhaustiva:** Toda entrada de datos proveniente de una petición HTTP debe validarse obligatoriamente mediante un `FormRequest` con reglas explícitas y autorización (`authorize()`) antes de tocar cualquier capa de negocio. Prohibido leer `$request->all()` crudo en Actions.
3. **Fallos Explícitos:** Los errores se registran con contexto estructurado (correlation ID, servicio, SKU, warehouse) para garantizar trazabilidad entre servicios.

---

## Artículo V: Gobernanza y Cumplimiento

1. **Excepciones a las Reglas:** Cualquier desviación de esta Constitución requiere aprobación explícita, documentada en el PR con la justificación técnica correspondiente.
2. **Lectura Obligatoria:** Los agentes de IA y contribuidores DEBEN leer esta Constitución y la spec activa antes de tocar cualquier código.
3. **Entorno de Ejecución Local:** Este proyecto NO utiliza Docker. Los comandos de verificación se ejecutan directamente en el host con PHP 8.3+ y Composer: Tests: `./vendor/bin/pest` · Estilo: `./vendor/bin/pint` · Análisis estático: `./vendor/bin/phpstan`. PostgreSQL y Redis se provisionan localmente (o como servicios gestionados) vía variables de entorno en `.env`. Queda prohibido añadir `docker-compose.yml`, Dockerfiles o dependencias de contenedores sin aprobación explícita.
