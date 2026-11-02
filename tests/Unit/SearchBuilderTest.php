<?php

namespace Shabayek\Elastic\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use Shabayek\Elastic\Builders\SearchBuilder;
use Shabayek\Elastic\Tests\EngineTestCase;
use Shabayek\Elastic\Tests\Fixtures\Post;

enum PostStatus: string
{
    case Published = 'published';
    case Draft = 'draft';
}

enum PostKind
{
    case Article;
}

class SearchBuilderTest extends EngineTestCase
{
    /**
     * Build the request for a builder with the given properties.
     */
    protected function request(string $query = '', array $properties = []): array
    {
        $builder = new Builder(new Post(), $query);

        foreach ($properties as $property => $value) {
            $builder->{$property} = $value;
        }

        return (new SearchBuilder($builder))->toArray();
    }

    /**
     * The bool query of a request built from the given "wheres".
     */
    protected function boolFor(array $wheres): array
    {
        return $this->request('', ['wheres' => $wheres])['body']['query']['bool'];
    }

    protected function row(string $field, string $operator, $value): array
    {
        return ['field' => $field, 'operator' => $operator, 'value' => $value];
    }

    public function test_full_text_scores_and_structured_constraints_filter()
    {
        $bool = $this->request('lamp', [
            'wheres' => ['status' => 'published'],
            'whereIns' => ['tag' => ['a', 'b']],
            'whereNotIns' => ['color' => ['red']],
        ])['body']['query']['bool'];

        $this->assertSame([['multi_match' => ['query' => 'lamp', 'fields' => ['title'], 'type' => 'phrase_prefix']]], $bool['must']);
        $this->assertSame([['term' => ['status' => 'published']], ['terms' => ['tag' => ['a', 'b']]]], $bool['filter']);
        $this->assertSame([['terms' => ['color' => ['red']]]], $bool['must_not']);
    }

    public function test_a_search_without_text_only_filters()
    {
        $bool = $this->boolFor(['status' => 'published']);

        $this->assertSame(['filter'], array_keys($bool));
    }

    public function test_a_search_without_text_or_constraints_has_no_query()
    {
        $this->assertArrayNotHasKey('query', $this->request()['body']);
    }

    public function test_scout_10_equality_wheres()
    {
        $this->assertSame(
            ['filter' => [['term' => ['status' => 'draft']], ['term' => ['views' => 3]]]],
            $this->boolFor(['status' => 'draft', 'views' => 3])
        );
    }

    public function test_scout_11_operators()
    {
        $this->assertSame([
            'filter' => [
                ['term' => ['status' => 'draft']],
                ['range' => ['views' => ['gt' => 10]]],
                ['range' => ['views' => ['gte' => 11]]],
                ['range' => ['views' => ['lt' => 100]]],
                ['range' => ['views' => ['lte' => 99]]],
            ],
            'must_not' => [
                ['term' => ['status' => 'archived']],
                ['term' => ['status' => 'spam']],
            ],
        ], $this->boolFor([
            $this->row('status', '=', 'draft'),
            $this->row('status', '!=', 'archived'),
            $this->row('status', '<>', 'spam'),
            $this->row('views', '>', 10),
            $this->row('views', '>=', 11),
            $this->row('views', '<', 100),
            $this->row('views', '<=', 99),
        ]));
    }

    public function test_scout_10_and_scout_11_equality_build_the_same_query()
    {
        $this->assertSame(
            $this->boolFor(['status' => 'draft', 'author_id' => 7]),
            $this->boolFor([$this->row('status', '=', 'draft'), $this->row('author_id', '=', 7)])
        );
    }

    public function test_equality_with_null_matches_missing_values()
    {
        $expected = ['must_not' => [['exists' => ['field' => 'deleted_by']]]];

        $this->assertSame($expected, $this->boolFor(['deleted_by' => null]));
        $this->assertSame($expected, $this->boolFor([$this->row('deleted_by', '=', null)]));
    }

    public function test_inequality_with_null_matches_present_values()
    {
        $this->assertSame(
            ['filter' => [['exists' => ['field' => 'deleted_by']]]],
            $this->boolFor([$this->row('deleted_by', '!=', null)])
        );
    }

    public function test_comparisons_with_null_are_rejected()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [>] operator cannot be used with a null value for [views].');

        $this->boolFor([$this->row('views', '>', null)]);
    }

    public function test_unsupported_operators_are_rejected()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [like] operator is not supported for [title]. Supported operators: =, !=, <>, >, >=, <, <=.');

        $this->boolFor([$this->row('title', 'like', '%lamp%')]);
    }

    public function test_enum_and_date_values_are_converted()
    {
        $date = new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('UTC'));

        $this->assertSame([
            'filter' => [
                ['term' => ['status' => 'published']],
                ['term' => ['kind' => 'Article']],
                ['range' => ['published_at' => ['gte' => '2026-01-02T03:04:05+00:00']]],
                ['terms' => ['status' => ['published', 'draft']]],
            ],
        ], $this->request('', [
            'wheres' => [
                $this->row('status', '=', PostStatus::Published),
                $this->row('kind', '=', PostKind::Article),
                $this->row('published_at', '>=', $date),
            ],
            'whereIns' => ['status' => [PostStatus::Published, PostStatus::Draft]],
        ])['body']['query']['bool']);
    }

    public function test_where_in_values_are_reindexed()
    {
        $bool = $this->request('', ['whereIns' => ['id' => [3 => 10, 7 => 20]]])['body']['query']['bool'];

        $this->assertSame([['terms' => ['id' => [10, 20]]]], $bool['filter']);
    }

    public function test_an_empty_where_in_matches_nothing()
    {
        $body = json_encode($this->request('', ['whereIns' => ['id' => []]])['body']['query']);

        $this->assertSame('{"bool":{"filter":[{"match_none":{}}]}}', $body);
    }

    public function test_an_empty_where_not_in_excludes_nothing()
    {
        $this->assertArrayNotHasKey('query', $this->request('', ['whereNotIns' => ['id' => []]])['body']);
    }

    public function test_null_in_where_in_values_is_rejected()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("use where('tag', null) to match missing values");

        $this->request('', ['whereIns' => ['tag' => ['a', null]]]);
    }

    public function test_null_in_where_not_in_values_is_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->request('', ['whereNotIns' => ['tag' => [null]]]);
    }

    public function test_constraints_reach_elasticsearch_through_the_installed_scout_version()
    {
        $this->http->push($this->searchResponse([]));

        Post::search('lamp')
            ->where('status', 'published')
            ->whereIn('tag', collect(['a', 'b']))
            ->whereNotIn('color', ['red', 'blue'])
            ->raw();

        $bool = $this->requestBody($this->http->lastRequest())['query']['bool'];
        $this->assertSame('lamp', $bool['must'][0]['multi_match']['query']);
        $this->assertSame([['term' => ['status' => 'published']], ['terms' => ['tag' => ['a', 'b']]]], $bool['filter']);
        $this->assertSame([['terms' => ['color' => ['red', 'blue']]]], $bool['must_not']);
    }
}
