<?php

namespace Shabayek\Elastic\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client that records requests and replies with queued responses.
 *
 * The Elasticsearch client is final, so tests exercise the real client on top
 * of this transport instead of mocking it.
 */
class FakeHttpClient implements ClientInterface
{
    /**
     * @var \Psr\Http\Message\RequestInterface[]
     */
    public array $requests = [];

    /**
     * @var array<int, \Psr\Http\Message\ResponseInterface|callable>
     */
    protected array $responses = [];

    /**
     * Queue a JSON response.
     */
    public function push(array $body, int $status = 200): self
    {
        $this->responses[] = static::response($body, $status);

        return $this;
    }

    /**
     * Queue a response built from the request by the callback.
     *
     * @param  callable(\Psr\Http\Message\RequestInterface): \Psr\Http\Message\ResponseInterface  $callback
     */
    public function pushCallback(callable $callback): self
    {
        $this->responses[] = $callback;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $response = array_shift($this->responses) ?? static::response([]);

        return is_callable($response) ? $response($request) : $response;
    }

    public function lastRequest(): ?RequestInterface
    {
        return end($this->requests) ?: null;
    }

    /**
     * Build a response the Elasticsearch client accepts as coming from Elasticsearch.
     */
    public static function response(array $body, int $status = 200): ResponseInterface
    {
        return new Response($status, [
            'Content-Type' => 'application/json',
            'X-Elastic-Product' => 'Elasticsearch',
        ], json_encode((object) $body));
    }
}
