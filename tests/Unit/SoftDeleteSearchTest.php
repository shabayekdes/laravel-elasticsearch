<?php

namespace Shabayek\Elastic\Tests\Unit;

use InvalidArgumentException;
use Laravel\Scout\Builder;
use Shabayek\Elastic\Builders\SearchBuilder;
use Shabayek\Elastic\Tests\EngineTestCase;
use Shabayek\Elastic\Tests\Fixtures\Article;
use Shabayek\Elastic\Tests\Fixtures\Post;

class SoftDeleteSearchTest extends EngineTestCase
{
    /**
     * The term filters of a built search request.
     */
    protected function terms(Builder $builder): array
    {
        $must = (new SearchBuilder($builder))->toArray()['body']['query']['bool']['must'] ?? [];

        return array_values(array_column($must, 'term'));
    }

    protected function articleBuilder(array $wheres): Builder
    {
        $builder = new Builder(new Article(), '');
        $builder->wheres = $wheres;

        return $builder;
    }

    public function test_scout_10_where_constraints_become_term_filters()
    {
        $builder = $this->articleBuilder(['__soft_deleted' => 0, 'status' => 'published']);

        $this->assertSame([['__soft_deleted' => 0], ['status' => 'published']], $this->terms($builder));
    }

    public function test_scout_11_where_rows_become_term_filters()
    {
        $builder = $this->articleBuilder([
            ['field' => '__soft_deleted', 'operator' => '=', 'value' => 0],
            ['field' => 'status', 'operator' => '=', 'value' => 'published'],
        ]);

        $this->assertSame([['__soft_deleted' => 0], ['status' => 'published']], $this->terms($builder));
    }

    public function test_both_representations_build_the_same_request()
    {
        $scout10 = $this->articleBuilder(['__soft_deleted' => 1]);
        $scout11 = $this->articleBuilder([['field' => '__soft_deleted', 'operator' => '=', 'value' => 1]]);

        $this->assertSame((new SearchBuilder($scout10))->toArray(), (new SearchBuilder($scout11))->toArray());
    }

    public function test_unsupported_scout_11_operators_are_rejected_instead_of_sending_an_invalid_query()
    {
        $builder = $this->articleBuilder([['field' => 'views', 'operator' => '>', 'value' => 10]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [>] operator is not supported');

        new SearchBuilder($builder);
    }

    public function test_equality_where_works_with_the_installed_scout_version()
    {
        $this->http->push($this->searchResponse([]));

        Post::search('post')->where('title', 'post 1')->raw();

        $this->assertSame([['title' => 'post 1']], $this->filtersSent());
    }

    public function test_soft_deleted_records_are_excluded_from_searches()
    {
        $this->app['config']->set('scout.soft_delete', true);
        [$live, $trashed] = $this->seedArticles();
        // Elasticsearch applies the __soft_deleted filter, so only the live article matches.
        $this->http->push($this->searchResponse([$live->id]));

        $results = Article::search('article')->get();

        $this->assertSame([['__soft_deleted' => 0]], $this->filtersSent());
        $this->assertSame([$live->id], $results->pluck('id')->all());
        $this->assertNotContains($trashed->id, $results->pluck('id')->all());
    }

    public function test_with_trashed_searches_without_the_soft_delete_filter()
    {
        $this->app['config']->set('scout.soft_delete', true);
        [$live, $trashed] = $this->seedArticles();
        $this->http->push($this->searchResponse([$trashed->id, $live->id]));

        $results = Article::search('article')->withTrashed()->get();

        $this->assertSame([], $this->filtersSent());
        $this->assertSame([$trashed->id, $live->id], $results->pluck('id')->all());
    }

    public function test_only_trashed_searches_soft_deleted_records()
    {
        $this->app['config']->set('scout.soft_delete', true);
        [, $trashed] = $this->seedArticles();
        $this->http->push($this->searchResponse([$trashed->id]));

        $results = Article::search('article')->onlyTrashed()->get();

        $this->assertSame([['__soft_deleted' => 1]], $this->filtersSent());
        $this->assertSame([$trashed->id], $results->pluck('id')->all());
    }

    public function test_soft_delete_filter_is_not_added_when_soft_delete_is_disabled()
    {
        $this->seedArticles();
        $this->http->push($this->searchResponse([]));

        Article::search('article')->get();

        $this->assertSame([], $this->filtersSent());
    }

    protected function seedArticles(): array
    {
        return $this->withoutSearchSync(function () {
            $live = Article::create(['title' => 'live article']);
            $trashed = Article::create(['title' => 'trashed article']);
            $trashed->delete();

            return [$live, $trashed];
        });
    }

    /**
     * The term filters of the last search request sent to Elasticsearch.
     */
    protected function filtersSent(): array
    {
        $must = $this->requestBody($this->http->lastRequest())['query']['bool']['must'] ?? [];

        return array_values(array_column($must, 'term'));
    }
}
