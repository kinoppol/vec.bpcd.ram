<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('CONFIG_FILE', APP_ROOT . '/config/config.php');
define('LOCK_FILE', APP_ROOT . '/storage/installed.lock');

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Bangkok');

require __DIR__ . '/helpers.php';
require __DIR__ . '/Db.php';
require __DIR__ . '/Migrator.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/domain.php';

function app_config(): ?array
{
    static $cfg = false;
    if ($cfg === false) {
        $cfg = is_file(CONFIG_FILE) ? (require CONFIG_FILE) : null;
    }
    return is_array($cfg) ? $cfg : null;
}

function is_installed(): bool
{
    return app_config() !== null && is_file(LOCK_FILE);
}

Auth::start();
