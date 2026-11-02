<?php

namespace Shabayek\Elastic\Exceptions;

use RuntimeException;

/**
 * Thrown when one or more operations of an Elasticsearch bulk request fail.
 *
 * The bulk API answers HTTP 200 even when individual operations fail, so the
 * failures are collected from the per-operation results.
 */
class BulkOperationException extends RuntimeException
{
    /**
     * The failed operations.
     *
     * @var array<int, array{operation: string, index: string, id: string, status: int|null, type: string|null, reason: string|null}>
     */
    protected array $failures;

    /**
     * The number of operations that were sent.
     */
    protected int $total;

    /**
     * Create a new exception instance.
     *
     * @param  array<int, array{operation: string, index: string, id: string, status: int|null, type: string|null, reason: string|null}>  $failures
     * @param  int  $total
     */
    public function __construct(array $failures, int $total)
    {
        $this->failures = array_values($failures);
        $this->total = $total;

        parent::__construct($this->buildMessage());
    }

    /**
     * Get the failed operations.
     *
     * @return array<int, array{operation: string, index: string, id: string, status: int|null, type: string|null, reason: string|null}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Get the document ids of the failed operations.
     *
     * @return array<int, string>
     */
    public function failedIds(): array
    {
        return array_column($this->failures, 'id');
    }

    /**
     * Get the number of operations that were sent.
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * Build a readable summary of the failures.
     */
    protected function buildMessage(): string
    {
        $shown = array_slice($this->failures, 0, 5);

        $details = array_map(fn (array $failure) => sprintf(
            '%s [%s/%s] %s%s',
            $failure['operation'],
            $failure['index'],
            $failure['id'],
            $failure['type'] ?? 'error',
            $failure['reason'] !== null ? ': '.$failure['reason'] : ''
        ), $shown);

        $more = count($this->failures) - count($shown);

        return sprintf(
            'Elasticsearch bulk request failed for %d of %d operations: %s%s',
            count($this->failures),
            $this->total,
            implode('; ', $details),
            $more > 0 ? "; and {$more} more" : ''
        );
    }
}
