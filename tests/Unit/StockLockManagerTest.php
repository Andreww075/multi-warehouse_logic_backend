<?php

declare(strict_types=1);

use App\Domain\Exceptions\StockInfrastructureUnavailableException;
use App\Domain\Exceptions\StockLockAcquisitionException;
use App\Infrastructure\Stock\StockLockHandle;
use App\Infrastructure\Stock\StockLockManager;
use Illuminate\Cache\RedisLock;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Facades\Redis;
use Predis\PredisException;

it('builds the canonical lock key from the Constitution (Art. I.1 / P-01)', function (): void {
    expect(StockLockManager::key('SKU-001', 'WH-01'))
        ->toBe('lock:stock:SKU-001:WH-01');
});

it('acquires and releases a lock over real Redis, and the key lives and dies', function (): void {
    $manager = new StockLockManager(app(Factory::class));
    $key = StockLockManager::key('SKU-ACQ', 'WH-ACQ');

    $handle = $manager->acquire('SKU-ACQ', 'WH-ACQ');

    expect($handle)->toBeInstanceOf(StockLockHandle::class)
        ->and($handle->key)->toBe($key)
        ->and((bool) Redis::connection()->exists($key))->toBeTrue();

    $handle->release();

    expect((bool) Redis::connection()->exists($key))->toBeFalse();
});

it('throws StockLockAcquisitionException when the lock is held beyond the blocking wait (RF-09)', function (): void {
    $key = StockLockManager::key('SKU-TIMEOUT', 'WH-TIMEOUT');

    // Otro dueño mantiene el lock por 10 s (mucho más que la espera de 1 s del test).
    $other = new RedisLock(Redis::connection(), $key, 10);
    expect($other->get())->toBeTrue();

    $manager = new StockLockManager(app(Factory::class), blockingWaitSeconds: 1);

    $manager->acquire('SKU-TIMEOUT', 'WH-TIMEOUT');
})->throws(StockLockAcquisitionException::class);

it('releases an expired lock safely: the new owner keeps its lock (RF-16)', function (): void {
    $manager = new StockLockManager(app(Factory::class), ownershipTtlSeconds: 1);
    $key = StockLockManager::key('SKU-EXPIRE', 'WH-EXPIRE');

    $first = $manager->acquire('SKU-EXPIRE', 'WH-EXPIRE');
    usleep(1_300_000); // TTL de propiedad 1 s: el lock expiró y fue purgado.

    // Un segundo proceso adquiere el lock con su propio token.
    $second = new RedisLock(Redis::connection(), $key, 10);
    expect($second->get())->toBeTrue();

    // El `finally` del primer proceso libera: no debe tocar el lock del nuevo dueño.
    $first->release();

    expect((bool) Redis::connection()->exists($key))->toBeTrue();

    $second->release();
});

it('can re-acquire after ownership TTL expiry', function (): void {
    $manager = new StockLockManager(app(Factory::class), ownershipTtlSeconds: 1);

    $first = $manager->acquire('SKU-REACQ', 'WH-REACQ');
    usleep(1_300_000);
    $first->release(); // No-op seguro (lock ya expirado).

    $second = $manager->acquire('SKU-REACQ', 'WH-REACQ');
    $second->release();

    expect((bool) Redis::connection()->exists(StockLockManager::key('SKU-REACQ', 'WH-REACQ')))->toBeFalse();
});

it('converts Redis connection failures to StockInfrastructureUnavailableException (RF-15)', function (): void {
    $failingFactory = new class implements Factory
    {
        public function connection($name = null): never
        {
            throw new class('connection refused') extends PredisException {};
        }
    };

    $manager = new StockLockManager($failingFactory);

    $manager->acquire('SKU-DOWN', 'WH-DOWN');
})->throws(StockInfrastructureUnavailableException::class);
