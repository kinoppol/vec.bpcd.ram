<?php
return [
    'title' => 'สร้างตารางโครงการ/คำขอจอง ผู้เข้าพัก และบันทึกการใช้งาน (projects, guests, audit_log)',
    'up' => function (PDO $db) {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $db->exec("CREATE TABLE `projects` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `booking_no` VARCHAR(30) NULL UNIQUE,
            `name` VARCHAR(255) NOT NULL,
            `owner_id` INT UNSIGNED NOT NULL,
            `start_date` DATE NOT NULL,
            `end_date` DATE NOT NULL,
            `meeting_room_id` INT UNSIGNED NULL,
            `male` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `female` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `rooms_trainee` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `rooms_speaker` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `rooms_committee` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `note` VARCHAR(500) NULL,
            `status` ENUM('pending','checking','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            `status_note` VARCHAR(255) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY (`start_date`,`end_date`),
            CONSTRAINT `fk_proj_owner` FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`),
            CONSTRAINT `fk_proj_mroom` FOREIGN KEY (`meeting_room_id`) REFERENCES `rooms`(`id`) ON DELETE SET NULL
        ) $t");
        $db->exec("CREATE TABLE `guests` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `project_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(150) NOT NULL,
            `gender` ENUM('M','F') NOT NULL DEFAULT 'M',
            `type` ENUM('trainee','speaker','committee','exec','other') NOT NULL DEFAULT 'trainee',
            `id_card` VARCHAR(20) NULL,
            `room_requested` VARCHAR(30) NULL,
            `room_id` INT UNSIGNED NULL,
            `note` VARCHAR(255) NULL,
            `status` ENUM('expected','checked_in','checked_out','no_show') NOT NULL DEFAULT 'expected',
            `key_status` ENUM('none','issued','returned') NOT NULL DEFAULT 'none',
            `checkin_at` DATETIME NULL,
            `checkout_at` DATETIME NULL,
            KEY (`project_id`),
            CONSTRAINT `fk_g_proj` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_g_room` FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE SET NULL
        ) $t");
        $db->exec("CREATE TABLE `audit_log` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NULL,
            `action` VARCHAR(255) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) $t");
    },
    'down' => function (PDO $db) {
        foreach (['audit_log', 'guests', 'projects'] as $t) {
            $db->exec("DROP TABLE IF EXISTS `$t`");
        }
    },
];
