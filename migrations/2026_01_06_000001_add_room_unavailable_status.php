<?php
return [
    'title' => 'เพิ่มสถานะห้อง "ไม่ว่าง" (ใช้ในภารกิจอื่น) และช่องระบุสาเหตุ (rooms.status_note)',
    'up' => function (PDO $db) {
        $db->exec("ALTER TABLE `rooms` MODIFY `status` ENUM('available','reserved','occupied','maintenance','cleaning','unavailable') NOT NULL DEFAULT 'available'");
        $db->exec('ALTER TABLE `rooms` ADD COLUMN `status_note` VARCHAR(255) NULL AFTER `status`');
    },
    'down' => function (PDO $db) {
        $db->exec("UPDATE `rooms` SET `status`='maintenance' WHERE `status`='unavailable'");
        $db->exec("ALTER TABLE `rooms` MODIFY `status` ENUM('available','reserved','occupied','maintenance','cleaning') NOT NULL DEFAULT 'available'");
        $db->exec('ALTER TABLE `rooms` DROP COLUMN `status_note`');
    },
];
