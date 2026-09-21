<?php
return [
    'title' => 'เพิ่มอีเมลผู้ใช้ ตารางตั้งค่าระบบ การแจ้งเตือน และบันทึกการใช้ AI',
    'up' => function (PDO $db) {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $db->exec('ALTER TABLE `users` ADD COLUMN `email` VARCHAR(190) NULL AFTER `full_name`');
        $db->exec("CREATE TABLE `settings` (
            `k` VARCHAR(80) NOT NULL PRIMARY KEY,
            `v` TEXT NULL
        ) $t");
        $db->exec("CREATE TABLE `notifications` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `channel` VARCHAR(20) NOT NULL,
            `recipient` VARCHAR(190) NOT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `status` ENUM('sent','failed','skipped') NOT NULL,
            `error` VARCHAR(255) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) $t");
        $db->exec("CREATE TABLE `ai_log` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NULL,
            `prompt` VARCHAR(500) NOT NULL,
            `cost` DECIMAL(8,2) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) $t");
    },
    'down' => function (PDO $db) {
        foreach (['ai_log', 'notifications', 'settings'] as $t) {
            $db->exec("DROP TABLE IF EXISTS `$t`");
        }
        $db->exec('ALTER TABLE `users` DROP COLUMN `email`');
    },
];
