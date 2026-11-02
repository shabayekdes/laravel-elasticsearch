<?php

namespace Shabayek\Elastic\Connection;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Psr\Http\Client\ClientInterface;

class ClientFactory
{
    /**
     * Build an Elasticsearch client from the package configuration.
     *
     * @param  array  $config
     * @param  \Psr\Http\Client\ClientInterface|null  $httpClient
     * @return \Elastic\Elasticsearch\Client
     */
    public function make(array $config, ?ClientInterface $httpClient = null): Client
    {
        $builder = ClientBuilder::create();

        if (! empty($config['cloud_id'])) {
            $builder->setElasticCloudId($config['cloud_id']);
        } else {
            $builder->setHosts($this->hosts($config));
        }

        if (! empty($config['api_key'])) {
            $builder->setApiKey($config['api_key']);
        } elseif (! empty($config['username']) && ! empty($config['password'])) {
            $builder->setBasicAuthentication($config['username'], $config['password']);
        }

        $builder->setSSLVerification((bool) ($config['ssl_verification'] ?? true));

        if (! empty($config['ca_bundle'])) {
            $builder->setCABundle($config['ca_bundle']);
        }

        if ($httpClient) {
            $builder->setHttpClient($httpClient);
        }

        return $builder->build();
    }

    /**
     * Resolve the list of node URLs.
     *
     * @param  array  $config
     * @return array
     */
    protected function hosts(array $config): array
    {
        $hosts = $config['hosts'] ?? null;

        if (is_string($hosts)) {
            $hosts = explode(',', $hosts);
        }

        $hosts = array_values(array_filter(array_map('trim', (array) $hosts)));

        if (empty($hosts)) {
            $hosts = [$this->hostFromParts($config)];
        }

        return $hosts;
    }

    /**
     * Build a single node URL from the scheme, host and port options.
     *
     * @param  array  $config
     * @return string
     */
    protected function hostFromParts(array $config): string
    {
        $host = $config['host'] ?? 'localhost';

        if (! preg_match('#^https?://#i', $host)) {
            $host = ($config['scheme'] ?? 'http').'://'.$host;
        }

        if (! empty($config['port']) && parse_url($host, PHP_URL_PORT) === null) {
            $host = rtrim($host, '/').':'.$config['port'];
        }

        return $host;
    }
}
