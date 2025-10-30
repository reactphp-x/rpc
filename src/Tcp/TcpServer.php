<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Tcp;

use Clue\React\Ndjson\Decoder;
use Clue\React\Ndjson\Encoder;
use Datto\JsonRpc\Server as JsonRpcServer;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use ReactphpX\Rpc\Evaluator;
use function React\Async\async;

/**
 * TCP-based JSON-RPC Server using NDJSON
 */
class TcpServer
{
    private JsonRpcServer $rpcServer;
    private SocketServer $socketServer;
    private array $connections = [];

    public function __construct(Evaluator $evaluator, SocketServer $socketServer)
    {
        $this->rpcServer = new JsonRpcServer($evaluator);
        $this->socketServer = $socketServer;
        
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
        $response = $this->rpcServer->rawReply($data);
        
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
}
