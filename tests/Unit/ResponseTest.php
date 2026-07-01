<?php

use Amims71\TinkerWeb\Http\Response;

it('renders a JSON response with the right headers and framing', function () {
    $raw = Response::json(['ok' => true, 'n' => 42])->toRaw();

    expect($raw)->toStartWith("HTTP/1.1 200 OK\r\n")
        ->and($raw)->toContain('Content-Type: application/json')
        ->and($raw)->toContain('Connection: close')
        ->and($raw)->toContain('X-Content-Type-Options: nosniff')
        ->and($raw)->toContain("\r\n\r\n".'{"ok":true,"n":42}');

    $body = '{"ok":true,"n":42}';
    expect($raw)->toContain('Content-Length: '.strlen($body));
});

it('carries a custom status and content type', function () {
    $raw = Response::make('body{}', 'text/css; charset=utf-8')->toRaw();
    expect($raw)->toContain('Content-Type: text/css; charset=utf-8');

    $forbidden = Response::json(['error' => 'x'], 403)->toRaw();
    expect($forbidden)->toStartWith('HTTP/1.1 403 Forbidden');
});
