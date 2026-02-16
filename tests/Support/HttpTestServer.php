<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class HttpTestServer
{
    private string $projectRoot;
    private string $host;
    private int $port;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    public function __construct(string $projectRoot, string $host = '127.0.0.1', ?int $port = null)
    {
        $this->projectRoot = $projectRoot;
        $this->host = $host;
        $this->port = $port ?? $this->findFreePort();
    }

    public function start(?array $env = null): void
    {
        if (is_resource($this->process)) {
            return;
        }

        $command = sprintf(
            '%s -S %s:%d -t public',
            escapeshellarg(PHP_BINARY),
            $this->host,
            $this->port
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->projectRoot, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start PHP built-in server.');
        }

        $this->process = $process;
        $this->pipes = $pipes;

        if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
            stream_set_blocking($this->pipes[1], false);
        }
        if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            stream_set_blocking($this->pipes[2], false);
        }

        $this->waitUntilReady();
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            @proc_terminate($this->process);
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            @proc_close($this->process);
        }

        $this->process = null;
        $this->pipes = [];
    }

    public function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'ignore_errors' => true,
                'timeout' => 15,
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
            ],
        ]);

        $bodyResult = @file_get_contents($this->getBaseUrl() . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $status = $this->extractStatusCode($responseHeaders);

        $textBody = is_string($bodyResult) ? $bodyResult : '';
        $json = json_decode($textBody, true);

        return [
            'status' => $status,
            'body' => $textBody,
            'json' => is_array($json) ? $json : null,
            'headers' => $responseHeaders,
        ];
    }

    public function getBaseUrl(): string
    {
        return sprintf('http://%s:%d', $this->host, $this->port);
    }

    private function waitUntilReady(): void
    {
        $url = $this->getBaseUrl() . '/__ready__';

        for ($attempt = 0; $attempt < 80; $attempt++) {
            if (!$this->isRunning()) {
                throw new RuntimeException('Server exited before becoming ready. ' . $this->readStderr());
            }

            @file_get_contents($url);
            $responseHeaders = $http_response_header ?? [];
            $status = $this->extractStatusCode($responseHeaders);

            if ($status > 0) {
                return;
            }

            usleep(100000);
        }

        throw new RuntimeException('Server did not become ready in time. ' . $this->readStderr());
    }

    private function isRunning(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);
        return (bool) ($status['running'] ?? false);
    }

    private function readStderr(): string
    {
        if (!isset($this->pipes[2]) || !is_resource($this->pipes[2])) {
            return '';
        }

        $stderr = stream_get_contents($this->pipes[2]);
        return is_string($stderr) ? trim($stderr) : '';
    }

    private function extractStatusCode(array $headers): int
    {
        if (!isset($headers[0])) {
            return 0;
        }

        if (preg_match('/\s(\d{3})\s/', $headers[0], $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    private function findFreePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException('Unable to allocate free TCP port: ' . $error);
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if (!is_string($name) || !str_contains($name, ':')) {
            throw new RuntimeException('Unable to parse allocated socket name.');
        }

        $parts = explode(':', $name);
        $port = (int) end($parts);

        if ($port <= 0) {
            throw new RuntimeException('Invalid allocated TCP port.');
        }

        return $port;
    }
}
