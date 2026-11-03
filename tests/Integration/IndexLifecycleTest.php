<?php

namespace Shabayek\Elastic\Tests\Integration;

use Shabayek\Elastic\Tests\Integration\Fixtures\Book;

class IndexLifecycleTest extends IntegrationTestCase
{
    public function test_create_index_applies_the_given_mappings_and_settings()
    {
        $index = $this->prefix.'custom';

        $this->engine()->createIndex($index, [
            'settings' => ['number_of_shards' => 2, 'number_of_replicas' => 0],
            'mappings' => ['properties' => ['status' => ['type' => 'keyword'], 'views' => ['type' => 'integer']]],
        ]);

        $this->assertTrue($this->client->indices()->exists(['index' => $index])->asBool());

        $mapping = $this->client->indices()->getMapping(['index' => $index])->asArray()[$index]['mappings'];
        $this->assertSame(['status' => ['type' => 'keyword'], 'views' => ['type' => 'integer']], $mapping['properties']);

        $settings = $this->client->indices()->getSettings(['index' => $index])->asArray()[$index]['settings']['index'];
        $this->assertSame('2', $settings['number_of_shards']);
        $this->assertSame('0', $settings['number_of_replicas']);
    }

    public function test_create_index_applies_the_default_analysis_settings()
    {
        $index = $this->prefix.'analysed';

        $this->engine()->createIndex($index);

        $analysis = $this->client->indices()->getSettings(['index' => $index])->asArray()[$index]['settings']['index']['analysis'];
        $this->assertSame('custom', $analysis['analyzer']['default']['type']);
        $this->assertSame('word_delimiter', $analysis['filter']['words_splitter']['type']);

        $tokens = fn (string $text) => array_column($this->client->indices()->analyze([
            'index' => $index,
            'body' => ['text' => $text],
        ])->asArray()['tokens'], 'token');

        // Letter/number boundaries and dots are split and also joined; hyphens
        // are already split by the standard tokenizer, so nothing is joined.
        $this->assertEqualsCanonicalizing(['iphone', '15', 'iphone15'], $tokens('iPhone15'));
        $this->assertEqualsCanonicalizing(['wi', 'fi', 'wifi'], $tokens('wi.fi'));
        $this->assertEqualsCanonicalizing(['wi', 'fi'], $tokens('<b>Wi-Fi</b>'));
    }

    public function test_create_index_leaves_an_existing_index_untouched()
    {
        $index = $this->prefix.'existing';
        $this->engine()->createIndex($index, ['mappings' => ['properties' => ['status' => ['type' => 'keyword']]]]);

        $response = $this->engine()->createIndex($index, ['mappings' => ['properties' => ['status' => ['type' => 'text']]]]);

        $this->assertSame('keyword', $response[$index]['mappings']['properties']['status']['type']);
        $mapping = $this->client->indices()->getMapping(['index' => $index])->asArray();
        $this->assertSame('keyword', $mapping[$index]['mappings']['properties']['status']['type']);
    }

    public function test_delete_index_deletes_the_index()
    {
        $index = $this->prefix.'deleted';
        $this->engine()->createIndex($index);

        $response = $this->engine()->deleteIndex($index);

        $this->assertTrue($response['acknowledged']);
        $this->assertFalse($this->client->indices()->exists(['index' => $index])->asBool());
    }

    public function test_the_scout_index_commands_create_and_delete_the_model_index()
    {
        $index = (new Book)->indexableAs();

        $this->artisan('scout:index', ['name' => Book::class])->assertSuccessful();
        $this->assertTrue($this->client->indices()->exists(['index' => $index])->asBool());

        $this->artisan('scout:delete-index', ['name' => Book::class])->assertSuccessful();
        $this->assertFalse($this->client->indices()->exists(['index' => $index])->asBool());
    }

    public function test_the_deprecated_create_command_creates_the_model_index()
    {
        $index = (new Book)->indexableAs();

        $this->artisan('scout:elasticsearch:create', ['model' => Book::class])->assertExitCode(0);
        $this->assertTrue($this->client->indices()->exists(['index' => $index])->asBool());

        $this->artisan('scout:elasticsearch:create', ['model' => Book::class])->assertExitCode(0);
    }
}
