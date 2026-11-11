<?php

declare(strict_types=1);

// 0.6.0: the "Labels" menu becomes "Inventory".
//   labels      -> inventory
//   host_labels -> host_inventory, column label_id -> item_id
// Items and their assignments to hosts are kept. Every step checks the current
// schema first, so the migration can be re-run after an interruption.
// Needs the DROP privilege on the bmon_web database (RENAME TABLE requires it).

return static function (PDO $pdo): void {
    $tableExists = static function (string $t) use ($pdo): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $st->execute([$t]);
        return (int) $st->fetchColumn() > 0;
    };
    $columnExists = static function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$t, $c]);
        return (int) $st->fetchColumn() > 0;
    };
    $foreignKeys = static function (string $t) use ($pdo): array {
        $st = $pdo->prepare("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
        $st->execute([$t]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    };
    $indexExists = static function (string $t, string $i) use ($pdo): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $st->execute([$t, $i]);
        return (int) $st->fetchColumn() > 0;
    };

    // 1. drop the foreign key of the old link table (it points to the old name)
    foreach (['host_labels', 'host_inventory'] as $t) {
        if ($tableExists($t)) {
            foreach ($foreignKeys($t) as $fk) {
                if ($fk !== 'fk_host_inventory_item') {
                    $pdo->exec("ALTER TABLE `$t` DROP FOREIGN KEY `$fk`");
                }
            }
        }
    }

    // 2. rename the tables
    if ($tableExists('labels') && !$tableExists('inventory')) {
        $pdo->exec('RENAME TABLE labels TO inventory');
    }
    if ($tableExists('host_labels') && !$tableExists('host_inventory')) {
        $pdo->exec('RENAME TABLE host_labels TO host_inventory');
    }

    // 3. rename the column and its index
    if ($columnExists('host_inventory', 'label_id')) {
        $pdo->exec('ALTER TABLE host_inventory CHANGE COLUMN label_id item_id INT UNSIGNED NOT NULL');
    }
    if ($indexExists('host_inventory', 'idx_label')) {
        $pdo->exec('ALTER TABLE host_inventory DROP INDEX idx_label');
    }
    if (!$indexExists('host_inventory', 'idx_item')) {
        $pdo->exec('ALTER TABLE host_inventory ADD KEY idx_item (item_id)');
    }

    // 4. foreign key on the new names
    if (!in_array('fk_host_inventory_item', $foreignKeys('host_inventory'), true)) {
        $pdo->exec('ALTER TABLE host_inventory ADD CONSTRAINT fk_host_inventory_item
                    FOREIGN KEY (item_id) REFERENCES inventory (id) ON DELETE CASCADE');
    }
};
