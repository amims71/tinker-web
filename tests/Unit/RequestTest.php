<?php

use Amims71\TinkerWeb\Http\Request;

it('parses a GET request line, query and headers', function () {
    $request = Request::parse("GET /connections?t=abc123 HTTP/1.1\r\nHost: 127.0.0.1:8000\r\nX-Token: zzz", '');

    expect($request->method)->toBe('GET')
        ->and($request->path)->toBe('/connections')
        ->and($request->query['t'])->toBe('abc123')
        ->and($request->header('host'))->toBe('127.0.0.1:8000')
        ->and($request->header('X-TOKEN'))->toBe('zzz')          // case-insensitive
        ->and($request->header('missing', 'def'))->toBe('def');
});

it('parses a POST body as JSON', function () {
    $body = '{"project":"/app","code":"1 + 1"}';
    $request = Request::parse("POST /eval HTTP/1.1\r\nContent-Type: application/json", $body);

    expect($request->method)->toBe('POST')
        ->and($request->path)->toBe('/eval')
        ->and($request->body)->toBe($body)
        ->and($request->json())->toBe(['project' => '/app', 'code' => '1 + 1']);
});

it('returns an empty array for a non-JSON body', function () {
    expect(Request::parse('GET / HTTP/1.1', 'not json')->json())->toBe([]);
});
