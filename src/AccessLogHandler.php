<?php

declare(strict_types=1);

namespace ReactphpX\Rpc;

/**
 * Access Log Handler for JSON-RPC requests and responses
 * 
 * Records detailed access logs including:
 * - Request method, URI, and JSON-RPC details
 * - Response status and JSON-RPC details
 * - Processing time
 * - Request/Response body
 */
class AccessLogHandler
{
    private mixed $logger;
    private bool $logRequestBody;
    private bool $logResponseBody;

    /**
     * @param bool|callable $logger Logger instance or callback function
     *                              If bool: true = echo to stdout, false = disabled
     *                              If callable: function(string $message, array $context): void
     * @param bool $logRequestBody Whether to log request body (default: true)
     * @param bool $logResponseBody Whether to log response body (default: true)
     */
    public function __construct(
        bool|callable $logger = true,
        bool $logRequestBody = true,
        bool $logResponseBody = true
    ) {
        $this->logger = $logger;
        $this->logRequestBody = $logRequestBody;
        $this->logResponseBody = $logResponseBody;
    }

    /**
     * Log access information
     * 
     * @param string $direction Request direction (REQUEST, RESPONSE, NOTIFICATION)
     * @param array $context Context information
     */
    public function log(string $direction, array $context): void
    {
        if (!$this->logger) {
            return;
        }

        $message = $this->formatMessage($direction, $context);

        if (is_callable($this->logger)) {
            ($this->logger)($message, $context);
        } else {
            echo $message;
        }
    }

    /**
     * Format log message
     */
    private function formatMessage(string $direction, array $context): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $parts = ["[{$timestamp}]", $direction];

        // Add remote address if available
        if (isset($context['remote'])) {
            $parts[] = $context['remote'];
        }

        // Add HTTP method and URI if available
        if (isset($context['method']) && isset($context['uri'])) {
            $parts[] = "{$context['method']} {$context['uri']}";
        }

        // Add JSON-RPC method if available
        if (isset($context['rpc_method'])) {
            $parts[] = "method={$context['rpc_method']}";
        }

        // Add JSON-RPC ID if available
        if (isset($context['rpc_id'])) {
            $parts[] = "id={$context['rpc_id']}";
        }

        // Add status code if available
        if (isset($context['status'])) {
            $parts[] = "status={$context['status']}";
        }

        // Add processing time if available
        if (isset($context['duration'])) {
            $ms = round($context['duration'] * 1000, 2);
            $parts[] = "{$ms}ms";
        }

        $message = implode(' ', $parts);

        // Add request body if enabled
        if ($this->logRequestBody && isset($context['request_body'])) {
            $message .= "\n  Request: " . $this->formatBody($context['request_body']);
        }

        // Add response body if enabled
        if ($this->logResponseBody && isset($context['response_body'])) {
            $message .= "\n  Response: " . $this->formatBody($context['response_body']);
        }

        // Add error if available
        if (isset($context['error'])) {
            $message .= "\n  Error: " . $context['error'];
        }

        return $message . "\n";
    }

    /**
     * Format body for logging (truncate if too long)
     */
    private function formatBody(string $body, int $maxLength = 500): string
    {
        if (strlen($body) <= $maxLength) {
            return $body;
        }

        return substr($body, 0, $maxLength) . '... (truncated)';
    }

    /**
     * Extract JSON-RPC information from request body
     */
    public function extractRpcInfo(string $body): array
    {
        $info = [];

        try {
            $data = json_decode($body, true);
            
            if (is_array($data)) {
                // Single request
                if (isset($data['method'])) {
                    $info['rpc_method'] = $data['method'];
                }
                if (isset($data['id'])) {
                    $info['rpc_id'] = is_scalar($data['id']) ? (string)$data['id'] : json_encode($data['id']);
                }
                
                // Batch request
                if (isset($data[0]) && is_array($data[0])) {
                    $info['rpc_batch'] = count($data);
                    if (isset($data[0]['method'])) {
                        $info['rpc_method'] = $data[0]['method'] . ' (batch)';
                    }
                }
            }
        } catch (\Throwable $e) {
            // Invalid JSON, ignore
        }

        return $info;
    }

    /**
     * Extract JSON-RPC information from response body
     */
    public function extractRpcResponseInfo(string $body): array
    {
        $info = [];

        try {
            $data = json_decode($body, true);
            
            if (is_array($data)) {
                // Single response
                if (isset($data['id'])) {
                    $info['rpc_id'] = is_scalar($data['id']) ? (string)$data['id'] : json_encode($data['id']);
                }
                if (isset($data['error'])) {
                    $info['rpc_error'] = $data['error']['message'] ?? 'Unknown error';
                    $info['rpc_error_code'] = $data['error']['code'] ?? -1;
                }
                if (isset($data['result'])) {
                    $info['rpc_result'] = true;
                }
                
                // Batch response
                if (isset($data[0]) && is_array($data[0])) {
                    $info['rpc_batch'] = count($data);
                }
            }
        } catch (\Throwable $e) {
            // Invalid JSON, ignore
        }

        return $info;
    }
}
