<?php

namespace Zomunk;

use RuntimeException;

/** curl wrapper with JSON decoding and a bounded retry on transient failures. */
final class HttpClient
{
    public function __construct(
        private int $timeout = 20,
        private int $retries = 2,
    ) {
    }

    public function getJson(string $url, array $query = [], array $headers = []): array
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $this->request('GET', $url, null, $headers);
    }

    public function postJson(string $url, array $body, array $headers = []): array
    {
        $headers['Content-Type'] = 'application/json';
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $this->request('POST', $url, $payload, $headers);
    }

    public function postForm(string $url, array $body, array $headers = []): array
    {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        return $this->request('POST', $url, http_build_query($body), $headers);
    }

    /**
     * @return array{status:int, body:string, json:array|null}
     */
    private function request(string $method, string $url, ?string $body, array $headers): array
    {
        $headerLines = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = "$name: $value";
        }

        $attempt = 0;
        $lastError = '';

        while ($attempt <= $this->retries) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headerLines,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            if ($body !== null) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
            }

            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            curl_close($handle);

            if ($response === false) {
                $lastError = "curl: $error";
            } elseif ($status === 429 || $status >= 500) {
                // Rate limited or provider hiccup: worth one more try.
                $lastError = "HTTP $status: " . substr($response, 0, 300);
            } else {
                return [
                    'status' => $status,
                    'body'   => $response,
                    'json'   => json_decode($response, true),
                ];
            }

            $attempt++;
            if ($attempt <= $this->retries) {
                sleep(2 ** $attempt);
            }
        }

        throw new RuntimeException("Request to $url failed after {$this->retries} retries. $lastError");
    }
}
