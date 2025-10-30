<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Tcp;

use Clue\React\Ndjson\Decoder;
use Clue\React\Ndjson\Encoder;
use Datto\JsonRpc\Client as JsonRpcClient;
use Datto\JsonRpc\Responses\ErrorResponse;
use Datto\JsonRpc\Responses\ResultResponse;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Promise\PromiseInterface;

/**
 * TCP-based JSON-RPC Client using NDJSON
 */
class TcpClient
{
    private JsonRpcClient $client;
    private ?ConnectionInterface $connection = null;
    private ?Decoder $decoder = null;
    private ?Encoder $encoder = null;
    private array $pendingRequests = [];
    private int $idCounter = 1;
    private ?PromiseInterface $connectingPromise = null;

    public function __construct(private string $uri, private ?Connector $connector = null)
    {
        $this->client = new JsonRpcClient();
        $this->connector = $connector ?? new Connector();
    }

    /**
     * Connect to the server
     *
     * @return PromiseInterface Promise that resolves when connected
     */
    public function connect(): PromiseInterface
    {
        // If already connected, return resolved promise
        if ($this->connection !== null) {
            return \React\Promise\resolve($this->connection);
        }

        // If already connecting, return the existing connection promise
        if ($this->connectingPromise !== null) {
            return $this->connectingPromise;
        }

        // Start new connection
        $this->connectingPromise = $this->connector->connect($this->uri)->then(
            function (ConnectionInterface $connection) {
                $this->connection = $connection;
                $this->decoder = new Decoder($connection, true);
                $this->encoder = new Encoder($connection);

                $this->decoder->on('data', function (array $data) {
                    $this->handleResponse($data);
                });

                $connection->on('close', function () {
                    $this->reset();
                });

                $connection->on('error', function (\Throwable $error) {
                    $this->reset();
                });

                // Clear connecting promise on success
                $this->connectingPromise = null;

                return $connection;
            },
            function (\Throwable $error) {
                // Clear connecting promise on failure
                $this->connectingPromise = null;
                throw $error;
            }
        );

        return $this->connectingPromise;
    }

    /**
     * Call a JSON-RPC method
     *
     * @param string $method Method name
     * @param array|null $arguments Method arguments
     * @return PromiseInterface Promise that resolves to the response result
     */
    public function call(string $method, ?array $arguments = null): PromiseInterface
    {
        return $this->connect()->then(function () use ($method, $arguments) {
            $id = $this->idCounter++;
            
            $this->client->query($id, $method, $arguments);
            $request = $this->client->preEncode();
            
            if ($request === null || !isset($request[0])) {
                return \React\Promise\reject(new \RuntimeException('Failed to create request'));
            }

            $deferred = new \React\Promise\Deferred();
            $this->pendingRequests[$id] = $deferred;

            $this->encoder->write($request[0]);

            return $deferred->promise();
        });
    }

    /**
     * Send a notification (no response expected)
     *
     * @param string $method Method name
     * @param array|null $arguments Method arguments
     * @return PromiseInterface Promise that resolves when notification is sent
     */
    public function notify(string $method, ?array $arguments = null): PromiseInterface
    {
        return $this->connect()->then(function () use ($method, $arguments) {
            $this->client->notify($method, $arguments);
            $request = $this->client->preEncode();
            
            if ($request === null || !isset($request[0])) {
                return \React\Promise\reject(new \RuntimeException('Failed to create request'));
            }

            $this->encoder->write($request[0]);
            return null;
        });
    }

    /**
     * Handle a response from the server
     */
    private function handleResponse(array $data): void
    {
        try {
            $responses = $this->client->postDecode($data);
            
            if (empty($responses)) {
                return;
            }

            $response = $responses[0];
            
            if (!($response instanceof \Datto\JsonRpc\Responses\Response)) {
                return;
            }

            $id = $response->getId();

            if (!isset($this->pendingRequests[$id])) {
                // Unknown request ID
                return;
            }

            $deferred = $this->pendingRequests[$id];
            unset($this->pendingRequests[$id]);

            if ($response instanceof ErrorResponse) {
                $deferred->reject(new \RuntimeException(
                    $response->getMessage(),
                    $response->getCode()
                ));
            } elseif ($response instanceof ResultResponse) {
                $deferred->resolve($response->getValue());
            }
        } catch (\Throwable $e) {
            // Invalid response format - ignore
        }
    }

    /**
     * Close the connection
     */
    public function close(): void
    {
        if ($this->connection !== null) {
            $this->connection->close();
            $this->reset();
        }
    }

    /**
     * Reset client state
     */
    private function reset(): void
    {
        $this->connection = null;
        $this->decoder = null;
        $this->encoder = null;
        $this->connectingPromise = null;
        $this->client->reset();
        
        // Reject all pending requests
        foreach ($this->pendingRequests as $deferred) {
            $deferred->reject(new \RuntimeException('Connection closed'));
        }
        $this->pendingRequests = [];
    }
}
