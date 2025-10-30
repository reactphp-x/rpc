<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Http;

use Datto\JsonRpc\Server as JsonRpcServer;
use React\Http\HttpServer as ReactHttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use ReactphpX\Rpc\Evaluator;
use ReactphpX\Rpc\AccessLogHandler;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\Promise\Deferred;
use function React\Async\async;

/**
 * HTTP-based JSON-RPC Server
 */
class HttpServer
{
    private JsonRpcServer $rpcServer;
    private ReactHttpServer $httpServer;
    private ?AccessLogHandler $accessLog;

    public function __construct(
        Evaluator $evaluator,
        SocketServer $socketServer,
        ?AccessLogHandler $accessLog = null
    ) {
        $this->rpcServer = new JsonRpcServer($evaluator);
        $this->accessLog = $accessLog;
        $this->httpServer = new ReactHttpServer(
            async(function (ServerRequestInterface $request, callable $next) {
                return $next($request);
            }),
            $this->createRequestHandler()
        );
        $this->httpServer->listen($socketServer);
    }

    /**
     * Create HTTP request handler
     */
    private function createRequestHandler(): callable
    {
        return function (ServerRequestInterface $request): PromiseInterface {
            return \React\Promise\resolve($this->handleRequest($request));
        };
    }

    /**
     * Handle HTTP request
     */
    private function handleRequest(ServerRequestInterface $request): Response
    {
        $startTime = microtime(true);
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $uri = (string)$request->getUri();
        $method = $request->getMethod();

        // Only accept POST requests
        if ($method !== 'POST') {
            $response = new Response(
                405,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Method not allowed'])
            );
            
            if ($this->accessLog) {
                $this->accessLog->log('REQUEST', [
                    'remote' => $remote,
                    'method' => $method,
                    'uri' => $uri,
                    'status' => 405,
                    'duration' => microtime(true) - $startTime,
                ]);
            }
            
            return $response;
        }

        // Get request body
        $body = (string)$request->getBody();

        if (empty($body)) {
            $response = new Response(
                400,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Empty request body'])
            );
            
            if ($this->accessLog) {
                $this->accessLog->log('REQUEST', [
                    'remote' => $remote,
                    'method' => $method,
                    'uri' => $uri,
                    'status' => 400,
                    'duration' => microtime(true) - $startTime,
                    'error' => 'Empty request body',
                ]);
            }
            
            return $response;
        }

        // Extract JSON-RPC info
        $rpcInfo = $this->accessLog ? $this->accessLog->extractRpcInfo($body) : [];

        // Process JSON-RPC request
        try {
            $responseBody = $this->rpcServer->reply($body);
        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;
            
            // Log error
            if ($this->accessLog) {
                $this->accessLog->log('REQUEST', array_merge([
                    'remote' => $remote,
                    'method' => $method,
                    'uri' => $uri,
                    'request_body' => $body,
                    'status' => 500,
                    'duration' => $duration,
                    'error' => $e->getMessage(),
                ], $rpcInfo));
            }
            
            // Return error response
            return new Response(
                500,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => [
                        'code' => -32603,
                        'message' => 'Internal error',
                        'data' => $e->getMessage()
                    ]
                ])
            );
        }
        
        $duration = microtime(true) - $startTime;

        // Log request
        if ($this->accessLog) {
            $context = array_merge([
                'remote' => $remote,
                'method' => $method,
                'uri' => $uri,
                'request_body' => $body,
                'duration' => $duration,
            ], $rpcInfo);

            if ($responseBody === null) {
                // Notification
                $context['status'] = 204;
                $this->accessLog->log('NOTIFICATION', $context);
            } else {
                // Response
                $context['status'] = 200;
                $context['response_body'] = $responseBody;
                $rpcResponseInfo = $this->accessLog->extractRpcResponseInfo($responseBody);
                $context = array_merge($context, $rpcResponseInfo);
                $this->accessLog->log('RESPONSE', $context);
            }
        }

        // Notifications return 204 No Content
        if ($responseBody === null) {
            return new Response(204);
        }

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            $responseBody
        );
    }

    /**
     * Get the React HTTP server instance
     */
    public function getHttpServer(): ReactHttpServer
    {
        return $this->httpServer;
    }
}
