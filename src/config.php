<?php

declare(strict_types=1);

/**
 * Configuration resolution for the plain-PHP taskboard.
 *
 * The process supervisor (scripts/* or the deploy target) is responsible for
 * exporting these variables; `.env.example` documents safe defaults. Values
 * are read from the environment array passed in, so the same code powers the
 * front controller, the test suite and the readiness probe.
 */

final class PhpTaskboard_Cfg
{
    public int $port;
    public string $bind;
    public string $dataDir;
    public string $dbPath;
    public string $marker;
    public string $root;
    public string $basePath;

    private function __construct()
    {
    }

    public static function fromEnv(array $env): self
    {
        $root = dirname(__DIR__); // <repo root>

        $dataDir = self::envGet($env, 'DATA_DIR', '');
        if ($dataDir === '') {
            $dataDir = $root . DIRECTORY_SEPARATOR . 'data';
        } else {
            $dataDir = self::resolvePath($root, $dataDir);
        }

        $dbPath = self::envGet($env, 'DATABASE_PATH', '');
        if ($dbPath === '') {
            $dbPath = $dataDir . DIRECTORY_SEPARATOR . 'taskboard.db';
        } else {
            $dbPath = self::resolvePath($root, $dbPath);
        }

        $marker = trim(self::envGet($env, 'BUILD_MARKER', ''));
        if ($marker === '') {
            $versionFile = $root . DIRECTORY_SEPARATOR . 'VERSION';
            if (is_file($versionFile)) {
                $value = trim((string)file_get_contents($versionFile));
                if ($value !== '') {
                    $marker = $value;
                }
            }
        }
        if ($marker === '') {
            $marker = 'develop';
        }

        $port = filter_var(self::envGet($env, 'PORT', '8080'), FILTER_VALIDATE_INT);
        if ($port === false || $port < 0 || $port > 65535) {
            throw new RuntimeException(sprintf(
                'PORT must be an integer in [0, 65535], got "%s"',
                self::envGet($env, 'PORT', '8080'),
            ));
        }

        $bind = trim(self::envGet($env, 'BIND_HOST', '0.0.0.0'));
        if ($bind === '') {
            $bind = '0.0.0.0';
        }

        $basePath = trim(self::envGet($env, 'BASE_PATH', ''));
        if ($basePath !== '' && $basePath[0] !== '/') {
            $basePath = '/' . $basePath;
        }
        $basePath = rtrim($basePath, '/');

        $cfg = new self();
        $cfg->port = (int)$port;
        $cfg->bind = $bind;
        $cfg->dataDir = $dataDir;
        $cfg->dbPath = $dbPath;
        $cfg->marker = $marker;
        $cfg->root = $root;
        $cfg->basePath = $basePath;
        return $cfg;
    }

    private static function envGet(array $env, string $key, string $default): string
    {
        $value = $env[$key] ?? null;
        if ($value === null || $value === false) {
            return $default;
        }
        $value = trim((string)$value);
        return $value === '' ? $default : $value;
    }

    private static function resolvePath(string $root, string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return $root . DIRECTORY_SEPARATOR . $path;
    }
}