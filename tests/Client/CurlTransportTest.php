<?php

namespace Client;

use FQL\Client\CurlTransport;
use FQL\Client\Exception\ClientException;
use PHPUnit\Framework\TestCase;

class CurlTransportTest extends TestCase
{
    // -------------------------------------------------------
    // Unit tests (no network required)
    // -------------------------------------------------------

    public function testConstructorSetsDefaults(): void
    {
        $transport = new CurlTransport();
        $this->assertInstanceOf(CurlTransport::class, $transport);
    }

    public function testConstructorAcceptsCustomTimeouts(): void
    {
        $transport = new CurlTransport(60, 20);
        $this->assertInstanceOf(CurlTransport::class, $transport);
    }

    public function testRequestFailureThrowsClientException(): void
    {
        $transport = new CurlTransport(1, 1);

        $this->expectException(ClientException::class);
        $transport->request('GET', 'http://127.0.0.1:1');
    }

    public function testRequestInvalidUrlThrowsClientException(): void
    {
        $transport = new CurlTransport(1, 1);

        $this->expectException(ClientException::class);
        $transport->request('GET', 'http://192.0.2.1:1');
    }

    // -------------------------------------------------------
    // Integration tests (require network access)
    // -------------------------------------------------------

    /** @group network */
    public function testRequestReturnsValidResponse(): void
    {
        $transport = new CurlTransport(15, 10);

        $response = $transport->request('GET', 'https://example.com');

        $this->assertGreaterThan(0, $response->getStatusCode());
        $this->assertNotEmpty($response->getBody());
    }
}
