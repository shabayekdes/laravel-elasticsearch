<?php

namespace Shabayek\Elastic\Tests\Integration;

use Shabayek\Elastic\Tests\Integration\Fixtures\Note;

class SoftDeleteTest extends IntegrationTestCase
{
    protected string $index;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('scout.soft_delete', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = (new Note)->indexableAs();
        $this->engine()->createIndex($this->index);

        Note::create(['title' => 'Live note']);
        Note::create(['title' => 'Deleted note'])->delete();
        Note::create(['title' => 'Another live note']);
    }

    public function test_a_soft_deleted_model_stays_indexed_and_is_marked_deleted()
    {
        $this->assertSame(['1', '2', '3'], $this->documentIds($this->index));

        $this->assertSame(0, $this->client->get(['index' => $this->index, 'id' => '1'])->asArray()['_source']['__soft_deleted']);
        $this->assertSame(1, $this->client->get(['index' => $this->index, 'id' => '2'])->asArray()['_source']['__soft_deleted']);
    }

    public function test_searches_exclude_soft_deleted_models()
    {
        $this->assertEqualsCanonicalizing([1, 3], Note::search('note')->get()->modelKeys());
        $this->assertSame(2, Note::search('note')->paginate(10)->total());
    }

    public function test_with_trashed_includes_soft_deleted_models()
    {
        $results = Note::search('note')->withTrashed()->get();

        $this->assertEqualsCanonicalizing([1, 2, 3], $results->modelKeys());
        $this->assertTrue($results->firstWhere('id', 2)->trashed());
    }

    public function test_only_trashed_returns_only_soft_deleted_models()
    {
        $results = Note::search('note')->onlyTrashed()->get();

        $this->assertSame([2], $results->modelKeys());
        $this->assertTrue($results->first()->trashed());
    }

    public function test_the_cursor_excludes_soft_deleted_models()
    {
        $this->assertEqualsCanonicalizing([1, 3], Note::search('')->cursor()->map(fn ($note) => $note->id)->all());
        $this->assertSame([2], Note::search('')->onlyTrashed()->cursor()->map(fn ($note) => $note->id)->all());
    }

    public function test_restoring_and_force_deleting_update_the_index()
    {
        Note::withTrashed()->find(2)->restore();
        $this->assertEqualsCanonicalizing([1, 2, 3], Note::search('note')->get()->modelKeys());

        Note::find(1)->forceDelete();
        $this->assertSame(['2', '3'], $this->documentIds($this->index));
        $this->assertEqualsCanonicalizing([2, 3], Note::search('note')->get()->modelKeys());
    }
}
