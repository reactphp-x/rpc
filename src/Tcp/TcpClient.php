<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Tcp;

use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use Datto\JsonRpc\Client as JsonRpcClient;
use Datto\JsonRpc\Responses\ErrorResponse;
use Datto\JsonRpc\Responses\ResultResponse;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Promise\PromiseInterface;
use ReactphpX\Rpc\AccessLogHandler;

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
    private array $batchStates = [];
    private int $idCounter = 1;
    private int $batchIdCounter = 1;
    private ?PromiseInterface $connectingPromise = null;
    private ?AccessLogHandler $accessLog;

    public function __construct(private string $uri, private ?Connector $connector = null, ?AccessLogHandler $accessLog = null)
    {
        $this->client = new JsonRpcClient();
        $this->connector = $connector ?? new Connector();
        $this->accessLog = $accessLog;
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
            $startTime = microtime(true);
            $id = $this->idCounter++;
            
            $this->client->query($id, $method, $arguments);
            $request = $this->client->preEncode();
            
            if ($request === null) {
                return \React\Promise\reject(new \RuntimeException('Failed to create request'));
            }

            // preEncode returns single request object (associative array) for one request
            $requestBody = json_encode($request);
            
            // Extract JSON-RPC info
            $rpcInfo = $this->accessLog ? $this->accessLog->extractRpcInfo($requestBody) : [];

            $deferred = new \React\Promise\Deferred();
            $this->pendingRequests[$id] = [
                'deferred' => $deferred,
                'startTime' => $startTime,
                'rpcInfo' => $rpcInfo,
                'method' => $method,
                'requestBody' => $requestBody,
            ];

            $this->encoder->write($request);

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
            $startTime = microtime(true);
            $this->client->notify($method, $arguments);
            $request = $this->client->preEncode();
            
            if ($request === null) {
                return \React\Promise\reject(new \RuntimeException('Failed to create request'));
            }

            // preEncode returns single request object (associative array) for one request
            $requestBody = json_encode($request);
            
            // Extract JSON-RPC info
            $rpcInfo = $this->accessLog ? $this->accessLog->extractRpcInfo($requestBody) : [];

            // Log notification
            if ($this->accessLog) {
                $duration = microtime(true) - $startTime;
                $this->accessLog->log('NOTIFICATION', array_merge([
                    'uri' => $this->uri,
                    'request_body' => $requestBody,
                    'duration' => $duration,
                    'rpc_method' => $method,
                ], $rpcInfo));
            }

            $this->encoder->write($request);
            return null;
        });
    }

    /**
     * Call multiple methods in batch
     *
     * For TCP transport, each request is sent as a separate NDJSON line.
     * Responses are collected and returned as an array.
     *
     * @param array $calls Array of [method, arguments, id?] pairs
     * @param float $timeout Timeout in seconds (default: 5.0)
     * @return PromiseInterface Promise that resolves to array of Response objects
     */
    public function batch(array $calls, float $timeout = 5.0): PromiseInterface
    {
        return $this->connect()->then(function () use ($calls, $timeout) {
            $startTime = microtime(true);
            $requestIds = [];
            $batchInfo = [];
            $batchId = $this->batchIdCounter++;
            
            // Send all requests
            foreach ($calls as $index => $call) {
                $method = $call[0];
                $arguments = $call[1] ?? null;
                
                // Determine ID
                if (isset($call[2])) {
                    $id = $call[2];
                } else {
                    $id = $this->idCounter++;
                }
                
                $requestIds[] = $id;
                
                $this->client->query($id, $method, $arguments);
                $request = $this->client->preEncode();
                
                if ($request === null) {
                    return \React\Promise\reject(new \RuntimeException('Failed to create request for batch call ' . $index));
                }
                
                $requestBody = json_encode($request);
                $rpcInfo = $this->accessLog ? $this->accessLog->extractRpcInfo($requestBody) : [];
                
                $batchInfo[$id] = [
                    'index' => $index,
                    'method' => $method,
                    'requestBody' => $requestBody,
                    'rpcInfo' => $rpcInfo,
                    'startTime' => microtime(true),
                ];
                
                $this->encoder->write($request);
            }
            
            // Create deferred promise for batch
            $batchDeferred = new \React\Promise\Deferred();
            $expectedCount = count($requestIds);
            
            // Initialize batch state
            $this->batchStates[$batchId] = [
                'deferred' => $batchDeferred,
                'responses' => [],
                'responseCount' => 0,
                'expectedCount' => $expectedCount,
                'requestIds' => $requestIds,
            ];
            
            // Create individual deferred promises for each request
            foreach ($requestIds as $id) {
                $individualDeferred = new \React\Promise\Deferred();
                $this->pendingRequests[$id] = [
                    'deferred' => $individualDeferred,
                    'startTime' => $batchInfo[$id]['startTime'],
                    'rpcInfo' => $batchInfo[$id]['rpcInfo'],
                    'method' => $batchInfo[$id]['method'],
                    'requestBody' => $batchInfo[$id]['requestBody'],
                    'batchId' => $batchId,
                    'batchIndex' => $batchInfo[$id]['index'],
                ];
            }
            
            // Set timeout for batch
            $timeoutTimer = null;
            if ($timeout > 0) {
                $timeoutTimer = \React\EventLoop\Loop::get()->addTimer($timeout, function () use ($batchDeferred, $batchId, $timeout) {
                    if (isset($this->batchStates[$batchId])) {
                        unset($this->batchStates[$batchId]);
                        $batchDeferred->reject(new \RuntimeException('Batch request timeout after ' . $timeout . ' seconds'));
                    }
                });
            }
            
            // Clean up timeout when batch completes
            $batchPromise = $batchDeferred->promise()->finally(function () use ($timeoutTimer, $batchId) {
                if ($timeoutTimer !== null) {
                    \React\EventLoop\Loop::get()->cancelTimer($timeoutTimer);
                }
                if (isset($this->batchStates[$batchId])) {
                    unset($this->batchStates[$batchId]);
                }
            });
            
            return $batchPromise;
        });
    }

    /**
     * Handle a response from the server
     */
    private function handleResponse(array $data): void
    {
        try {
            $responseBody = json_encode($data);

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

            $requestInfo = $this->pendingRequests[$id];
            unset($this->pendingRequests[$id]);

            $duration = microtime(true) - $requestInfo['startTime'];

            // Check if this is part of a batch request
            $isBatch = isset($requestInfo['batchId']);
            
            if ($isBatch) {
                // Batch request handling
                $batchId = $requestInfo['batchId'];
                $batchIndex = $requestInfo['batchIndex'];
                
                if (!isset($this->batchStates[$batchId])) {
                    // Batch state was cleaned up (timeout or error)
                    return;
                }
                
                $batchState = &$this->batchStates[$batchId];
                
                // Store response at correct index
                $batchState['responses'][$batchIndex] = $response;
                $batchState['responseCount']++;
                
                // Log response
                if ($this->accessLog) {
                    $rpcResponseInfo = $this->accessLog->extractRpcResponseInfo($responseBody);
                    $this->accessLog->log('RESPONSE', array_merge([
                        'uri' => $this->uri,
                        'request_body' => $requestInfo['requestBody'],
                        'response_body' => $responseBody,
                        'duration' => $duration,
                        'rpc_method' => $requestInfo['method'],
                        'batch_index' => $batchIndex,
                    ], $requestInfo['rpcInfo'], $rpcResponseInfo));
                }
                
                // Check if all responses received
                if ($batchState['responseCount'] >= $batchState['expectedCount']) {
                    // Sort responses by index to maintain order
                    ksort($batchState['responses']);
                    $batchState['deferred']->resolve(array_values($batchState['responses']));
                }
            } else {
                // Single request handling
                // Log response
                if ($this->accessLog) {
                    $rpcResponseInfo = $this->accessLog->extractRpcResponseInfo($responseBody);
                    $this->accessLog->log('RESPONSE', array_merge([
                        'uri' => $this->uri,
                        'request_body' => $requestInfo['requestBody'],
                        'response_body' => $responseBody,
                        'duration' => $duration,
                        'rpc_method' => $requestInfo['method'],
                    ], $requestInfo['rpcInfo'], $rpcResponseInfo));
                }

                $deferred = $requestInfo['deferred'];

                if ($response instanceof ErrorResponse) {
                    $deferred->reject(new \RuntimeException(
                        $response->getMessage(),
                        $response->getCode()
                    ));
                } elseif ($response instanceof ResultResponse) {
                    $deferred->resolve($response->getValue());
                }
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
        foreach ($this->pendingRequests as $requestInfo) {
            $requestInfo['deferred']->reject(new \RuntimeException('Connection closed'));
        }
        $this->pendingRequests = [];
        
        // Reject all batch requests
        foreach ($this->batchStates as $batchState) {
            $batchState['deferred']->reject(new \RuntimeException('Connection closed'));
        }
        $this->batchStates = [];
    }
}
