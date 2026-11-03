<?php

namespace Shabayek\Elastic\Tests\Integration;

use Shabayek\Elastic\Exceptions\BulkOperationException;
use Shabayek\Elastic\Tests\Integration\Fixtures\Book;

class BulkIndexingTest extends IntegrationTestCase
{
    public function test_models_are_indexed_in_bulk_chunks_under_their_scout_key()
    {
        $index = $this->createBookIndex();
        $this->configure('elasticsearch.indexing.chunk_size', 2);
        $books = $this->books([
            ['title' => 'Dune', 'author' => 'Frank Herbert', 'status' => 'published', 'views' => 10],
            ['title' => 'Emma', 'author' => 'Jane Austen', 'status' => 'published', 'views' => 20],
            ['title' => 'Ulysses', 'author' => 'James Joyce', 'status' => 'draft', 'views' => 30],
            ['title' => 'Beloved', 'author' => 'Toni Morrison', 'status' => 'published', 'views' => 40],
            ['title' => 'Middlemarch', 'author' => 'George Eliot', 'status' => 'draft', 'views' => 50],
        ]);

        $books->searchable();

        $this->assertSame(['1', '2', '3', '4', '5'], $this->documentIds($index));

        $document = $this->client->get(['index' => $index, 'id' => '3'])->asArray();
        $this->assertSame(
            ['id' => 3, 'title' => 'Ulysses', 'author' => 'James Joyce', 'status' => 'draft', 'views' => 30, 'published_on' => null],
            $document['_source']
        );

        $found = Book::search('austen')->get();
        $this->assertSame([2], $found->modelKeys());
        $this->assertSame('Emma', $found->first()->title);
    }

    public function test_saving_a_model_updates_its_document()
    {
        $index = $this->createBookIndex();
        $book = Book::create(['title' => 'Dune', 'author' => 'Frank Herbert']);

        $book->update(['title' => 'Dune Messiah']);

        $this->assertSame(1, $this->documentCount($index));
        $this->assertSame('Dune Messiah', $this->client->get(['index' => $index, 'id' => (string) $book->id])->asArray()['_source']['title']);
        $this->assertSame([$book->id], Book::search('messiah')->get()->modelKeys());
    }

    public function test_a_rejected_document_is_reported_and_the_other_documents_are_indexed()
    {
        $index = $this->createBookIndex();
        $books = $this->books([
            ['title' => 'Dune', 'published_on' => '1965-08-01'],
            ['title' => 'Emma', 'published_on' => 'not a date'],
            ['title' => 'Ulysses', 'published_on' => '1922-02-02'],
        ]);

        try {
            $books->searchable();
            $this->fail('A document that does not match the mapping must be reported.');
        } catch (BulkOperationException $e) {
            $this->assertSame(['2'], $e->failedIds());
            $this->assertSame(3, $e->total());

            $failure = $e->failures()[0];
            $this->assertSame('index', $failure['operation']);
            $this->assertSame($index, $failure['index']);
            $this->assertSame(400, $failure['status']);
            $this->assertContains($failure['type'], ['document_parsing_exception', 'mapper_parsing_exception']);
        }

        $this->assertSame(['1', '3'], $this->documentIds($index));
    }

    public function test_deleting_models_removes_their_documents()
    {
        $index = $this->createBookIndex();
        $books = $this->books([['title' => 'Dune'], ['title' => 'Emma'], ['title' => 'Ulysses']]);
        $books->searchable();

        $books->only([1, 3])->unsearchable();

        $this->assertSame(['2'], $this->documentIds($index));
    }

    public function test_deleting_a_model_that_was_never_indexed_succeeds()
    {
        $index = $this->createBookIndex();
        $books = $this->books([['title' => 'Dune'], ['title' => 'Emma']]);
        $books->first()->searchable();

        $books->unsearchable();

        $this->assertSame(0, $this->documentCount($index));
    }
}
