<?php

declare(strict_types=1);

/**
 * Session + CSRF protection.
 *
 * The brief requires cookie-authenticated sessions. Every mutating request
 * (POST/PATCH/PUT/DELETE) must carry the per-session CSRF token, either as the
 * `X-CSRF-Token` header or as a `_csrf` field in the JSON body. The SPA shell
 * embeds the token on every page load and the JSON API exposes it via
 * `/api/meta` so automated tests can bootstrap a session with a cookie jar.
 *
 * The session cookie is marked HttpOnly + SameSite=Lax; mutations never rely
 * on cookies alone, so a cross-site form cannot forge a change.
 */

function php_tb_start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** @return string token bound to the current session (created on demand) */
function php_tb_csrf_token(): string
{
    php_tb_start_session();
    if (empty($_SESSION['php_tb_csrf'])) {
        $_SESSION['php_tb_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['php_tb_csrf'];
}

/** @param string $token expected value from the session */
function php_tb_csrf_constant_time_equals(string $token, string $provided): bool
{
    return hash_equals($token, $provided);
}