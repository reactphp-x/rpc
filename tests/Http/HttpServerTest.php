<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Tests\Http;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use ReactphpX\Rpc\AccessLogHandler;
use ReactphpX\Rpc\Evaluator;
use ReactphpX\Rpc\Http\HttpServer;

class HttpServerTest extends TestCase
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
        
        $server = new HttpServer($evaluator, $socket);
        
        $this->assertInstanceOf(HttpServer::class, $server);
        
        $socket->close();
    }

    public function testServerWithAccessLog(): void
    {
        $loop = Loop::get();
        $socket = new SocketServer('127.0.0.1:0', [], $loop);
        $evaluator = $this->createMockEvaluator();
        $accessLog = new AccessLogHandler(false);
        
        $server = new HttpServer($evaluator, $socket, $accessLog);
        
        $this->assertInstanceOf(HttpServer::class, $server);
        
        $socket->close();
    }

    public function testServerWithoutAccessLog(): void
    {
        $loop = Loop::get();
        $socket = new SocketServer('127.0.0.1:0', [], $loop);
        $evaluator = $this->createMockEvaluator();
        
        $server = new HttpServer($evaluator, $socket, null);
        
        $this->assertInstanceOf(HttpServer::class, $server);
        
        $socket->close();
    }

    public function testGetHttpServer(): void
    {
        $loop = Loop::get();
        $socket = new SocketServer('127.0.0.1:0', [], $loop);
        $evaluator = $this->createMockEvaluator();
        
        $server = new HttpServer($evaluator, $socket);
        
        $this->assertNotNull($server->getHttpServer());
        
        $socket->close();
    }
}

