<?php

namespace Shabayek\Elastic\Tests\Support;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Simulates Elasticsearch point in time (PIT) searches with search_after.
 *
 * Documents are served in the given order, which stands for the total order
 * (sort values, then the _shard_doc tiebreaker). A page starts right after the
 * _shard_doc value in "search_after", so the engine must pass the last sort
 * values back correctly. Every page returns a new PIT id, which the next
 * request must use.
 */
class FakePointInTimeServer
{
    /** @var array<int, array{index: string, keep_alive: string}> */
    public array $opened = [];

    /** @var array<int, string> */
    public array $closed = [];

    /** @var array<int, array> Bodies of the PIT search requests. */
    public array $searches = [];

    /** Page number (1-based) answered with an HTTP 500. */
    public ?int $failOnPage = null;

    /** Page number (1-based) answered with a failed shard. */
    public ?int $shardFailureOnPage = null;

    /** Page number (1-based) answered with "terminated_early": true. */
    public ?int $terminatedEarlyOnPage = null;

    /** HTTP status of the close request. */
    public int $closeStatus = 200;

    protected string $currentId = '';

    protected int $pages = 0;

    /**
     * @param  array<int, array{id: int|string, score?: float, source?: array}>  $documents
     */
    public function __construct(protected array $documents = [], protected string $index = 'posts')
    {
    }

    public function __invoke(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        parse_str($request->getUri()->getQuery(), $query);

        if ($request->getMethod() === 'POST' && str_ends_with($path, '/_pit')) {
            $this->opened[] = ['index' => trim(substr($path, 0, -5), '/'), 'keep_alive' => $query['keep_alive'] ?? ''];
            $this->currentId = 'pit-0';

            return FakeHttpClient::response(['id' => $this->currentId]);
        }

        if ($request->getMethod() === 'DELETE' && $path === '/_pit') {
            $this->closed[] = json_decode((string) $request->getBody(), true)['id'] ?? '';

            return FakeHttpClient::response(['succeeded' => $this->closeStatus === 200, 'num_freed' => 1], $this->closeStatus);
        }

        if ($request->getMethod() === 'POST' && $path === '/_search') {
            return $this->search(json_decode((string) $request->getBody(), true));
        }

        throw new RuntimeException("Unexpected request {$request->getMethod()} {$path}");
    }

    protected function search(array $body): ResponseInterface
    {
        $this->searches[] = $body;
        $this->pages++;

        if (($body['pit']['id'] ?? null) !== $this->currentId) {
            return FakeHttpClient::response(['error' => ['type' => 'search_context_missing_exception']], 404);
        }

        if ($this->pages === $this->failOnPage) {
            return FakeHttpClient::response(['error' => ['type' => 'search_phase_execution_exception']], 500);
        }

        $start = isset($body['search_after']) ? end($body['search_after']) + 1 : 0;
        $hits = [];

        foreach (array_slice($this->documents, $start, $body['size'], true) as $position => $document) {
            $score = $document['score'] ?? 1.0;
            $hits[] = [
                '_index' => $this->index,
                '_id' => (string) $document['id'],
                '_score' => $score,
                '_source' => $document['source'] ?? [],
                'sort' => [$score, $position],
            ];
        }

        $this->currentId = 'pit-'.$this->pages;
        $failed = $this->pages === $this->shardFailureOnPage ? 1 : 0;
        $terminatedEarly = $this->terminatedEarlyOnPage === null ? [] : ['terminated_early' => $this->pages === $this->terminatedEarlyOnPage];

        return FakeHttpClient::response([
            'pit_id' => $this->currentId,
            'took' => 1,
            'timed_out' => false,
            ...$terminatedEarly,
            '_shards' => [
                'total' => 2,
                'successful' => 2 - $failed,
                'skipped' => 0,
                'failed' => $failed,
                'failures' => $failed ? [['shard' => 1, 'reason' => ['type' => 'node_disconnected_exception', 'reason' => 'node left']]] : [],
            ],
            'hits' => ['total' => null, 'max_score' => null, 'hits' => $hits],
        ]);
    }
}
