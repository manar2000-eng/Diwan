<?php
/**
 * Data Layer - the ONLY place a PDO connection is created.
 * Spec: SPEC-000-shared (R1 multi-tenancy, R2 PDO prepared only).
 *
 * No Composer / no ORM / no connection pool: shared hosting friendly.
 */

declare(strict_types=1);

// Connection values. Change here only (single source of truth for the Data Layer).
// WAMP local default: user root, empty password, port 3306.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'diwan');
define('DB_USER', 'root');
define('DB_PASS', '');

/**
 * PDO singleton (prepared statements only, no emulation).
 *
 * @return PDO
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $ex) {
        // Never leak credentials/SQL to the browser.
        error_log('Diwan: DB connection failed - ' . $ex->getMessage());
        http_response_code(500);
        exit('تعذر الاتصال بقاعدة البيانات');
    }

    return $pdo;
}