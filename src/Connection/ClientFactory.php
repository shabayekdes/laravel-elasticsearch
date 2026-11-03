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
     * The host may be a hostname, an IPv4 or IPv6 address, or a full URL. The
     * port is added to the URL authority when the host has none, so a path
     * prefix (for example "https://proxy.example.com/es") is kept after it.
     *
     * @param  array  $config
     * @return string
     */
    protected function hostFromParts(array $config): string
    {
        $host = (string) ($config['host'] ?? 'localhost');

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '['.$host.']';
        }

        if (! preg_match('#^https?://#i', $host)) {
            $host = ($config['scheme'] ?? 'http').'://'.$host;
        }

        $parts = parse_url($host);

        if (empty($config['port']) || $parts === false || ! isset($parts['host']) || isset($parts['port'])) {
            return $host;
        }

        return $parts['scheme'].'://'
            .(isset($parts['user']) ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '')
            .$parts['host'].':'.$config['port']
            .($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
