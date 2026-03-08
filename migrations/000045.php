<?php

// Add custom fields useful for eSIM/VPS tracking and bulk management
$columnsToAdd = [
    'subscription_type' => 'TEXT DEFAULT "general"',
    'provider' => 'TEXT',
    'region' => 'TEXT',
    'external_id' => 'TEXT',
    'plan_details' => 'TEXT'
];

foreach ($columnsToAdd as $columnName => $definition) {
    $columnQuery = $db->query("SELECT * FROM pragma_table_info('subscriptions') WHERE name='" . $columnName . "'");
    $columnRequired = $columnQuery->fetchArray(SQLITE3_ASSOC) === false;

    if ($columnRequired) {
        $db->exec("ALTER TABLE subscriptions ADD COLUMN " . $columnName . " " . $definition);
    }
}
