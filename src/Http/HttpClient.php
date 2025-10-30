<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Http;

use Datto\JsonRpc\Client as JsonRpcClient;
use Datto\JsonRpc\Responses\ErrorResponse;
use Datto\JsonRpc\Responses\ResultResponse;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use ReactphpX\Rpc\AccessLogHandler;

/**
 * HTTP-based JSON-RPC Client
 */
class HttpClient
{
    private Browser $browser;
    private JsonRpcClient $client;
    private string $url;
    private ?AccessLogHandler $accessLog;

    public function __construct(Browser $browser, string $url, ?AccessLogHandler $accessLog = null)
    {
        $this->browser = $browser;
        $this->client = new JsonRpcClient();
        $this->url = $url;
        $this->accessLog = $accessLog;
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
        $startTime = microtime(true);
        $id = $this->generateId();
        $this->client->query($id, $method, $arguments);
        $json = $this->client->encode();

        if ($json === null) {
            return \React\Promise\reject(new \RuntimeException('No request to send'));
        }

        // Extract JSON-RPC info
        $rpcInfo = $this->accessLog ? $this->accessLog->extractRpcInfo($json) : [];

        return $this->browser->post(
            $this->url,
            ['Content-Type' => 'application/json'],
            $json
        )->then(function ($response) use ($startTime, $json, $rpcInfo, $method) {
            $body = (string)$response->getBody();
            $duration = microtime(true) - $startTime;
            
            if ($response->getStatusCode() === 204) {
                // Notification response
                if ($this->accessLog) {
                    $this->accessLog->log('NOTIFICATION', array_merge([
                        'uri' => $this->url,
                        'method' => 'POST',
                        'request_body' => $json,
                        'status' => 204,
                        'duration' => $duration,
                        'rpc_method' => $method,
                    ], $rpcInfo));
                }
                return null;
            }

            if ($response->getStatusCode() !== 200) {
                if ($this->accessLog) {
                    $this->accessLog->log('REQUEST', array_merge([
                        'uri' => $this->url,
                        'method' => 'POST',
                        'request_body' => $json,
                        'status' => $response->getStatusCode(),
                        'duration' => $duration,
                        'rpc_method' => $method,
                        'error' => 'HTTP error: ' . $response->getStatusCode(),
                    ], $rpcInfo));
                }
                throw new \RuntimeException('HTTP error: ' . $response->getStatusCode());
            }

            // Log response
            if ($this->accessLog) {
                $rpcResponseInfo = $this->accessLog->extractRpcResponseInfo($body);
                $this->accessLog->log('RESPONSE', array_merge([
                    'uri' => $this->url,
                    'method' => 'POST',
                    'request_body' => $json,
                    'response_body' => $body,
                    'status' => 200,
                    'duration' => $duration,
                    'rpc_method' => $method,
                ], $rpcInfo, $rpcResponseInfo));
            }

            $responses = $this->client->decode($body);
            $response = $responses[0] ?? null;
            
            if ($response === null) {
                throw new \RuntimeException('Empty response');
            }

            if ($response instanceof ErrorResponse) {
                throw new \RuntimeException(
                    $response->getMessage(),
                    $response->getCode()
                );
            }

            if ($response instanceof ResultResponse) {
                return $response->getValue();
            }

            throw new \RuntimeException('Unknown response type');
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
        $startTime = microtime(true);
        $this->client->notify($method, $arguments);
        $json = $this->client->encode();

        if ($json === null) {
            return \React\Promise\resolve(null);
        }

        // Extract JSON-RPC info
        $rpcInfo = $this->accessLog ? $this->accessLog->extractRpcInfo($json) : [];

        return $this->browser->post(
            $this->url,
            ['Content-Type' => 'application/json'],
            $json
        )->then(function ($response) use ($startTime, $json, $rpcInfo, $method) {
            $duration = microtime(true) - $startTime;
            
            // Log notification
            if ($this->accessLog) {
                $this->accessLog->log('NOTIFICATION', array_merge([
                    'uri' => $this->url,
                    'method' => 'POST',
                    'request_body' => $json,
                    'status' => $response->getStatusCode(),
                    'duration' => $duration,
                    'rpc_method' => $method,
                ], $rpcInfo));
            }
            
            // Notifications should return 204, but we don't care about the response
            return null;
        });
    }

    /**
     * Call multiple methods in batch
     *
     * @param array $calls Array of [method, arguments, id?] pairs
     * @return PromiseInterface Promise that resolves to array of responses
     */
    public function batch(array $calls): PromiseInterface
    {
        $startTime = microtime(true);
        
        foreach ($calls as $call) {
            if (isset($call[2])) {
                // [method, arguments, id]
                $this->client->query($call[2], $call[0], $call[1] ?? null);
            } else {
                // [method, arguments] - query with auto-generated id
                $id = $this->generateId();
                $this->client->query($id, $call[0], $call[1] ?? null);
            }
        }

        $json = $this->client->encode();

        if ($json === null) {
            return \React\Promise\reject(new \RuntimeException('No requests to send'));
        }

        // Extract JSON-RPC info
        $rpcInfo = $this->accessLog ? $this->accessLog->extractRpcInfo($json) : [];

        return $this->browser->post(
            $this->url,
            ['Content-Type' => 'application/json'],
            $json
        )->then(function ($response) use ($startTime, $json, $rpcInfo) {
            $body = (string)$response->getBody();
            $duration = microtime(true) - $startTime;
            
            // Log batch response
            if ($this->accessLog) {
                $rpcResponseInfo = $this->accessLog->extractRpcResponseInfo($body);
                $this->accessLog->log('BATCH RESPONSE', array_merge([
                    'uri' => $this->url,
                    'method' => 'POST',
                    'request_body' => $json,
                    'response_body' => $body,
                    'status' => $response->getStatusCode(),
                    'duration' => $duration,
                ], $rpcInfo, $rpcResponseInfo));
            }

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('HTTP error: ' . $response->getStatusCode());
            }

            return $this->client->decode($body);
        });
    }

    /**
     * Generate a unique request ID
     */
    private function generateId(): int
    {
        return mt_rand(1, PHP_INT_MAX);
    }
}
