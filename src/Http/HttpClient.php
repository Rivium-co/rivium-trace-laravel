<?php

namespace RiviumTrace\Laravel\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RiviumTrace\Laravel\Config\RiviumTraceConfig;

class HttpClient
{
    private Client $guzzle;
    private RiviumTraceConfig $config;
    private const RETRY_LIMIT = 3;

    public function __construct(RiviumTraceConfig $config)
    {
        $this->config = $config;

        $stack = HandlerStack::create();
        $stack->push(Middleware::retry(
            $this->buildRetryDecider(),
            $this->buildRetryDelay()
        ));

        $this->guzzle = new Client([
            'handler' => $stack,
            'base_uri' => $config->apiUrl,
            'timeout' => $config->timeout,
            'connect_timeout' => 3,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $config->apiKey,
                'x-server-secret' => $config->serverSecret,
                'User-Agent' => $config->getUserAgent(),
            ],
        ]);
    }

    public function sendError(array $payload): array
    {
        return $this->doPost('/api/errors', $payload);
    }

    public function sendLog(array $payload): bool
    {
        return $this->doPost('/api/logs/ingest', $payload)['success'];
    }

    public function sendLogBatch(array $payload): bool
    {
        return $this->doPost('/api/logs/ingest/batch', $payload)['success'];
    }

    public function sendPerformanceSpanBatch(array $spans): bool
    {
        return $this->doPost('/api/performance/spans/batch', ['spans' => $spans])['success'];
    }

    private function doPost(string $uri, array $body): array
    {
        try {
            $response = $this->guzzle->post($uri, ['json' => $body]);
            $code = $response->getStatusCode();
            $ok = ($code >= 200 && $code < 300) || $code === 409;

            return ['success' => $ok, 'statusCode' => $code];
        } catch (RequestException $e) {
            if ($this->config->debug) {
                error_log('[RiviumTrace] HTTP error: ' . $e->getMessage());
            }
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            if ($this->config->debug) {
                error_log('[RiviumTrace] Unexpected error: ' . $e->getMessage());
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function buildRetryDecider(): callable
    {
        return function (
            int $attempt,
            RequestInterface $req,
            ?ResponseInterface $res = null,
            ?\Throwable $err = null
        ): bool {
            if ($attempt >= self::RETRY_LIMIT) {
                return false;
            }
            if ($err instanceof ConnectException) {
                return true;
            }
            if ($res && $res->getStatusCode() >= 500) {
                return true;
            }
            return false;
        };
    }

    private function buildRetryDelay(): callable
    {
        return fn (int $attempt): int => 1000 * $attempt;
    }
}
