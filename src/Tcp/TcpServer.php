<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Tcp;

use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use Datto\JsonRpc\Server as JsonRpcServer;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use ReactphpX\Rpc\Evaluator;
use ReactphpX\Rpc\AccessLogHandler;
use function React\Async\async;

/**
 * TCP-based JSON-RPC Server using NDJSON
 */
class TcpServer
{
    private JsonRpcServer $rpcServer;
    private SocketServer $socketServer;
    private array $connections = [];
    private ?AccessLogHandler $accessLog;

    public function __construct(
        Evaluator $evaluator,
        SocketServer $socketServer,
        ?AccessLogHandler $accessLog = null
    ) {
        $this->rpcServer = new JsonRpcServer($evaluator);
        $this->socketServer = $socketServer;
        $this->accessLog = $accessLog;
        
        $this->socketServer->on('connection', async(function (ConnectionInterface $connection) {
            $this->handleConnection($connection);
        }));
    }

    /**
     * Handle a new connection
     */
    private function handleConnection(ConnectionInterface $connection): void
    {
        $decoder = new Decoder($connection, true);
        $encoder = new Encoder($connection);

        $connectionId = spl_object_hash($connection);
        $this->connections[$connectionId] = [
            'connection' => $connection,
            'decoder' => $decoder,
            'encoder' => $encoder
        ];

        $decoder->on('data', function (array $data) use ($encoder, $connectionId) {
            $this->handleRequest($data, $encoder);
        });

        $connection->on('close', function () use ($connectionId) {
            unset($this->connections[$connectionId]);
        });

        $connection->on('error', function (\Throwable $error) use ($connectionId) {
            unset($this->connections[$connectionId]);
        });
    }

    /**
     * Handle a JSON-RPC request
     */
    private function handleRequest(array $data, Encoder $encoder): void
    {
        $startTime = microtime(true);
        $requestBody = json_encode($data);

        // Extract JSON-RPC info
        $rpcInfo = $this->accessLog ? $this->accessLog->extractRpcInfo($requestBody) : [];

        // Process JSON-RPC request
        try {
            $response = $this->rpcServer->rawReply($data);
        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;
            
            // Log error
            if ($this->accessLog) {
                $this->accessLog->log('REQUEST', array_merge([
                    'request_body' => $requestBody,
                    'duration' => $duration,
                    'error' => $e->getMessage(),
                ], $rpcInfo));
            }
            
            // Send error response
            $errorResponse = [
                'jsonrpc' => '2.0',
                'id' => $data['id'] ?? null,
                'error' => [
                    'code' => -32603,
                    'message' => 'Internal error',
                    'data' => $e->getMessage()
                ]
            ];
            $encoder->write($errorResponse);
            return;
        }
        
        $duration = microtime(true) - $startTime;

        // Log request
        if ($this->accessLog) {
            $context = array_merge([
                'request_body' => $requestBody,
                'duration' => $duration,
            ], $rpcInfo);

            if ($response !== null) {
                // Response
                $responseBody = json_encode($response);
                $context['response_body'] = $responseBody;
                $rpcResponseInfo = $this->accessLog->extractRpcResponseInfo($responseBody);
                $context = array_merge($context, $rpcResponseInfo);
                $this->accessLog->log('RESPONSE', $context);
            } else {
                // Notification
                $this->accessLog->log('NOTIFICATION', $context);
            }
        }

        // Only send response for queries (notifications return null)
        if ($response !== null) {
            $encoder->write($response);
        }
    }

    /**
     * Get the socket server instance
     */
    public function getSocketServer(): SocketServer
    {
        return $this->socketServer;
    }

    /**
     * Close the server
     */
    public function close(): void
    {
        $this->socketServer->close();
    }

    public function __destruct()
    {
        $this->close();
    }
}
