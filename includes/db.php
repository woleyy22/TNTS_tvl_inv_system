<?php
require_once __DIR__ . '/../config.php';

// One shared database connection (PDO)
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die('<h3>Cannot connect to the database.</h3>'
              . '<p>Check that MySQL is running in XAMPP, that you imported '
              . '<code>tnts_inventory_schema.sql</code>, and that the settings in '
              . '<code>config.php</code> are correct.</p>');
        }
    }
    return $pdo;
}

// Run a query that returns one value (a count, a sum, ...)
function db_scalar(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? 0 : $v;
}
