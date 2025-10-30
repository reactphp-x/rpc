<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Tests\Tcp;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Socket\Connector;
use ReactphpX\Rpc\AccessLogHandler;
use ReactphpX\Rpc\Tcp\TcpClient;

class TcpClientTest extends TestCase
{
    public function testClientCreation(): void
    {
        $loop = Loop::get();
        $connector = new Connector($loop);
        
        $client = new TcpClient('127.0.0.1:8081', $connector);
        
        $this->assertInstanceOf(TcpClient::class, $client);
    }

    public function testClientWithAccessLog(): void
    {
        $loop = Loop::get();
        $connector = new Connector($loop);
        $accessLog = new AccessLogHandler(false);
        
        $client = new TcpClient('127.0.0.1:8081', $connector, $accessLog);
        
        $this->assertInstanceOf(TcpClient::class, $client);
    }

    public function testClientWithoutConnector(): void
    {
        $client = new TcpClient('127.0.0.1:8081');
        
        $this->assertInstanceOf(TcpClient::class, $client);
    }

    public function testClientWithoutAccessLog(): void
    {
        $loop = Loop::get();
        $connector = new Connector($loop);
        
        $client = new TcpClient('127.0.0.1:8081', $connector, null);
        
        $this->assertInstanceOf(TcpClient::class, $client);
    }
}

