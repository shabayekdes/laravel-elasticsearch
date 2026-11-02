<?php

namespace Shabayek\Elastic\Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\TestCase;
use Shabayek\Elastic\Connection\ClientFactory;
use Shabayek\Elastic\Tests\Support\FakeHttpClient;

class ClientFactoryTest extends TestCase
{
    /**
     * Send a request through a client built from the given config and return it.
     */
    protected function sendWith(array $config, ?FakeHttpClient $http = null): \Psr\Http\Message\RequestInterface
    {
        $http ??= new FakeHttpClient();

        (new ClientFactory())->make($config, $http)->info();

        return $http->lastRequest();
    }

    public function test_it_builds_the_node_url_from_the_legacy_host_and_port_options()
    {
        $request = $this->sendWith(['host' => 'localhost', 'port' => '9200']);

        $this->assertSame('http://localhost:9200/', (string) $request->getUri());
    }

    public function test_it_uses_the_configured_scheme()
    {
        $request = $this->sendWith(['scheme' => 'https', 'host' => 'es.internal', 'port' => 9243]);

        $this->assertSame('https://es.internal:9243/', (string) $request->getUri());
    }

    public function test_it_does_not_duplicate_a_scheme_or_port_already_present_in_the_host()
    {
        $request = $this->sendWith(['scheme' => 'http', 'host' => 'https://es.internal:9443', 'port' => 9200]);

        $this->assertSame('https://es.internal:9443/', (string) $request->getUri());
    }

    public function test_it_adds_the_port_before_the_path_prefix_of_a_host_url()
    {
        $request = $this->sendWith(['host' => 'https://proxy.example.com/es', 'port' => '9200']);

        $this->assertSame('https://proxy.example.com:9200/es/', (string) $request->getUri());
    }

    public function test_it_adds_the_port_to_a_host_url_without_a_path()
    {
        $request = $this->sendWith(['host' => 'http://localhost', 'port' => '9200']);

        $this->assertSame('http://localhost:9200/', (string) $request->getUri());
    }

    public function test_a_trailing_slash_in_the_host_url_does_not_change_the_request_path()
    {
        $request = $this->sendWith(['host' => 'https://es.internal/', 'port' => 9200]);

        $this->assertSame('https://es.internal:9200/', (string) $request->getUri());
    }

    public function test_it_keeps_the_path_prefix_of_a_host_url_that_already_has_a_port()
    {
        $request = $this->sendWith(['host' => 'https://proxy.example.com:8443/es', 'port' => 9200]);

        $this->assertSame('https://proxy.example.com:8443/es/', (string) $request->getUri());
    }

    public function test_it_keeps_credentials_in_a_host_url()
    {
        $request = $this->sendWith(['host' => 'https://elastic:s3cret@proxy.example.com/es', 'port' => 9200]);

        $this->assertSame('https://elastic:s3cret@proxy.example.com:9200/es/', (string) $request->getUri());
    }

    public function test_it_builds_the_node_url_from_an_ipv4_address()
    {
        $request = $this->sendWith(['host' => '10.0.0.5', 'port' => 9200]);

        $this->assertSame('http://10.0.0.5:9200/', (string) $request->getUri());
    }

    public function test_it_brackets_a_raw_ipv6_address()
    {
        $request = $this->sendWith(['scheme' => 'https', 'host' => '::1', 'port' => 9200]);

        $this->assertSame('https://[::1]:9200/', (string) $request->getUri());
    }

    public function test_it_accepts_a_bracketed_ipv6_address_with_or_without_a_port()
    {
        $this->assertSame('http://[2001:db8::1]:9200/', (string) $this->sendWith(['host' => '[2001:db8::1]', 'port' => 9200])->getUri());
        $this->assertSame('http://[2001:db8::1]:9300/', (string) $this->sendWith(['host' => 'http://[2001:db8::1]:9300', 'port' => 9200])->getUri());
    }

    public function test_it_leaves_the_host_without_a_port_when_none_is_configured()
    {
        $request = $this->sendWith(['host' => 'https://proxy.example.com/es', 'port' => null]);

        $this->assertSame('https://proxy.example.com/es/', (string) $request->getUri());
    }

    public function test_it_accepts_a_comma_separated_list_of_hosts()
    {
        $http = new FakeHttpClient();
        $client = (new ClientFactory())->make([
            'hosts' => 'http://es01:9200, http://es02:9200',
            'host' => 'ignored',
        ], $http);

        $client->info();
        $client->info();

        $hosts = array_map(fn ($request) => $request->getUri()->getHost(), $http->requests);
        sort($hosts);

        $this->assertSame(['es01', 'es02'], $hosts);
    }

    public function test_it_accepts_an_array_of_hosts()
    {
        $request = $this->sendWith(['hosts' => ['https://es01:9200']]);

        $this->assertSame('https://es01:9200/', (string) $request->getUri());
    }

    public function test_empty_hosts_fall_back_to_the_host_option()
    {
        $request = $this->sendWith(['hosts' => '', 'host' => 'search', 'port' => 9200]);

        $this->assertSame('http://search:9200/', (string) $request->getUri());
    }

    public function test_it_sends_no_credentials_when_none_are_configured()
    {
        $request = $this->sendWith(['host' => 'localhost', 'port' => 9200, 'username' => null, 'password' => null]);

        $this->assertFalse($request->hasHeader('Authorization'));
        $this->assertSame('', $request->getUri()->getUserInfo());
    }

    public function test_it_requires_both_username_and_password_for_basic_authentication()
    {
        $request = $this->sendWith(['host' => 'localhost', 'username' => 'elastic', 'password' => null]);

        $this->assertFalse($request->hasHeader('Authorization'));
        $this->assertSame('', $request->getUri()->getUserInfo());
    }

    public function test_it_uses_basic_authentication()
    {
        $request = $this->sendWith(['host' => 'localhost', 'username' => 'elastic', 'password' => 's3cret']);

        $this->assertSame('elastic:s3cret', $request->getUri()->getUserInfo());
    }

    public function test_an_api_key_takes_precedence_over_basic_authentication()
    {
        $request = $this->sendWith([
            'host' => 'localhost',
            'username' => 'elastic',
            'password' => 's3cret',
            'api_key' => 'base64-key',
        ]);

        $this->assertSame('ApiKey base64-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('', $request->getUri()->getUserInfo());
    }

    public function test_it_connects_to_elastic_cloud_using_the_cloud_id()
    {
        $cloudId = 'my-deployment:'.base64_encode('us-east-1.aws.found.io$es-uuid$kibana-uuid');

        $request = $this->sendWith(['cloud_id' => $cloudId, 'host' => 'ignored']);

        $this->assertSame('https', $request->getUri()->getScheme());
        $this->assertSame('es-uuid.us-east-1.aws.found.io', $request->getUri()->getHost());
    }

    public function test_ssl_verification_can_be_disabled()
    {
        $client = (new ClientFactory())->make(['host' => 'localhost', 'ssl_verification' => false], new GuzzleClient());

        $this->assertFalse($client->getTransport()->getClient()->getConfig('verify'));
    }

    public function test_a_ca_bundle_is_used_to_verify_the_server_certificate()
    {
        $client = (new ClientFactory())->make(['host' => 'localhost', 'ca_bundle' => '/etc/ssl/es-ca.crt'], new GuzzleClient());

        $this->assertSame('/etc/ssl/es-ca.crt', $client->getTransport()->getClient()->getConfig('verify'));
    }
}
