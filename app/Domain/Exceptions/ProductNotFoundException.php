<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * RF-03: el product_id no existe en el catálogo local.
 * Mapeo HTTP (decidido en el handler): 404 Not Found.
 */
final class ProductNotFoundException extends AbstractDomainException {}
