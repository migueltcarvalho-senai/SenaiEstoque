<?php
/**
 * Instalação e migração automática do banco de dados
 * Sistema de Gestão de Estoque e Endereçamento 2D - SENAI
 *
 * Chamado pela tela de login (por onde todo usuário passa). Em um banco vazio,
 * cria todas as tabelas — não é preciso importar o database.sql na hospedagem.
 * Em bancos antigos, aplica as colunas/índices que faltam. É seguro rodar sempre.
 */

require_once __DIR__ . '/config.php';

const SCHEMA_TABLES = [
    'products' => "
        CREATE TABLE IF NOT EXISTS `products` (
            `id` VARCHAR(64) NOT NULL PRIMARY KEY,
            `sku` VARCHAR(32) NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `category` VARCHAR(64) NOT NULL,
            `unit` VARCHAR(16) NOT NULL DEFAULT 'un',
            `min_stock` INT NOT NULL DEFAULT 5,
            `max_stock` INT NULL DEFAULT NULL,
            `default_expiry` VARCHAR(10) NULL DEFAULT NULL,
            `storage` VARCHAR(128) NULL DEFAULT NULL,
            `flag_perecivel` TINYINT(1) NOT NULL DEFAULT 0,
            `flag_epi` TINYINT(1) NOT NULL DEFAULT 0,
            `flag_controlado` TINYINT(1) NOT NULL DEFAULT 0,
            `photo` TEXT NULL,
            `observations` TEXT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_products_sku` (`sku`),
            INDEX `idx_category` (`category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'slots' => "
        CREATE TABLE IF NOT EXISTS `slots` (
            `slot_code` VARCHAR(16) NOT NULL PRIMARY KEY,
            `product_id` VARCHAR(64) NOT NULL,
            `qty` INT NOT NULL DEFAULT 0,
            `entry_date` VARCHAR(10) NULL DEFAULT NULL,
            `expiry_date` VARCHAR(10) NULL DEFAULT NULL,
            `lot` VARCHAR(64) NULL DEFAULT NULL,
            `observations` TEXT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'unallocated_lots' => "
        CREATE TABLE IF NOT EXISTS `unallocated_lots` (
            `id` VARCHAR(64) NOT NULL PRIMARY KEY,
            `product_id` VARCHAR(64) NOT NULL,
            `qty` INT NOT NULL DEFAULT 0,
            `entry_date` VARCHAR(10) NULL DEFAULT NULL,
            `expiry_date` VARCHAR(10) NULL DEFAULT NULL,
            `observations` TEXT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_unalloc_prod` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'custom_slots' => "
        CREATE TABLE IF NOT EXISTS `custom_slots` (
            `code` VARCHAR(16) NOT NULL PRIMARY KEY,
            `label` VARCHAR(128) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'history' => "
        CREATE TABLE IF NOT EXISTS `history` (
            `id` VARCHAR(64) NOT NULL PRIMARY KEY,
            `timestamp` VARCHAR(32) NOT NULL,
            `type` VARCHAR(32) NOT NULL,
            `product_name` VARCHAR(255) NOT NULL,
            `slot_code` VARCHAR(64) NOT NULL,
            `qty_text` VARCHAR(64) NOT NULL,
            `note` TEXT NULL,
            `user_id` INT UNSIGNED NULL DEFAULT NULL,
            `username` VARCHAR(32) NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_hist_type` (`type`),
            INDEX `idx_hist_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'users' => "
        CREATE TABLE IF NOT EXISTS `users` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(32) NOT NULL,
            `display_name` VARCHAR(100) NOT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `role` ENUM('admin','operador') NOT NULL DEFAULT 'operador',
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
            `failed_attempts` INT NOT NULL DEFAULT 0,
            `locked_until` DATETIME NULL DEFAULT NULL,
            `last_login_at` DATETIME NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_users_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

function ensureSchema(PDO $pdo): void {
    // Tabelas já existentes: evita rodar DDL à toa a cada acesso
    $existingTables = $pdo->query("
        SELECT `TABLE_NAME` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE()
    ")->fetchAll(PDO::FETCH_COLUMN);
    $existingTables = array_map('strtolower', $existingTables);

    foreach (SCHEMA_TABLES as $table => $ddl) {
        if (!in_array($table, $existingTables, true)) {
            $pdo->exec($ddl);
        }
    }

    // Bancos criados antes do login: colunas de autoria no histórico
    $cols = $pdo->query("
        SELECT `COLUMN_NAME` FROM `information_schema`.`COLUMNS`
         WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'history'
    ")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('user_id', $cols, true)) {
        $pdo->exec("ALTER TABLE `history` ADD COLUMN `user_id` INT UNSIGNED NULL DEFAULT NULL AFTER `note`, ADD INDEX `idx_hist_user` (`user_id`)");
    }
    if (!in_array('username', $cols, true)) {
        $pdo->exec("ALTER TABLE `history` ADD COLUMN `username` VARCHAR(32) NULL DEFAULT NULL AFTER `user_id`");
    }

    // SKU único no banco (garantia final contra duplicidade, além da validação da API)
    $idx = $pdo->query("
        SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
         WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'products' AND `INDEX_NAME` = 'uq_products_sku'
    ")->fetchColumn();
    if ((int)$idx === 0) {
        $dup = $pdo->query("SELECT `sku` FROM `products` GROUP BY `sku` HAVING COUNT(*) > 1 LIMIT 1")->fetchColumn();
        if ($dup === false) {
            $pdo->exec("ALTER TABLE `products` ADD UNIQUE KEY `uq_products_sku` (`sku`)");
        } else {
            // Não bloqueia o sistema: a API continua impedindo novos duplicados.
            error_log("[senai-estoque] Índice único de SKU não criado: existem SKUs duplicados (ex.: {$dup}). Corrija-os e acesse o login novamente.");
        }
    }
}
