<?php
/**
 * Database Connection & Helpers
 * 
 * PDO singleton with query helper functions.
 * MySQL 8.4 compatible with prepared statements only.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

/**
 * Get PDO database instance (singleton)
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                throw $e;
            }
            error_log("CCM API Hub DB Error: " . $e->getMessage());
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed']));
        }
    }

    return $pdo;
}

/**
 * Execute a query and return all rows
 */
function dbFetchAll(string $sql, array $params = []): array
{
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Execute a query and return single row
 */
function dbFetchOne(string $sql, array $params = []): ?array
{
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();
    return $result ?: null;
}

/**
 * Execute a query and return affected rows count
 */
function dbExecute(string $sql, array $params = []): int
{
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Insert a row and return the last insert ID
 */
function dbInsert(string $table, array $data): string
{
    $columns = implode(', ', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));

    $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
    $stmt = getDB()->prepare($sql);
    $stmt->execute(array_values($data));

    return getDB()->lastInsertId();
}

/**
 * Update rows and return affected count
 */
function dbUpdate(string $table, array $data, string $where, array $whereParams = []): int
{
    $sets = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($data)));
    $sql = "UPDATE {$table} SET {$sets} WHERE {$where}";
    $stmt = getDB()->prepare($sql);
    $stmt->execute(array_merge(array_values($data), $whereParams));

    return $stmt->rowCount();
}

/**
 * Delete rows and return affected count
 */
function dbDelete(string $table, string $where, array $params = []): int
{
    $sql = "DELETE FROM {$table} WHERE {$where}";
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

/**
 * Begin a transaction
 */
function dbBeginTransaction(): bool
{
    return getDB()->beginTransaction();
}

/**
 * Commit a transaction
 */
function dbCommit(): bool
{
    return getDB()->commit();
}

/**
 * Rollback a transaction
 */
function dbRollback(): bool
{
    return getDB()->rollBack();
}

/**
 * Get a single value from a query
 */
function dbFetchValue(string $sql, array $params = []): mixed
{
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}
