<?php

namespace Amims71\TinkerWeb;

use Amims71\TinkerWeb\Connections\ConnectionStore;
use Amims71\TinkerWeb\Http\Request;
use Amims71\TinkerWeb\Http\Response;
use Amims71\TinkerWeb\Runner\RunnerBridge;
use Amims71\TinkerWeb\Runner\SymbolsBridge;
use Amims71\TinkerWeb\Web\TokenGuard;

/**
 * Single-threaded local HTTP server (127.0.0.1). One request per accepted connection.
 * A crashing eval can't take the server down — it runs in a separate runner subprocess.
 */
final class Server
{
    private const CONTENT_TYPES = [
        'js' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'svg' => 'image/svg+xml',
    ];

    private bool $running = true;

    public function __construct(
        private TokenGuard $guard,
        private RunnerBridge $bridge,
        private SymbolsBridge $symbols,
        private ConnectionStore $connections,
        private string $resourcesDir,
    ) {}

    /** @param resource $socket a bound, listening stream_socket_server */
    public function run($socket): void
    {
        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $conn = @stream_socket_accept($socket, 1);
            if ($conn === false) {
                continue;
            }

            $this->handleConnection($conn);
            fclose($conn);
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /** @param resource $conn */
    private function handleConnection($conn): void
    {
        stream_set_timeout($conn, 5);

        $buffer = '';
        while (! str_contains($buffer, "\r\n\r\n")) {
            $chunk = fread($conn, 8192);
            if ($chunk === '' || $chunk === false) {
                return;
            }
            $buffer .= $chunk;
            if (strlen($buffer) > 65536) {
                fwrite($conn, (new Response(431, 'Headers too large'))->toRaw());

                return;
            }
        }

        [$head, $rest] = explode("\r\n\r\n", $buffer, 2);
        $request = Request::parse($head, $rest);

        $length = (int) $request->header('content-length', '0');
        $body = $rest;
        while (strlen($body) < $length) {
            $chunk = fread($conn, $length - strlen($body));
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $body .= $chunk;
        }

        $request = Request::parse($head, substr($body, 0, $length));

        fwrite($conn, $this->route($request)->toRaw());
    }

    private function route(Request $request): Response
    {
        if (! $this->guard->allows($request)) {
            return Response::json(['ok' => false, 'error' => ['message' => 'Forbidden']], 403);
        }

        return match (true) {
            $request->method === 'GET' && $request->path === '/' => $this->serveSpa(),
            $request->method === 'GET' && str_starts_with($request->path, '/assets/') => $this->serveAsset($request->path),
            $request->method === 'GET' && $request->path === '/connections' => Response::json(['connections' => $this->connections->all()]),
            $request->method === 'POST' && $request->path === '/connections' => $this->addConnection($request),
            $request->method === 'POST' && $request->path === '/eval' => $this->eval($request),
            $request->method === 'POST' && $request->path === '/symbols' => $this->symbols($request),
            $request->method === 'POST' && $request->path === '/members' => $this->members($request),
            default => Response::json(['ok' => false, 'error' => ['message' => 'Not found']], 404),
        };
    }

    private function eval(Request $request): Response
    {
        $input = $request->json();
        $project = rtrim((string) ($input['project'] ?? ''), '/');
        $code = (string) ($input['code'] ?? '');

        if (! $this->connections->isValidProject($project)) {
            return Response::json(['ok' => false, 'error' => ['message' => 'Not a Laravel project: '.$project]], 400);
        }

        $this->connections->remember($project);

        return Response::json($this->bridge->eval($project, $code));
    }

    private function symbols(Request $request): Response
    {
        $project = rtrim((string) ($request->json()['project'] ?? ''), '/');
        if (! $this->connections->isValidProject($project)) {
            return Response::json(['ok' => false, 'error' => ['message' => 'Not a Laravel project: '.$project]], 400);
        }

        return Response::json($this->symbols->classes($project));
    }

    private function members(Request $request): Response
    {
        $input = $request->json();
        $project = rtrim((string) ($input['project'] ?? ''), '/');
        $class = (string) ($input['class'] ?? '');
        if (! $this->connections->isValidProject($project)) {
            return Response::json(['ok' => false, 'error' => ['message' => 'Not a Laravel project: '.$project]], 400);
        }

        return Response::json($this->symbols->members($project, $class));
    }

    private function addConnection(Request $request): Response
    {
        $project = rtrim((string) ($request->json()['project'] ?? ''), '/');

        if (! $this->connections->isValidProject($project)) {
            return Response::json(['ok' => false, 'error' => ['message' => 'Not a Laravel project: '.$project]], 400);
        }

        $this->connections->remember($project);

        return Response::json(['connections' => $this->connections->all()]);
    }

    private function serveSpa(): Response
    {
        $path = $this->resourcesDir.'/web/index.html';
        if (! is_file($path)) {
            return Response::json(['ok' => false, 'error' => ['message' => 'Missing web/index.html']], 404);
        }

        // Set the token as a cookie so the browser's sub-resource requests (css/js/eval) carry it
        // automatically — a <link>/<script> tag can't inherit the ?t= from the page URL.
        return new Response(200, (string) file_get_contents($path), [
            'Content-Type' => 'text/html; charset=utf-8',
            'Set-Cookie' => TokenGuard::COOKIE.'='.$this->guard->token().'; Path=/; SameSite=Strict; HttpOnly',
        ]);
    }

    private function serveAsset(string $path): Response
    {
        $name = basename($path);
        $file = $this->resourcesDir.'/dist/'.$name;

        if (! is_file($file) || $name === '' || str_contains($name, '..')) {
            return Response::json(['ok' => false, 'error' => ['message' => 'Not found']], 404);
        }

        return Response::make((string) file_get_contents($file), $this->contentType($file));
    }

    private function contentType(string $path): string
    {
        return self::CONTENT_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }
}
