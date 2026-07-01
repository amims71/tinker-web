<?php

use Amims71\TinkerWeb\Http\Request;
use Amims71\TinkerWeb\Web\TokenGuard;

function req(string $head): Request
{
    return Request::parse($head, '');
}

it('allows a request with the correct token (query) and host', function () {
    $guard = new TokenGuard('secret', 8000);

    expect($guard->allows(req("GET /?t=secret HTTP/1.1\r\nHost: 127.0.0.1:8000")))->toBeTrue()
        ->and($guard->allows(req("GET /?t=secret HTTP/1.1\r\nHost: localhost:8000")))->toBeTrue();
});

it('allows the token via the X-Token header', function () {
    $guard = new TokenGuard('secret', 8000);

    expect($guard->allows(req("POST /eval HTTP/1.1\r\nHost: 127.0.0.1:8000\r\nX-Token: secret")))->toBeTrue();
});

it('rejects a wrong or missing token', function () {
    $guard = new TokenGuard('secret', 8000);

    expect($guard->allows(req("GET /?t=nope HTTP/1.1\r\nHost: 127.0.0.1:8000")))->toBeFalse()
        ->and($guard->allows(req("GET / HTTP/1.1\r\nHost: 127.0.0.1:8000")))->toBeFalse();
});

it('rejects a foreign Host header (DNS-rebinding guard)', function () {
    $guard = new TokenGuard('secret', 8000);

    expect($guard->allows(req("GET /?t=secret HTTP/1.1\r\nHost: evil.example.com")))->toBeFalse()
        ->and($guard->allows(req("GET /?t=secret HTTP/1.1\r\nHost: 127.0.0.1:9999")))->toBeFalse();
});

it('generates a 64-hex-char random token', function () {
    expect(TokenGuard::random(8000)->token())->toMatch('/^[a-f0-9]{64}$/');
});
