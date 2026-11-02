<?php

namespace Shabayek\Elastic\Exceptions;

use RuntimeException;

/**
 * Thrown when Elasticsearch returns partial results (failed shards or a
 * timeout) while iterating a cursor, which would otherwise skip records.
 */
class IncompleteSearchResultsException extends RuntimeException
{
    /**
     * Create an exception from a raw search response.
     *
     * @param  array  $results
     * @return static
     */
    public static function fromResults(array $results): static
    {
        $failed = (int) ($results['_shards']['failed'] ?? 0);
        $reasons = array_filter(array_map(
            fn ($failure) => $failure['reason']['reason'] ?? $failure['reason']['type'] ?? null,
            $results['_shards']['failures'] ?? []
        ));

        return new static(sprintf(
            'Elasticsearch returned incomplete results (%s%s)%s.',
            $failed > 0 ? "{$failed} failed shard(s)" : 'no failed shards',
            ! empty($results['timed_out']) ? ', timed out' : '',
            $reasons ? ': '.implode('; ', array_unique($reasons)) : ''
        ));
    }
}
