<?php

declare(strict_types=1);

use App\Support\CorrelationId;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Route::get('/_probe-correlation', fn (): string => 'ok')
        ->middleware('correlation');
});

it('echoes a valid incoming X-Correlation-ID verbatim (RF-18)', function (): void {
    $correlationId = (string) Str::uuid();

    $response = $this->get('/_probe-correlation', [CorrelationId::HEADER => $correlationId]);

    expect($response->headers->get(CorrelationId::HEADER))->toBe($correlationId);
});

it('generates a fresh UUID when the header is missing (RF-18)', function (): void {
    $response = $this->get('/_probe-correlation');

    expect(Str::isUuid((string) $response->headers->get(CorrelationId::HEADER)))->toBeTrue();
});

it('generates a fresh UUID when the header is not a valid UUID (RF-18)', function (): void {
    $response = $this->get('/_probe-correlation', [CorrelationId::HEADER => 'not-a-uuid']);

    $resolved = (string) $response->headers->get(CorrelationId::HEADER);

    expect(Str::isUuid($resolved))->toBeTrue()
        ->and($resolved)->not->toBe('not-a-uuid');
});

it('pushes the resolved correlation_id into the log context (RF-13)', function (): void {
    $correlationId = (string) Str::uuid();

    Log::shouldReceive('withContext')
        ->once()
        ->with(['correlation_id' => $correlationId]);

    $this->get('/_probe-correlation', [CorrelationId::HEADER => $correlationId]);
});
