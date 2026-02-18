<?php
/**
 * Environment Variable Loader
 * 
 * Parses .env files and provides typed access to environment variables.
 * Based on WebWatch pattern - vanilla PHP, no framework dependencies.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

class Env
{
    private static array $variables = [];
    private static bool $loaded = false;

    /**
     * Load environment variables from a .env file
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (!file_exists($path)) {
            throw new RuntimeException(".env file not found at: {$path}");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments
            if (str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            // Must contain =
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove surrounding quotes
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            // Variable interpolation ${VAR}
            $value = preg_replace_callback('/\$\{(\w+)\}/', function ($matches) {
                return self::$variables[$matches[1]] ?? $_ENV[$matches[1]] ?? $matches[0];
            }, $value);

            self::$variables[$key] = $value;
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }

        self::$loaded = true;
    }

    /**
     * Get an environment variable
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$variables[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }

    /**
     * Get as string
     */
    public static function string(string $key, string $default = ''): string
    {
        return (string)(self::get($key, $default));
    }

    /**
     * Get as integer
     */
    public static function int(string $key, int $default = 0): int
    {
        return (int)(self::get($key, $default));
    }

    /**
     * Get as boolean
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);
        if (is_bool($value)) return $value;
        return in_array(strtolower((string)$value), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Get as float
     */
    public static function float(string $key, float $default = 0.0): float
    {
        return (float)(self::get($key, $default));
    }
}

/**
 * Global helper function
 */
function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}
