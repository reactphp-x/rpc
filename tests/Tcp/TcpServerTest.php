<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Tests\Tcp;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Socket\Connector;
use React\Socket\SocketServer;
use ReactphpX\Rpc\AccessLogHandler;
use ReactphpX\Rpc\Evaluator;
use ReactphpX\Rpc\Tcp\TcpClient;
use ReactphpX\Rpc\Tcp\TcpServer;

class TcpServerTest extends TestCase
{
    private function createMockEvaluator(): Evaluator
    {
        return new class implements Evaluator {
            public function evaluate($method, $arguments)
            {
                return match ($method) {
                    'test' => 'success',
                    'add' => array_sum($arguments ?? []),
                    default => throw new \RuntimeException("Method '{$method}' not found", -32601),
                };
            }
        };
    }

    public function testServerCreation(): void
    {
        $loop = Loop::get();
        $socket = new SocketServer('127.0.0.1:0', [], $loop);
        $evaluator = $this->createMockEvaluator();
        
        $server = new TcpServer($evaluator, $socket);
        
        $this->assertInstanceOf(TcpServer::class, $server);
        
        $socket->close();
    }

    public function testServerWithAccessLog(): void
    {
        $loop = Loop::get();
        $socket = new SocketServer('127.0.0.1:0', [], $loop);
        $evaluator = $this->createMockEvaluator();
        $accessLog = new AccessLogHandler(false);
        
        $server = new TcpServer($evaluator, $socket, $accessLog);
        
        $this->assertInstanceOf(TcpServer::class, $server);
        
        $socket->close();
    }

    public function testServerWithoutAccessLog(): void
    {
        $loop = Loop::get();
        $socket = new SocketServer('127.0.0.1:0', [], $loop);
        $evaluator = $this->createMockEvaluator();
        
        $server = new TcpServer($evaluator, $socket, null);
        
        $this->assertInstanceOf(TcpServer::class, $server);
        
        $socket->close();
    }

    public function testCloseServer(): void
    {
        $loop = Loop::get();
        $socket = new SocketServer('127.0.0.1:0', [], $loop);
        $evaluator = $this->createMockEvaluator();
        
        $server = new TcpServer($evaluator, $socket);
        $server->close();
        
        // Server should be closed (no exception thrown)
        $this->assertInstanceOf(TcpServer::class, $server);
    }

    public function testGetSocketServer(): void
    {
        $loop = Loop::get();
        $socket = new SocketServer('127.0.0.1:0', [], $loop);
        $evaluator = $this->createMockEvaluator();
        
        $server = new TcpServer($evaluator, $socket);
        
        $this->assertSame($socket, $server->getSocketServer());
        
        $socket->close();
    }
}

