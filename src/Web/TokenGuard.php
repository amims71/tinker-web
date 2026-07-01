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

    /** The cookie name the server sets on the page so sub-resources (css/js/eval) carry the token. */
    public const COOKIE = 'tw_token';

    public function allows(Request $request): bool
    {
        // DNS-rebinding guard: only accept the loopback host we bound to.
        $host = $request->header('host');
        if ($host !== '' && ! in_array($host, ['127.0.0.1:'.$this->port, 'localhost:'.$this->port], true)) {
            return false;
        }

        // Token may arrive as ?t= (initial page load), X-Token (fetch), or the cookie we set (assets).
        $provided = $request->query['t'] ?? $request->header('x-token');
        if (! is_string($provided) || $provided === '') {
            $provided = $this->cookieToken($request);
        }

        return is_string($provided) && $provided !== '' && hash_equals($this->token, $provided);
    }

    private function cookieToken(Request $request): ?string
    {
        $cookie = $request->header('cookie');
        if ($cookie === '') {
            return null;
        }

        foreach (explode(';', $cookie) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($name === self::COOKIE) {
                return $value;
            }
        }

        return null;
    }
}
