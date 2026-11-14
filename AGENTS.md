# AGENTS.md

## Proyecto
Sistema de backend distribuido en microservicios con Laravel para la gestión de inventarios multi-almacén. Utiliza PHP 8.3+, PostgreSQL, y Redis para bloqueos distribuidos orientados a prevenir sobreventas bajo alta concurrencia, ejecutado de forma local y nativa.

## Comandos
- Ejecutar: `php artisan serve`
- Tests: `./vendor/bin/pest`
- Lint/formato: `./vendor/bin/pint`

## Estilo y convenciones
- PHP 8.3+, tipado estricto obligatorio (`declare(strict_types=1);`) en todos los archivos.
- Clases, métodos y nombres de tablas en inglés (PSR-12). Código organizado bajo principios de Domain-Driven Design (controladores delgados, lógica en Actions/Services).
- Mensajes de commit y documentación interna en español.

## Reglas
- Lee docs/constitution.md y la spec activa antes de tocar código.
- No modifiques la lógica de concurrencia o los locks de Redis sin respetar transacciones atómicas. No añadas paquetes externos a Composer sin autorización previa. No implementes contenedores Docker ni dependencias de contenedores por el momento.

## Al terminar cualquier tarea
- Ejecutar la suite de tests de Pest y el linter de Laravel Pint para verificar que no existan errores ni violaciones de estilo.