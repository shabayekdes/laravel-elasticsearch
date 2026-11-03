<?php

namespace Shabayek\Elastic\Tests\Integration;

use Shabayek\Elastic\Tests\Integration\Fixtures\Book;
use Shabayek\Elastic\Tests\Integration\Fixtures\Note;

class DeleteTest extends IntegrationTestCase
{
    public function test_flush_deletes_every_document_of_the_model_with_delete_by_query_and_keeps_the_index()
    {
        $books = $this->createBookIndex();
        $this->books([['title' => 'Dune'], ['title' => 'Emma'], ['title' => 'Ulysses']])->searchable();
        $notes = (new Note)->indexableAs();
        $this->engine()->createIndex($notes);
        Note::create(['title' => 'Keep me']);

        Book::removeAllFromSearch();

        $this->assertSame(0, $this->documentCount($books));
        $this->assertSame('keyword', $this->client->indices()->getMapping(['index' => $books])->asArray()[$books]['mappings']['properties']['status']['type']);
        $this->assertSame(['1'], $this->documentIds($notes));
    }

    public function test_flush_of_a_missing_index_succeeds()
    {
        $this->artisan('scout:flush', ['model' => Book::class])->assertSuccessful();

        $this->assertFalse($this->client->indices()->exists(['index' => (new Book)->indexableAs()])->asBool());
    }

    public function test_unsearchable_on_a_query_deletes_only_the_matching_documents()
    {
        $index = $this->createBookIndex();
        $this->books([
            ['title' => 'Dune', 'status' => 'draft'],
            ['title' => 'Emma', 'status' => 'published'],
            ['title' => 'Ulysses', 'status' => 'draft'],
            ['title' => 'Beloved', 'status' => 'published'],
        ])->searchable();

        Book::where('status', 'draft')->unsearchable();

        $this->assertSame(['2', '4'], $this->documentIds($index));
        $this->assertEqualsCanonicalizing([2, 4], Book::search('')->get()->modelKeys());
    }
}
