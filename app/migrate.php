<?php

/**
 * Adds columns introduced after the original schema, for databases that
 * already exist (docker volume or production). Safe to run more than once.
 */
$config = include __DIR__ . '/config/config.php';

$db = $config->database;
$dsn = 'mysql:host=' . $db->host . ';dbname=' . $db->dbname . ';charset=utf8';

$pdo = new PDO($dsn, $db->username, $db->password, array(
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
));

$exists = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
);
$exists->execute(array($db->dbname, 'item', 'geocode_pending'));

if ((int) $exists->fetchColumn() === 0) {
    $pdo->exec(
        'ALTER TABLE item ADD COLUMN geocode_pending tinyint(1) unsigned NOT NULL DEFAULT 0'
    );
    echo "added item.geocode_pending\n";
} else {
    echo "item.geocode_pending already present\n";
}
