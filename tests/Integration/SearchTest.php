<?php

namespace Shabayek\Elastic\Tests\Integration;

use Shabayek\Elastic\Tests\Integration\Fixtures\Book;

class SearchTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createBookIndex();

        $this->books([
            ['title' => 'Dune', 'author' => 'Frank Herbert', 'status' => 'published', 'views' => 50],
            ['title' => 'Dune Messiah', 'author' => 'Frank Herbert', 'status' => 'draft', 'views' => 10],
            ['title' => 'Children of Dune', 'author' => 'Frank Herbert', 'status' => 'published', 'views' => 30],
            ['title' => 'Emma', 'author' => 'Jane Austen', 'status' => 'published', 'views' => 40],
            ['title' => 'Persuasion', 'author' => 'Jane Austen', 'status' => null, 'views' => 20],
            ['title' => 'Ulysses', 'author' => 'James Joyce', 'views' => 60],
        ])->searchable();

        // Store book 6 without a status field at all, unlike book 5 whose status is null.
        $this->client->index([
            'index' => (new Book)->indexableAs(),
            'id' => '6',
            'refresh' => 'true',
            'body' => ['id' => 6, 'title' => 'Ulysses', 'author' => 'James Joyce', 'views' => 60],
        ]);
    }

    public function test_a_full_text_search_returns_the_matching_models_in_relevance_order()
    {
        $results = Book::search('dune')->get();

        $this->assertEqualsCanonicalizing([1, 2, 3], $results->modelKeys());
        // The shortest matching title scores highest.
        $this->assertSame(1, $results->first()->id);
        $this->assertSame('Dune', $results->first()->title);
        $this->assertInstanceOf(Book::class, $results->first());

        $scores = array_map(fn ($book) => $book->scoutMetadata()['_score'], $results->all());
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);
    }

    public function test_the_query_searches_every_searchable_field()
    {
        $this->assertEqualsCanonicalizing([4, 5], Book::search('austen')->get()->modelKeys());
        $this->assertSame([], Book::search('tolstoy')->get()->modelKeys());
    }

    public function test_an_empty_query_matches_every_document()
    {
        $this->assertCount(6, Book::search('')->get());
    }

    public function test_where_and_where_in_filter_without_changing_the_matches()
    {
        $this->assertEqualsCanonicalizing([1, 3], Book::search('dune')->where('status', 'published')->get()->modelKeys());
        $this->assertEqualsCanonicalizing([1, 2, 3, 4], Book::search('')->whereIn('status', ['draft', 'published'])->get()->modelKeys());
    }

    public function test_where_not_in_also_matches_documents_with_a_missing_or_null_field()
    {
        // Book 5 has a null status and book 6 has no status field.
        $this->assertEqualsCanonicalizing([2, 5, 6], Book::search('')->whereNotIn('status', ['published'])->get()->modelKeys());
    }

    public function test_where_null_matches_documents_with_a_missing_or_null_field()
    {
        $this->assertEqualsCanonicalizing([5, 6], Book::search('')->where('status', null)->get()->modelKeys());
    }

    public function test_order_by_and_take()
    {
        $this->assertSame([6, 1, 4], Book::search('')->orderBy('views', 'desc')->take(3)->get()->modelKeys());
        $this->assertSame([2, 5], Book::search('')->orderBy('views')->take(2)->get()->modelKeys());
    }

    public function test_paginate_returns_the_requested_page_and_the_exact_total()
    {
        $page = Book::search('')->orderBy('views')->paginate(2, 'page', 2);

        $this->assertSame([3, 4], array_map(fn ($book) => $book->id, $page->items()));
        $this->assertSame(6, $page->total());
        $this->assertSame(3, $page->lastPage());
    }

    public function test_raw_returns_the_server_response_as_an_array()
    {
        $raw = Book::search('dune')->raw();

        $this->assertIsArray($raw);
        $this->assertSame(['value' => 3, 'relation' => 'eq'], $raw['hits']['total']);
        $this->assertFalse($raw['timed_out']);
        $this->assertSame(0, $raw['_shards']['failed']);
        $this->assertEqualsCanonicalizing(['1', '2', '3'], array_column($raw['hits']['hits'], '_id'));
        $this->assertSame((new Book)->searchableAs(), $raw['hits']['hits'][0]['_index']);
    }

    public function test_keys_returns_the_matching_scout_keys()
    {
        $this->assertEqualsCanonicalizing([4, 5], Book::search('austen')->keys()->map(fn ($key) => (int) $key)->all());
    }

    public function test_options_are_sent_with_the_search()
    {
        $raw = Book::search('dune')->options(['highlight' => ['fields' => ['title' => new \stdClass()]]])->raw();

        $highlights = array_column(array_column($raw['hits']['hits'], 'highlight'), 'title');
        $this->assertContains(['<em>Dune</em>'], $highlights);
    }
}
