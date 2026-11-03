<?php

/**
 * Adds columns introduced after the original schema, for databases that
 * already exist (docker volume or production). Safe to run more than once.
 */
require_once __DIR__ . '/lib/MapAudience.php';

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

$width = $pdo->prepare(
    'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
);
$width->execute(array($db->dbname, 'item', 'collmex_address_groups'));
if ((int) $width->fetchColumn() < 512) {
    $pdo->exec(
        'ALTER TABLE item MODIFY collmex_address_groups varchar(512) NOT NULL'
    );
    echo "widened item.collmex_address_groups\n";
} else {
    echo "item.collmex_address_groups already wide enough\n";
}

$staffColumns = array(
    'phone2' => "varchar(40) NOT NULL DEFAULT ''",
    'department' => "varchar(80) NOT NULL DEFAULT ''",
    'firstname' => "varchar(40) NOT NULL DEFAULT ''",
    'surname' => "varchar(40) NOT NULL DEFAULT ''",
    'note' => "varchar(1024) NOT NULL DEFAULT ''",
    'inactive' => 'tinyint(1) unsigned NOT NULL DEFAULT 0',
    'no_mailings' => 'tinyint(1) unsigned NOT NULL DEFAULT 0',
);
foreach ($staffColumns as $column => $definition) {
    $exists->execute(array($db->dbname, 'item', $column));
    if ((int) $exists->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE item ADD COLUMN ' . $column . ' ' . $definition);
        echo 'added item.' . $column . "\n";
    } else {
        echo 'item.' . $column . " already present\n";
    }
}

$tableExists = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
);
$tableExists->execute(array($db->dbname, 'item_contact'));
if ((int) $tableExists->fetchColumn() === 0) {
    $pdo->exec(
        "CREATE TABLE item_contact (
            id int(10) unsigned NOT NULL AUTO_INCREMENT,
            item_id int(10) unsigned NOT NULL,
            collmex_id int(10) unsigned NOT NULL DEFAULT 0,
            name varchar(120) NOT NULL DEFAULT '',
            department varchar(80) NOT NULL DEFAULT '',
            phone varchar(40) NOT NULL DEFAULT '',
            phone2 varchar(40) NOT NULL DEFAULT '',
            email varchar(120) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY item_id (item_id),
            CONSTRAINT item_contact_item_id FOREIGN KEY (item_id) REFERENCES item (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );
    echo "added item_contact\n";
} else {
    echo "item_contact already present\n";
}

$tableExists->execute(array($db->dbname, 'address_group'));
if ((int) $tableExists->fetchColumn() === 0) {
    $pdo->exec(
        "CREATE TABLE address_group (
            collmex_id int(10) unsigned NOT NULL,
            name varchar(255) NOT NULL,
            PRIMARY KEY (collmex_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );
    echo "added address_group\n";
} else {
    echo "address_group already present\n";
}

if (MapAudience::clearsStoredPhones(MapAudience::fromConfig($config))) {
    $cleared = $pdo->exec(
        "UPDATE item SET phone = '', phone2 = '', department = '', firstname = '', surname = '', note = '', inactive = 0, no_mailings = 0"
    );
    $pdo->exec('DELETE FROM item_contact');
    echo 'cleared stored staff details: ' . (int) $cleared . "\n";
} else {
    echo "kept stored staff details\n";
}
