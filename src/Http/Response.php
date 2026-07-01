<?php

namespace Amims71\TinkerWeb\Http;

/** A minimal HTTP/1.1 response. Always Connection: close (one request per accepted socket). */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {}

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, (string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES), [
            'Content-Type' => 'application/json',
        ]);
    }

    public static function make(string $body, string $contentType, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => $contentType]);
    }

    public static function text(string $body, int $status = 200): self
    {
        return self::make($body, 'text/plain; charset=utf-8', $status);
    }

    public function toRaw(): string
    {
        $reason = [200 => 'OK', 400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found', 500 => 'Internal Server Error'][$this->status] ?? 'OK';

        $headers = array_merge([
            'Content-Type' => 'text/plain; charset=utf-8',
        ], $this->headers, [
            'Content-Length' => (string) strlen($this->body),
            'Connection' => 'close',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $head = "HTTP/1.1 {$this->status} {$reason}\r\n";
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }

        return $head."\r\n".$this->body;
    }
}
