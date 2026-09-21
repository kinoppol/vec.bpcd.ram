<?php
return [
    'title' => 'เพิ่มตาราง API ผู้ช่วย AI หลายรายการ (ai_profiles) และย้ายค่าเดิมมาเป็นโปรไฟล์แรก',
    'up' => function (PDO $db) {
        $db->exec("CREATE TABLE `ai_profiles` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(80) NOT NULL,
            `provider` VARCHAR(20) NOT NULL DEFAULT 'custom',
            `base_url` VARCHAR(255) NOT NULL,
            `api_key` VARCHAR(500) NOT NULL,
            `model` VARCHAR(150) NOT NULL,
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `sort` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec('ALTER TABLE `ai_log` ADD COLUMN `profile` VARCHAR(80) NULL AFTER `prompt`');

        // ย้ายการตั้งค่า AI เดิม (ถ้ามี) เป็นโปรไฟล์แรก
        $s = $db->query("SELECT k,v FROM settings WHERE k IN ('ai_provider','ai_base_url','ai_key','ai_model')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($s['ai_key']) && !empty($s['ai_base_url'])) {
            $db->prepare('INSERT INTO ai_profiles (name,provider,base_url,api_key,model) VALUES (?,?,?,?,?)')
                ->execute(['API เดิม', $s['ai_provider'] ?? 'custom', $s['ai_base_url'], $s['ai_key'], $s['ai_model'] ?? '']);
            $id = (string)$db->lastInsertId();
            $up = $db->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
            foreach (['ai_default_profile' => $id, 'ai_simple_profile' => $id, 'ai_select_mode' => 'user', 'ai_simple_max' => '30'] as $k => $v) {
                $up->execute([$k, $v]);
            }
        }
        $db->exec("DELETE FROM settings WHERE k IN ('ai_provider','ai_base_url','ai_key','ai_model')");
    },
    'down' => function (PDO $db) {
        // คืนค่าจากโปรไฟล์เริ่มต้นกลับไปเป็นการตั้งค่าเดี่ยวแบบเดิม
        $id = $db->query("SELECT v FROM settings WHERE k='ai_default_profile'")->fetchColumn();
        $p = $id ? $db->query('SELECT * FROM ai_profiles WHERE id=' . (int)$id)->fetch() : null;
        if ($p) {
            $up = $db->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
            foreach (['ai_provider' => $p['provider'], 'ai_base_url' => $p['base_url'], 'ai_key' => $p['api_key'], 'ai_model' => $p['model']] as $k => $v) {
                $up->execute([$k, $v]);
            }
        }
        $db->exec("DELETE FROM settings WHERE k IN ('ai_default_profile','ai_simple_profile','ai_select_mode','ai_simple_max')");
        $db->exec('DROP TABLE IF EXISTS `ai_profiles`');
        $db->exec('ALTER TABLE `ai_log` DROP COLUMN `profile`');
    },
];
