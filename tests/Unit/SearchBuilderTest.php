<?php

namespace Shabayek\Elastic\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use Shabayek\Elastic\Builders\SearchBuilder;
use Shabayek\Elastic\Tests\EngineTestCase;
use Shabayek\Elastic\Tests\Fixtures\Comment;
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

    /**
     * Documents with an existing matching value, an existing other value, a
     * missing field and a null field.
     */
    protected function statusDocuments(): array
    {
        return [
            'archived' => ['status' => 'archived'],
            'draft' => ['status' => 'draft'],
            'missing' => [],
            'null' => ['status' => null],
        ];
    }

    /**
     * The keys of the documents a bool filter query matches.
     *
     * Evaluates term, terms and exists clauses the way Elasticsearch does: a
     * null field is not indexed, so like a missing field it does not exist
     * and matches no term.
     */
    protected function matching(array $bool, array $documents): array
    {
        $matches = function (array $clause, array $document) {
            $type = array_key_first($clause);
            $field = array_key_first($clause[$type]);

            return match ($type) {
                'exists' => isset($document[$clause[$type]['field']]),
                'term' => isset($document[$field]) && $document[$field] === $clause[$type][$field],
                'terms' => isset($document[$field]) && in_array($document[$field], $clause[$type][$field], true),
            };
        };

        return array_keys(array_filter($documents, function ($document) use ($bool, $matches) {
            foreach ($bool['filter'] ?? [] as $clause) {
                if (! $matches($clause, $document)) {
                    return false;
                }
            }

            foreach ($bool['must_not'] ?? [] as $clause) {
                if ($matches($clause, $document)) {
                    return false;
                }
            }

            return true;
        }));
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

    public function test_equality_matches_only_existing_equal_values()
    {
        $this->assertSame(
            ['archived'],
            $this->matching($this->boolFor([$this->row('status', '=', 'archived')]), $this->statusDocuments())
        );
    }

    public function test_inequality_matches_other_values_and_missing_or_null_fields()
    {
        foreach (['!=', '<>'] as $operator) {
            $this->assertSame(
                ['draft', 'missing', 'null'],
                $this->matching($this->boolFor([$this->row('status', $operator, 'archived')]), $this->statusDocuments()),
                "Operator [{$operator}]"
            );
        }
    }

    public function test_where_in_matches_only_existing_listed_values()
    {
        $bool = $this->request('', ['whereIns' => ['status' => ['archived']]])['body']['query']['bool'];

        $this->assertSame(['archived'], $this->matching($bool, $this->statusDocuments()));
    }

    public function test_where_not_in_matches_other_values_and_missing_or_null_fields()
    {
        $bool = $this->request('', ['whereNotIns' => ['status' => ['archived']]])['body']['query']['bool'];

        $this->assertSame(['draft', 'missing', 'null'], $this->matching($bool, $this->statusDocuments()));
    }

    public function test_null_equality_and_inequality_split_on_whether_the_field_exists()
    {
        $documents = $this->statusDocuments();

        $this->assertSame(['missing', 'null'], $this->matching($this->boolFor([$this->row('status', '=', null)]), $documents));
        $this->assertSame(['archived', 'draft'], $this->matching($this->boolFor([$this->row('status', '!=', null)]), $documents));
    }

    public function test_inequality_and_not_null_exclude_missing_or_null_fields()
    {
        $bool = $this->boolFor([$this->row('status', '!=', 'archived'), $this->row('status', '!=', null)]);

        $this->assertSame(['draft'], $this->matching($bool, $this->statusDocuments()));
    }

    public function test_order_by_sorts_in_the_given_order()
    {
        $this->http->push($this->searchResponse([]));

        Post::search('lamp')->orderBy('published_at', 'desc')->orderBy('title')->orderBy('views', 'ASC')->raw();

        $this->assertSame([
            ['published_at' => ['order' => 'desc']],
            ['title' => ['order' => 'asc']],
            ['views' => ['order' => 'asc']],
        ], $this->requestBody($this->http->lastRequest())['sort']);
    }

    public function test_results_are_sorted_by_relevance_without_order_by()
    {
        $this->assertArrayNotHasKey('sort', $this->request('lamp')['body']);
    }

    public function test_take_limits_the_number_of_hits()
    {
        $this->http->push($this->searchResponse([]))->push($this->searchResponse([]));

        Post::search('lamp')->take(25)->raw();
        $this->assertSame(25, $this->requestBody($this->http->lastRequest())['size']);

        Post::search('lamp')->raw();
        $this->assertSame(5000, $this->requestBody($this->http->lastRequest())['size']);
    }

    public function test_take_limits_get_and_keys()
    {
        $this->http->push($this->searchResponse([]))->push($this->searchResponse([]));

        Post::search('lamp')->take(3)->get();
        Post::search('lamp')->take(4)->keys();

        $this->assertSame([3, 4], array_map(fn ($request) => $this->requestBody($request)['size'], $this->http->requests));
    }

    public function test_paginate_uses_the_page_size_like_other_scout_engines()
    {
        $this->http->push($this->searchResponse([]));

        Post::search('lamp')->take(3)->paginateRaw(20, 'page', 2);

        $body = $this->requestBody($this->http->lastRequest());
        $this->assertSame(20, $body['from']);
        $this->assertSame(20, $body['size']);
    }

    public function test_within_searches_a_custom_index()
    {
        $this->http->push($this->searchResponse([]));

        Post::search('lamp')->within('posts_archive')->raw();

        $this->assertSame(['POST /posts_archive/_search'], $this->sentRequests());
    }

    public function test_full_text_searches_the_fields_from_searchable_fields()
    {
        $this->assertSame(
            ['multi_match' => ['query' => 'lamp', 'fields' => ['title'], 'type' => 'phrase_prefix']],
            $this->request('lamp')['body']['query']['bool']['must'][0]
        );
    }

    public function test_full_text_falls_back_to_the_index_default_fields_without_searchable_fields()
    {
        $request = (new SearchBuilder(new Builder(new Comment(), 'lamp')))->toArray();

        $this->assertSame(
            ['multi_match' => ['query' => 'lamp', 'type' => 'phrase_prefix', 'lenient' => true]],
            $request['body']['query']['bool']['must'][0]
        );
    }

    public function test_empty_searchable_fields_fall_back_to_the_index_default_fields()
    {
        $model = new class extends Post
        {
            public function searchableFields()
            {
                return [];
            }
        };

        $request = (new SearchBuilder(new Builder($model, 'lamp')))->toArray();

        $this->assertArrayNotHasKey('fields', $request['body']['query']['bool']['must'][0]['multi_match']);
        $this->assertTrue($request['body']['query']['bool']['must'][0]['multi_match']['lenient']);
    }

    public function test_searching_without_searchable_fields_through_scout()
    {
        $this->http->push($this->searchResponse([]));

        Comment::search('great')->where('approved', true)->raw();

        $bool = $this->requestBody($this->http->lastRequest())['query']['bool'];
        $this->assertSame(['query' => 'great', 'type' => 'phrase_prefix', 'lenient' => true], $bool['must'][0]['multi_match']);
        $this->assertSame([['term' => ['approved' => true]]], $bool['filter']);
    }

    public function test_a_zero_query_is_searched()
    {
        $this->assertSame('0', $this->request('0')['body']['query']['bool']['must'][0]['multi_match']['query']);
    }

    public function test_an_empty_query_matches_everything()
    {
        $this->assertArrayNotHasKey('query', $this->request('')['body']);
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
