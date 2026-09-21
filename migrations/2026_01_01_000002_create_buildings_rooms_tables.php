<?php
return [
    'title' => 'สร้างตารางอาคารและห้องพัก (buildings, rooms)',
    'up' => function (PDO $db) {
        $db->exec("CREATE TABLE `buildings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(20) NOT NULL UNIQUE,
            `name` VARCHAR(120) NOT NULL,
            `floors` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `note` VARCHAR(255) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE `rooms` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `building_id` INT UNSIGNED NOT NULL,
            `room_no` VARCHAR(30) NOT NULL,
            `floor` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `beds` TINYINT UNSIGNED NOT NULL DEFAULT 2,
            `type` ENUM('lodging','meeting') NOT NULL DEFAULT 'lodging',
            `status` ENUM('available','reserved','occupied','maintenance','cleaning') NOT NULL DEFAULT 'available',
            UNIQUE KEY `uq_room` (`building_id`,`room_no`),
            CONSTRAINT `fk_rooms_building` FOREIGN KEY (`building_id`) REFERENCES `buildings`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    },
    'down' => function (PDO $db) {
        $db->exec('DROP TABLE IF EXISTS `rooms`');
        $db->exec('DROP TABLE IF EXISTS `buildings`');
    },
];
