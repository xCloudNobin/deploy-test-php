<?php

declare(strict_types=1);

/**
 * Front controller / web-root router.
 *
 * Production start (PHP built-in web server, `public` is the web root):
 *
 *   php -S 0.0.0.0:8080 -t public public/index.php
 *
 * With a router script the built-in server first checks for a static file,
 * then delegates here. This controller:
 *   - threads the request through the JSON API when the path starts with
 *     `/api/...`;
 *   - serves existing static assets (JS/CSS) from `public/`;
 *   - returns 404 for missing static assets;
 *   - renders the SPA shell for every other path, so nested client routes
 *     (e.g. `/project/3`) load the same UI and the client deep-links.
 *
 * The same file also works behind PHP-FPM/FastCGI with a web server that
 * sends all requests to this script (FastCGI example documented in README).
 */

$root = dirname(__DIR__);

require $root . '/src/config.php';
require $root . '/src/session.php';
require $root . '/src/db.php';
require $root . '/src/api.php';
require $root . '/src/ui.php';

$cfg = PhpTaskboard_Cfg::fromEnv(getenv());
$sessionToken = php_tb_csrf_token();

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
if ($path === false || $path === '') {
    $path = '/';
}
if ($cfg->basePath !== '' && str_starts_with($path, $cfg->basePath)) {
    $path = substr($path, strlen($cfg->basePath));
    if ($path === '') {
        $path = '/';
    }
}
if ($path !== '/' && !str_starts_with($path, '/')) {
    $path = '/' . $path;
}

$segments = array_values(array_filter(
    explode('/', $path),
    static fn(string $part) => $part !== ''
));

foreach ($segments as $part) {
    $decoded = rawurldecode($part);
    if ($decoded === '..') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found\n";
        exit;
    }
}

// Read and decode the request body exactly once; `$malformed` records a body
// that is not a valid JSON object so the API can answer 400 meaningfully.
$rawBody = file_get_contents('php://input');
$body = null;
$malformed = false;
if (is_string($rawBody) && trim($rawBody) !== '') {
    try {
        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
            $body = $decoded;
        } else {
            $malformed = true;
        }
    } catch (JsonException) {
        $malformed = true;
    }
}

$providedCsrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($providedCsrf === '' && is_array($body) && isset($body['_csrf']) && is_string($body['_csrf'])) {
    $providedCsrf = $body['_csrf'];
}

if (($segments[0] ?? '') === 'api') {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $result = php_tb_api_dispatch(
        $method,
        $segments,
        $_GET,
        $body,
        $malformed,
        $sessionToken,
        $providedCsrf,
        $cfg->dbPath,
        $cfg,
    );
    http_response_code($result['status']);
    foreach ($result['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }
    header('Content-Type: application/json; charset=utf-8');
    if ($result['body'] !== null && $result['status'] !== 204) {
        echo json_encode($result['body'], JSON_UNESCAPED_SLASHES) ?: '{}';
    }
    exit;
}

$baseName = basename($path);
if (str_contains($baseName, '.')) {
    // Static asset request: serve it if present, otherwise 404.
    $file = $root . '/public' . $path;
    if (is_file($file)) {
        php_tb_serve_static($file);
        exit;
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

// Everything else is the SPA shell (UI + nested client routes).
php_tb_render_ui($cfg, $sessionToken, $path);

function php_tb_serve_static(string $file): void
{
    $types = [
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'txt' => 'text/plain; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
    ];
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $type = $types[$extension] ?? 'application/octet-stream';
    header('Content-Type: ' . $type);
    header('Content-Length: ' . (string)filesize($file));
    readfile($file);
}