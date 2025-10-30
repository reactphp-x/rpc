<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\ChildProcess;

use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use Datto\JsonRpc\Client as JsonRpcClient;
use Datto\JsonRpc\Responses\ErrorResponse;
use Datto\JsonRpc\Responses\ResultResponse;
use React\ChildProcess\Process as ReactProcess;
use React\Promise\PromiseInterface;
use ReactphpX\Rpc\AccessLogHandler;
use function React\Async\async;

/**
 * ChildProcess-based JSON-RPC Client using NDJSON over Process stdin/stdout
 */
class Client
{
    private JsonRpcClient $client;
    private ?Decoder $decoder = null;
    private ?Encoder $encoder = null;
    private array $pendingRequests = [];
    private array $batchStates = [];
    private int $idCounter = 1;
    private int $batchIdCounter = 1;
    private ?AccessLogHandler $accessLog;
    private bool $initialized = false;
    private string $evaluatorClass;
    private ?string $evaluatorFile = null;
    private ?ReactProcess $process = null;

    public function __construct(string $evaluatorClass, ?AccessLogHandler $accessLog = null, ?string $evaluatorFile = null)
    {
        $this->client = new JsonRpcClient();
        $this->evaluatorClass = $evaluatorClass;
        $this->evaluatorFile = $evaluatorFile;
        $this->accessLog = $accessLog;
    }

    /**
     * Initialize decoder and encoder
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // Initialize child process if not already running
        if ($this->process === null || !$this->process->isRunning()) {
            // Pass evaluator class name as command line argument
            $command = sprintf(
                'exec php %s/child_process_init.php %s',
                __DIR__,
                escapeshellarg($this->evaluatorClass)
            );
            
            // If evaluator file is provided, pass it as second argument
            if ($this->evaluatorFile !== null) {
                $command .= ' ' . escapeshellarg($this->evaluatorFile);
            }
            
            $this->process = new ReactProcess($command);
            $this->process->start();
        }

        if ($this->process === null) {
            throw new \RuntimeException('Process is not available');
        }

        // Parent reads from child through stderr (child writes to STDERR)
        // Parent writes to child through stdin (child reads from STDIN)
        $this->decoder = new Decoder($this->process->stderr, true);
        $this->encoder = new Encoder($this->process->stdin);

        $this->decoder->on('data', async(function (array $data) {
            $this->handleResponse($data);
        }));

        $this->process->stderr->on('close', function () {
            $this->decoder = null;
            $this->encoder = null;
            $this->reset();
        });

        $this->process->stderr->on('error', function (\Throwable $error) {
            $this->reset();
        });

        $this->initialized = true;
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
        $this->initialize();

        $startTime = microtime(true);
        $id = $this->idCounter++;
        
        $this->client->query($id, $method, $arguments);
        $request = $this->client->preEncode();
        
        if ($request === null) {
            return \React\Promise\reject(new \RuntimeException('Failed to create request'));
        }

        $requestBody = json_encode($request);
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
        $this->initialize();

        $startTime = microtime(true);
        $this->client->notify($method, $arguments);
        $request = $this->client->preEncode();
        
        if ($request === null) {
            return \React\Promise\reject(new \RuntimeException('Failed to create request'));
        }

        $requestBody = json_encode($request);
        $rpcInfo = $this->accessLog ? $this->accessLog->extractRpcInfo($requestBody) : [];

        // Log notification
        if ($this->accessLog) {
            $duration = microtime(true) - $startTime;
            $this->accessLog->log('NOTIFICATION', array_merge([
                'request_body' => $requestBody,
                'duration' => $duration,
                'rpc_method' => $method,
            ], $rpcInfo));
        }

        $this->encoder->write($request);
        return \React\Promise\resolve(null);
    }

    /**
     * Call multiple methods in batch
     *
     * @param array $calls Array of [method, arguments, id?] pairs
     * @param float $timeout Timeout in seconds (default: 5.0)
     * @return PromiseInterface Promise that resolves to array of Response objects
     */
    public function batch(array $calls, float $timeout = 5.0): PromiseInterface
    {
        $this->initialize();

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
                        'request_body' => $requestInfo['requestBody'],
                        'response_body' => $responseBody,
                        'duration' => $duration,
                        'rpc_method' => $requestInfo['method'],
                        'batch_index' => $batchIndex,
                    ], $requestInfo['rpcInfo'], $rpcResponseInfo));
                }
                
                // Check if all responses received
                if ($batchState['responseCount'] >= $batchState['expectedCount']) {
                    ksort($batchState['responses']);
                    $batchState['deferred']->resolve(array_values($batchState['responses']));
                }
            } else {
                // Single request handling
                if ($this->accessLog) {
                    $rpcResponseInfo = $this->accessLog->extractRpcResponseInfo($responseBody);
                    $this->accessLog->log('RESPONSE', array_merge([
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
     * Close the client
     */
    public function close(): void
    {
        if ($this->process !== null) {
            $this->process->terminate();
            $this->process = null;
        }
    }

    /**
     * Reset client state
     */
    private function reset(): void
    {
        $this->decoder = null;
        $this->encoder = null;
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

    public function __destruct()
    {
        $this->close();
    }
}

