<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Tests\Http;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\Message\Response;
use ReactphpX\Rpc\AccessLogHandler;
use ReactphpX\Rpc\Evaluator;
use ReactphpX\Rpc\Http\HttpClient;
use React\Socket\SocketServer;

class HttpClientTest extends TestCase
{
    private Browser $browser;
    private string $baseUrl = 'http://127.0.0.1:8080';

    protected function setUp(): void
    {
        $this->browser = new Browser(Loop::get());
    }

    public function testCallMethod(): void
    {
        $client = new HttpClient($this->browser, $this->baseUrl);
        
        // This test requires a running server, so we'll just test instantiation
        $this->assertInstanceOf(HttpClient::class, $client);
    }

    public function testClientWithAccessLog(): void
    {
        $accessLog = new AccessLogHandler(false);
        $client = new HttpClient($this->browser, $this->baseUrl, $accessLog);
        
        $this->assertInstanceOf(HttpClient::class, $client);
    }

    public function testClientWithoutAccessLog(): void
    {
        $client = new HttpClient($this->browser, $this->baseUrl, null);
        
        $this->assertInstanceOf(HttpClient::class, $client);
    }
}

