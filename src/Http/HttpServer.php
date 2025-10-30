<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Http;

use Datto\JsonRpc\Server as JsonRpcServer;
use React\Http\HttpServer as ReactHttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use ReactphpX\Rpc\Evaluator;
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

    public function __construct(Evaluator $evaluator, SocketServer $socketServer)
    {
        $this->rpcServer = new JsonRpcServer($evaluator);
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
        // Only accept POST requests
        if ($request->getMethod() !== 'POST') {
            return new Response(
                405,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Method not allowed'])
            );
        }

        // Get request body
        $body = (string)$request->getBody();

        if (empty($body)) {
            return new Response(
                400,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Empty request body'])
            );
        }

        // Process JSON-RPC request
        $response = $this->rpcServer->reply($body);

        // Notifications return 204 No Content
        if ($response === null) {
            return new Response(204);
        }

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            $response
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
