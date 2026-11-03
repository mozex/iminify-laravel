<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\Events;

use Mozex\Iminify\Exceptions\IminifyException;
use Throwable;

/**
 * The optimizer left a file as it was, because of `$reason`: a spent daily allowance, a
 * timeout, a refused key, or an answer it wouldn't write back. The file is still usable.
 *
 * A queued listener gets `$reason` as an `IminifyException` holding the original's class
 * and message, since an exception's trace can hold what a queue can't carry.
 */
class OptimizationSkippedEvent
{
    public function __construct(
        public readonly string $path,
        public readonly Throwable $reason,
    ) {}

    /**
     * @return array{path: string, reason: string, code: int}
     */
    public function __serialize(): array
    {
        return [
            'path' => $this->path,
            'reason' => '['.$this->reason::class.'] '.$this->reason->getMessage(),
            'code' => (int) $this->reason->getCode(),
        ];
    }

    /**
     * @param  array{path?: mixed, reason?: mixed, code?: mixed}  $data
     */
    public function __unserialize(array $data): void
    {
        $this->path = is_string($data['path'] ?? null) ? $data['path'] : '';
        $this->reason = new IminifyException(is_string($data['reason'] ?? null) ? $data['reason'] : '', is_int($data['code'] ?? null) ? $data['code'] : 0);
    }
}
