<?php

namespace Amims71\TinkerWeb\Http;

/**
 * A parsed HTTP/1.1 request. `parse()` is pure (head block + body) so it's unit-testable
 * independently of the socket reads the server performs.
 */
final class Request
{
    /**
     * @param  array<string,string>  $headers  lower-cased header names
     * @param  array<string,string>  $query
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public static function parse(string $headBlock, string $body): self
    {
        $lines = explode("\r\n", $headBlock);
        $requestLine = array_shift($lines) ?? '';
        [$method, $target] = array_pad(explode(' ', $requestLine, 3), 2, '');

        $headers = [];
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }

        $path = parse_url($target, PHP_URL_PATH) ?: '/';
        $query = [];
        parse_str((string) (parse_url($target, PHP_URL_QUERY) ?: ''), $query);

        return new self(strtoupper($method), $path, $query, $headers, $body);
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
