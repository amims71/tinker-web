<?php

namespace Amims71\TinkerWeb\Web;

use Amims71\TinkerWeb\Http\Request;

/** Local-only auth: a per-session URL token (constant-time compared) + a Host-header allowlist. */
final class TokenGuard
{
    public function __construct(
        private string $token,
        private int $port,
    ) {}

    public static function random(int $port): self
    {
        return new self(bin2hex(random_bytes(32)), $port);
    }

    public function token(): string
    {
        return $this->token;
    }

    public function allows(Request $request): bool
    {
        // DNS-rebinding guard: only accept the loopback host we bound to.
        $host = $request->header('host');
        if ($host !== '' && ! in_array($host, ['127.0.0.1:'.$this->port, 'localhost:'.$this->port], true)) {
            return false;
        }

        $provided = $request->query['t'] ?? $request->header('x-token');

        return is_string($provided) && $provided !== '' && hash_equals($this->token, $provided);
    }
}
