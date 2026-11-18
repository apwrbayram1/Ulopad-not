<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if ((int)$connect->query("SELECT GET_LOCK('nashenas_platform', 10)")->fetch_row()[0] !== 1) {
    throw new RuntimeException('Platform is busy; retry migration.');
}
try {
    $tables = [
        "CREATE TABLE IF NOT EXISTS bot_feature_orders (
            payload VARCHAR(64) PRIMARY KEY, user_id BIGINT NOT NULL, kind VARCHAR(20) NOT NULL,
            plan VARCHAR(12) NOT NULL DEFAULT '', stars INT NOT NULL, coins INT NOT NULL DEFAULT 0,
            charge_id VARCHAR(200) NULL UNIQUE, status VARCHAR(16) NOT NULL DEFAULT 'pending', created BIGINT NOT NULL,
            INDEX(user_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bot_feature_users (
            user_id BIGINT PRIMARY KEY, premium_until BIGINT NOT NULL DEFAULT 0, created BIGINT NOT NULL,
            INDEX(premium_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bot_feature_claims (
            user_id BIGINT NOT NULL, claim_day DATE NOT NULL, kind VARCHAR(24) NOT NULL, created BIGINT NOT NULL,
            PRIMARY KEY(user_id,claim_day,kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bot_feature_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, kind VARCHAR(32) NOT NULL,
            detail VARCHAR(255) NOT NULL DEFAULT '', created BIGINT NOT NULL, INDEX(user_id,created), INDEX(kind,created)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bot_feature_reports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, report_key CHAR(24) NOT NULL UNIQUE,
            reporter_id BIGINT NOT NULL, target_id BIGINT NOT NULL, session_id BIGINT NULL,
            reason VARCHAR(500) NOT NULL DEFAULT '', status VARCHAR(16) NOT NULL DEFAULT 'open',
            reviewer_id BIGINT NULL, created BIGINT NOT NULL, reviewed_at BIGINT NULL,
            INDEX(status,created), INDEX(target_id,created)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bot_feature_blocks (
            owner_id BIGINT NOT NULL, blocked_id BIGINT NOT NULL, created BIGINT NOT NULL,
            PRIMARY KEY(owner_id,blocked_id), INDEX(blocked_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bot_feature_mutes (
            user_id BIGINT PRIMARY KEY, until_at BIGINT NOT NULL DEFAULT 0, reason VARCHAR(255) NOT NULL DEFAULT '',
            actor BIGINT NOT NULL DEFAULT 0, created BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bot_feature_rate (
            user_id BIGINT PRIMARY KEY, window_start BIGINT NOT NULL, count INT NOT NULL DEFAULT 0,
            last_message BIGINT NOT NULL DEFAULT 0, last_hash CHAR(64) NOT NULL DEFAULT '', repeats INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bot_feature_ton (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, amount DECIMAL(20,9) NOT NULL,
            tx_hash VARCHAR(128) NOT NULL UNIQUE, status VARCHAR(16) NOT NULL DEFAULT 'pending',
            reviewer_id BIGINT NULL, created BIGINT NOT NULL, reviewed_at BIGINT NULL, INDEX(status,created), INDEX(user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
    foreach ($tables as $sql) $connect->query($sql);
    if (!$connect->query("SHOW COLUMNS FROM bot_feature_rate LIKE 'last_hash'")->num_rows) $connect->query("ALTER TABLE bot_feature_rate ADD COLUMN last_hash CHAR(64) NOT NULL DEFAULT ''");
    if (!$connect->query("SHOW COLUMNS FROM bot_feature_rate LIKE 'repeats'")->num_rows) $connect->query("ALTER TABLE bot_feature_rate ADD COLUMN repeats INT NOT NULL DEFAULT 0");
    echo "Feature, wallet, subscription, report and anti-spam tables are ready.\n";
} finally {
    $connect->query("SELECT RELEASE_LOCK('nashenas_platform')");
}
