<?php

namespace Shabayek\Elastic\Tests\Integration;

use Elastic\Elasticsearch\Client;

class ServerCompatibilityTest extends IntegrationTestCase
{
    public function test_the_server_major_version_matches_the_client_major_version()
    {
        $clientMajor = (int) explode('.', Client::VERSION)[0];

        $this->assertSame(
            $clientMajor,
            $this->serverMajorVersion(),
            sprintf('Client %s must be paired with an Elasticsearch %d server.', Client::VERSION, $clientMajor)
        );
    }
}
