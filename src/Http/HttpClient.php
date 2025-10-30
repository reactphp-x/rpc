<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Http;

use Datto\JsonRpc\Client as JsonRpcClient;
use Datto\JsonRpc\Responses\ErrorResponse;
use Datto\JsonRpc\Responses\ResultResponse;
use React\Http\Browser;
use React\Promise\PromiseInterface;

/**
 * HTTP-based JSON-RPC Client
 */
class HttpClient
{
    private Browser $browser;
    private JsonRpcClient $client;
    private string $url;

    public function __construct(Browser $browser, string $url)
    {
        $this->browser = $browser;
        $this->client = new JsonRpcClient();
        $this->url = $url;
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
        $id = $this->generateId();
        $this->client->query($id, $method, $arguments);
        $json = $this->client->encode();

        if ($json === null) {
            return \React\Promise\reject(new \RuntimeException('No request to send'));
        }

        return $this->browser->post(
            $this->url,
            ['Content-Type' => 'application/json'],
            $json
        )->then(function ($response) {
            $body = (string)$response->getBody();
            
            if ($response->getStatusCode() === 204) {
                // Notification - no response
                return null;
            }

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('HTTP error: ' . $response->getStatusCode());
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
        $this->client->notify($method, $arguments);
        $json = $this->client->encode();

        if ($json === null) {
            return \React\Promise\resolve(null);
        }

        return $this->browser->post(
            $this->url,
            ['Content-Type' => 'application/json'],
            $json
        )->then(function ($response) {
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

        return $this->browser->post(
            $this->url,
            ['Content-Type' => 'application/json'],
            $json
        )->then(function ($response) {
            $body = (string)$response->getBody();
            
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
