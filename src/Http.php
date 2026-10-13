<?php

namespace Kehai;

use Closure;

/**
 * The real transport for source adapters: a GET over HTTPS that returns
 * `['status' => int, 'body' => string]` and throws on a network failure.
 * Adapters take the transport as a closure, so tests run against recorded
 * answers without a network.
 *
 * ext-curl when available, otherwise PHP's stream wrapper. HTTPS only, no
 * redirects (a redirect would carry the key header to another host), at
 * most MAX_BYTES, and a hard time limit: 30 seconds for the CLI, 5 seconds
 * in pull mode (Pull::TIMEOUT).
 */
final class Http
{
    public const MAX_BYTES = 10_000_000;
    public const USER_AGENT = 'kirby-kehai/1.0 (+https://github.com/simsimmal/kirby-kehai)';

    public static function transport(int $timeout = 30): Closure
    {
        return function (string $url, array $headers) use ($timeout): array {
            if (!str_starts_with($url, 'https://')) {
                throw new \RuntimeException('only https addresses are fetched');
            }
            return function_exists('curl_init') ? self::curl($url, $headers, $timeout) : self::stream($url, $headers, $timeout);
        };
    }

    /** @return array{status:int, body:string} */
    private static function curl(string $url, array $headers, int $timeout): array
    {
        $handle = curl_init($url);
        $body = '';
        curl_setopt_array($handle, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_WRITEFUNCTION  => function ($handle, string $chunk) use (&$body): int {
                $body .= $chunk;
                return strlen($body) > self::MAX_BYTES ? 0 : strlen($chunk); // 0 aborts
            },
        ]);
        $ok = curl_exec($handle);
        if ($ok === false) {
            throw new \RuntimeException(curl_errno($handle) === CURLE_OPERATION_TIMEDOUT ? 'timed out after ' . $timeout . ' seconds' : curl_error($handle));
        }
        // No curl_close(): a no-op since PHP 8.0, deprecated in 8.5.
        return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body];
    }

    /** @return array{status:int, body:string} */
    private static function stream(string $url, array $headers, int $timeout): array
    {
        $context = stream_context_create(['http' => [
            'method'          => 'GET',
            'header'          => implode("\r\n", $headers),
            'timeout'         => $timeout,
            'follow_location' => 0,
            'ignore_errors'   => true,
            'user_agent'      => self::USER_AGENT,
        ]]);
        $body = @file_get_contents($url, false, $context, 0, self::MAX_BYTES + 1);
        // http_get_last_response_headers() since PHP 8.4; the magic local
        // variable is deprecated in 8.5, hence the indirection (as in Shoji).
        $legacy = 'http_response_header';
        $lines = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($$legacy ?? []);
        if ($body === false) {
            throw new \RuntimeException(error_get_last()['message'] ?? 'request failed');
        }
        $status = 0;
        foreach ($lines as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        return ['status' => $status, 'body' => substr($body, 0, self::MAX_BYTES)];
    }
}
