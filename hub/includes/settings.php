<?php
/**
 * Settings Manager
 * 
 * Loads all settings from the app_settings table into memory.
 * Encrypted values are decrypted transparently on read.
 * All API keys, OAuth credentials, and configuration live here — NOT in .env.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

class Settings
{
    /** @var array<string, array{value: string, encrypted: bool, category: string}> */
    private static array $cache = [];
    private static bool $loaded = false;

    /**
     * Load all settings from the database into memory.
     * Called once during bootstrap (config.php).
     */
    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        $rows = dbFetchAll("SELECT setting_key, setting_value, is_encrypted, category FROM app_settings");

        foreach ($rows as $row) {
            self::$cache[$row['setting_key']] = [
                'value'     => $row['setting_value'],
                'encrypted' => (bool)$row['is_encrypted'],
                'category'  => $row['category'],
            ];
        }

        self::$loaded = true;
    }

    /**
     * Get a setting value. Decrypts automatically if stored encrypted.
     */
    public static function get(string $key, string $default = ''): string
    {
        if (!isset(self::$cache[$key])) {
            return $default;
        }

        $entry = self::$cache[$key];
        $value = $entry['value'];

        if ($entry['encrypted'] && !empty($value)) {
            $decrypted = decrypt($value);
            return ($decrypted !== false) ? $decrypted : $default;
        }

        return $value ?? $default;
    }

    /**
     * Get a setting as integer
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, (string)$default);
        return (int)$value;
    }

    /**
     * Get a setting as boolean
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? '1' : '0');
        return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Save a setting to the database and update cache.
     * 
     * @param string $key       Setting key
     * @param string $value     Plain-text value (encrypted before storage if $encrypt=true)
     * @param bool   $encrypt   Whether to encrypt before storing
     * @param string $category  Setting category for grouping in admin UI
     */
    public static function save(string $key, string $value, bool $encrypt = false, string $category = 'general'): void
    {
        $storeValue = $encrypt ? encrypt($value) : $value;

        $existing = dbFetchOne("SELECT id FROM app_settings WHERE setting_key = ?", [$key]);

        if ($existing) {
            dbUpdate('app_settings', [
                'setting_value' => $storeValue,
                'is_encrypted'  => $encrypt ? 1 : 0,
                'category'      => $category,
            ], 'setting_key = ?', [$key]);
        } else {
            dbInsert('app_settings', [
                'setting_key'   => $key,
                'setting_value' => $storeValue,
                'is_encrypted'  => $encrypt ? 1 : 0,
                'category'      => $category,
            ]);
        }

        // Update cache
        self::$cache[$key] = [
            'value'     => $storeValue,
            'encrypted' => $encrypt,
            'category'  => $category,
        ];
    }

    /**
     * Delete a setting
     */
    public static function delete(string $key): void
    {
        dbExecute("DELETE FROM app_settings WHERE setting_key = ?", [$key]);
        unset(self::$cache[$key]);
    }

    /**
     * Get all settings in a category (for admin display).
     * Returns decrypted values.
     */
    public static function getByCategory(string $category): array
    {
        $result = [];
        foreach (self::$cache as $key => $entry) {
            if ($entry['category'] === $category) {
                $result[$key] = [
                    'value'     => $entry['encrypted'] ? '••••••••' : ($entry['value'] ?? ''),
                    'encrypted' => $entry['encrypted'],
                    'category'  => $entry['category'],
                ];
            }
        }
        return $result;
    }

    /**
     * Get all categories
     */
    public static function getCategories(): array
    {
        $categories = [];
        foreach (self::$cache as $entry) {
            $categories[$entry['category']] = true;
        }
        return array_keys($categories);
    }

    /**
     * Check if a setting exists and has a non-empty value
     */
    public static function has(string $key): bool
    {
        if (!isset(self::$cache[$key])) {
            return false;
        }
        return !empty(self::$cache[$key]['value']);
    }

    /**
     * Check if settings have been loaded (DB is available)
     */
    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /**
     * Invalidate cache (force reload on next access)
     */
    public static function invalidate(): void
    {
        self::$cache = [];
        self::$loaded = false;
    }

    /**
     * Bulk save multiple settings at once (transactional)
     */
    public static function saveMany(array $settings): void
    {
        dbBeginTransaction();
        try {
            foreach ($settings as $item) {
                self::save(
                    $item['key'],
                    $item['value'],
                    $item['encrypt'] ?? false,
                    $item['category'] ?? 'general'
                );
            }
            dbCommit();
        } catch (Exception $e) {
            dbRollback();
            throw $e;
        }
    }

    /**
     * Get the raw (non-decrypted) store for export/debug.
     * Encrypted values are shown as masked.
     */
    public static function dump(): array
    {
        $out = [];
        foreach (self::$cache as $key => $entry) {
            $out[$key] = [
                'value'     => $entry['encrypted'] ? '[encrypted]' : $entry['value'],
                'encrypted' => $entry['encrypted'],
                'category'  => $entry['category'],
            ];
        }
        return $out;
    }
}
