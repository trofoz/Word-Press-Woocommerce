<?php
declare(strict_types=1);

namespace WfpMultiSeller;

final class Installer
{
    public const SCHEMA_VERSION = 1;

    public static function activate(): void
    {
        self::install_schema();
    }

    public static function maybe_upgrade(): void
    {
        if ((int) get_option('wfpms_schema_version', 0) < self::SCHEMA_VERSION) {
            self::install_schema();
        }
    }

    private static function install_schema(): void
    {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->prefix)) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collation = $wpdb->get_charset_collate();
        $sellers = SellerRepository::sellers_table();
        $configs = SellerRepository::configs_table();
        $rules = SellerRepository::category_rules_table();

        dbDelta("CREATE TABLE {$sellers} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            seller_uuid char(36) NOT NULL,
            internal_label varchar(191) NOT NULL,
            legal_name varchar(255) NOT NULL,
            tax_id varchar(64) NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            current_config_id bigint(20) unsigned NOT NULL DEFAULT 0,
            used_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY seller_uuid (seller_uuid),
            KEY active_label (active,internal_label)
        ) ENGINE=InnoDB {$collation};");

        dbDelta("CREATE TABLE {$configs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            seller_id bigint(20) unsigned NOT NULL,
            version int(10) unsigned NOT NULL,
            recipient_name varchar(255) NOT NULL,
            tax_id varchar(64) NOT NULL,
            iban varchar(34) NOT NULL DEFAULT '',
            bank_name varchar(191) NOT NULL DEFAULT '',
            purpose_template varchar(255) NOT NULL DEFAULT 'Оплата за замовлення №{order_number}',
            merchant_account varchar(191) NOT NULL DEFAULT '',
            merchant_domain varchar(191) NOT NULL DEFAULT '',
            card_enabled tinyint(1) NOT NULL DEFAULT 0,
            iban_enabled tinyint(1) NOT NULL DEFAULT 0,
            secret_ciphertext text NOT NULL,
            checked_by bigint(20) unsigned DEFAULT NULL,
            checked_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY seller_version (seller_id,version),
            KEY seller_id (seller_id)
        ) ENGINE=InnoDB {$collation};");

        dbDelta("CREATE TABLE {$rules} (
            category_id bigint(20) unsigned NOT NULL,
            seller_id bigint(20) unsigned NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (category_id),
            KEY seller_id (seller_id)
        ) ENGINE=InnoDB {$collation};");

        foreach ([$sellers, $configs, $rules] as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($found !== $table) {
                return;
            }
        }

        update_option('wfpms_schema_version', self::SCHEMA_VERSION, false);
    }
}
