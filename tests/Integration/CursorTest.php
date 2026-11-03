<?php

namespace Shabayek\Elastic\Tests\Integration;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use RuntimeException;
use Shabayek\Elastic\Exceptions\IncompleteSearchResultsException;
use Shabayek\Elastic\Tests\Integration\Fixtures\Book;

class CursorTest extends IntegrationTestCase
{
    protected string $index;

    /**
     * The point in time id sent with each page and returned by the server.
     *
     * @var array<int, array{sent: string, returned: string|null, search_after: array|null}>
     */
    protected array $pages = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->createBookIndex();
        $this->configure('elasticsearch.cursor.batch_size', 5);

        // 23 books: 4 full pages and a partial one. Views repeat, so sorting by
        // views needs the _shard_doc tiebreaker to page without gaps.
        $this->books(array_map(fn ($i) => ['title' => "Book {$i}", 'views' => $i % 4], range(1, 23)))->searchable();
    }

    /**
     * A search callback that records every page and sends it unchanged.
     */
    protected function recordingCallback(): callable
    {
        return function ($client, $query, array $params) {
            $response = $client->search($params);

            $this->pages[] = [
                'sent' => $params['body']['pit']['id'],
                'returned' => $response->asArray()['pit_id'] ?? null,
                'search_after' => $params['body']['search_after'] ?? null,
            ];

            return $response;
        };
    }

    protected function assertPointInTimeClosed(string $id): void
    {
        $this->assertSame(0, $this->openSearchContexts($this->index));

        try {
            $this->client->search(['body' => ['size' => 1, 'pit' => ['id' => $id]]]);
            $this->fail('The point in time must be closed.');
        } catch (ClientResponseException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    public function test_the_cursor_returns_every_document_exactly_once()
    {
        $ids = Book::search('')->cursor()->map(fn ($book) => $book->id)->all();

        $this->assertCount(23, $ids);
        $this->assertSame(range(1, 23), collect($ids)->sort()->values()->all());
    }

    public function test_the_cursor_pages_in_a_stable_order_across_equal_sort_values()
    {
        $books = Book::search('')->orderBy('views')->cursor()->all();

        $views = array_map(fn ($book) => $book->views, $books);
        $sorted = $views;
        sort($sorted);
        $this->assertSame($sorted, $views);
        $this->assertCount(23, array_unique(array_map(fn ($book) => $book->id, $books)));

        $again = array_map(fn ($book) => $book->id, Book::search('')->orderBy('views')->cursor()->all());
        $this->assertSame(array_map(fn ($book) => $book->id, $books), $again);
    }

    public function test_the_cursor_follows_the_point_in_time_id_returned_with_each_page_and_closes_the_latest()
    {
        $ids = Book::search('', $this->recordingCallback())->cursor()->map(fn ($book) => $book->id)->all();

        $this->assertCount(23, $ids);
        $this->assertCount(5, $this->pages);
        $this->assertNull($this->pages[0]['search_after']);

        foreach (array_slice($this->pages, 1) as $position => $page) {
            $this->assertSame($this->pages[$position]['returned'], $page['sent'], "Page {$position} must send the point in time id returned by the previous page.");
            $this->assertNotNull($page['search_after']);
        }

        $this->assertPointInTimeClosed(end($this->pages)['returned']);
    }

    public function test_the_point_in_time_is_open_while_iterating()
    {
        $openWhileIterating = null;

        foreach (Book::search('')->cursor() as $book) {
            $openWhileIterating ??= $this->openSearchContexts($this->index);
        }

        $this->assertGreaterThan(0, $openWhileIterating);
        $this->assertSame(0, $this->openSearchContexts($this->index));
    }

    public function test_the_cursor_reads_a_snapshot_taken_when_iteration_starts()
    {
        $seen = [];

        foreach (Book::search('')->cursor() as $book) {
            if ($seen === []) {
                $this->books([['title' => 'Added later']])->searchable();
            }

            $seen[] = $book->id;
        }

        $this->assertCount(23, $seen);
        $this->assertNotContains(24, $seen);
        $this->assertSame(24, $this->documentCount($this->index));
    }

    public function test_the_point_in_time_is_closed_when_iteration_stops_early()
    {
        $first = Book::search('', $this->recordingCallback())->cursor()->take(7)->all();

        $this->assertCount(7, $first);
        $this->assertCount(2, $this->pages);
        $this->assertPointInTimeClosed($this->pages[1]['returned']);
    }

    public function test_the_point_in_time_is_closed_when_the_consumer_throws()
    {
        try {
            foreach (Book::search('', $this->recordingCallback())->cursor() as $position => $book) {
                if ($position === 6) {
                    throw new RuntimeException('Consumer failed.');
                }
            }
            $this->fail('The exception must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Consumer failed.', $e->getMessage());
        }

        $this->assertPointInTimeClosed(end($this->pages)['returned']);
    }

    public function test_a_page_that_terminated_early_fails_and_closes_the_point_in_time()
    {
        $seen = 0;

        try {
            foreach (Book::search('', $this->recordingCallback())->options(['terminate_after' => 1])->cursor() as $book) {
                $seen++;
            }
            $this->fail('A page that terminated early must not be treated as complete.');
        } catch (IncompleteSearchResultsException $e) {
            $this->assertStringContainsString('terminated early', $e->getMessage());
        }

        $this->assertSame(0, $seen);
        $this->assertPointInTimeClosed(end($this->pages)['returned']);
    }

    public function test_a_page_with_failed_shards_fails_and_closes_the_point_in_time()
    {
        // The same field is a keyword in one index and an integer in the
        // other, so a term filter on a word fails on the second index's shard
        // while the first index still returns hits.
        $other = $this->prefix.'books_numeric';
        $this->engine()->createIndex($other, [
            'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0],
            'mappings' => ['properties' => ['status' => ['type' => 'integer']]],
        ]);
        $this->client->index(['index' => $other, 'id' => '1', 'refresh' => 'true', 'body' => ['status' => 1]]);
        Book::query()->update(['status' => 'published']);
        Book::all()->searchable();

        $seen = 0;

        try {
            foreach (Book::search('', $this->recordingCallback())->within($this->index.','.$other)->where('status', 'published')->cursor() as $book) {
                $seen++;
            }
            $this->fail('A page with failed shards must not be treated as complete.');
        } catch (IncompleteSearchResultsException $e) {
            $this->assertMatchesRegularExpression('/[1-9]\d* failed shard\(s\)/', $e->getMessage());
        }

        $this->assertSame(0, $seen);
        $this->assertSame(0, $this->openSearchContexts($other));
        $this->assertPointInTimeClosed(end($this->pages)['returned']);
    }
}
