<?php
declare(strict_types=1);

namespace WfpMultiSeller;

final class Installer
{
    public const SCHEMA_VERSION = 5;

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
        $purchases = PurchaseRepository::purchases_table();
        $attempts = PurchaseRepository::attempts_table();
        $receipts = PurchaseRepository::receipts_table();
        $events = PurchaseRepository::events_table();
        $legacyMigrations = LegacyMigration::migrations_table();

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

        dbDelta("CREATE TABLE {$purchases} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            seller_id bigint(20) unsigned NOT NULL,
            config_id bigint(20) unsigned NOT NULL,
            financial_snapshot longtext NOT NULL,
            snapshot_hash char(64) NOT NULL,
            amount decimal(20,6) NOT NULL,
            currency char(3) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'ready',
            locked_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            accepted_receipt_id bigint(20) unsigned NOT NULL DEFAULT 0,
            free_basis longtext NULL,
            execution_state varchar(24) NOT NULL DEFAULT 'none',
            execution_token char(36) NOT NULL DEFAULT '',
            execution_started_at datetime DEFAULT NULL,
            execution_updated_at datetime DEFAULT NULL,
            execution_error_code varchar(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            KEY seller_state (seller_id,state)
        ) ENGINE=InnoDB {$collation};");

        dbDelta("CREATE TABLE {$attempts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            purchase_id bigint(20) unsigned NOT NULL,
            channel varchar(16) NOT NULL,
            merchant_account varchar(191) NOT NULL,
            external_reference varchar(191) NOT NULL,
            config_id bigint(20) unsigned NOT NULL,
            credential_key_id varchar(32) NOT NULL DEFAULT '',
            expected_amount decimal(20,6) NOT NULL,
            amount_scale tinyint(3) unsigned NOT NULL DEFAULT 2,
            currency char(3) NOT NULL,
            payload longtext NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'issued',
            issued_at datetime NOT NULL,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY external_reference (external_reference),
            KEY purchase_status (purchase_id,status)
        ) ENGINE=InnoDB {$collation};");

        dbDelta("CREATE TABLE {$receipts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            purchase_id bigint(20) unsigned NOT NULL,
            attempt_id bigint(20) unsigned NOT NULL,
            source varchar(16) NOT NULL,
            source_scope varchar(191) NOT NULL,
            external_id varchar(191) NOT NULL,
            provider_reference varchar(191) NOT NULL DEFAULT '',
            amount decimal(20,6) NOT NULL,
            amount_raw varchar(191) NOT NULL,
            currency char(3) NOT NULL,
            transaction_status varchar(64) NOT NULL,
            classification varchar(32) NOT NULL,
            actual_recipient varchar(191) NOT NULL DEFAULT '',
            occurred_at datetime DEFAULT NULL,
            time_evidence varchar(24) NOT NULL DEFAULT 'provider',
            recorded_by bigint(20) unsigned DEFAULT NULL,
            first_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
            recorded_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_identity (source,source_scope,external_id),
            KEY purchase_classification (purchase_id,classification)
        ) ENGINE=InnoDB {$collation};");

        dbDelta("CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            purchase_id bigint(20) unsigned NOT NULL,
            attempt_id bigint(20) unsigned NOT NULL,
            receipt_id bigint(20) unsigned NOT NULL DEFAULT 0,
            dedupe_key char(64) NOT NULL,
            kind varchar(32) NOT NULL,
            actor_id bigint(20) unsigned DEFAULT NULL,
            reason varchar(500) NOT NULL DEFAULT '',
            related_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
            evidence longtext NOT NULL,
            received_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY dedupe_key (dedupe_key),
            KEY purchase_kind (purchase_id,kind)
        ) ENGINE=InnoDB {$collation};");

        dbDelta("CREATE TABLE {$legacyMigrations} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            purchase_id bigint(20) unsigned NOT NULL,
            seller_id bigint(20) unsigned NOT NULL,
            config_id bigint(20) unsigned NOT NULL,
            migrated_by bigint(20) unsigned NOT NULL,
            migrated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            UNIQUE KEY purchase_id (purchase_id),
            KEY seller_id (seller_id)
        ) ENGINE=InnoDB {$collation};");

        foreach ([$sellers, $configs, $rules, $purchases, $attempts, $receipts, $events, $legacyMigrations] as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($found !== $table) {
                return;
            }
        }

        if (!self::upgrade_provider_reference($receipts)) {
            return;
        }

        update_option('wfpms_schema_version', self::SCHEMA_VERSION, false);
    }

    private static function upgrade_provider_reference(string $receipts): bool
    {
        global $wpdb;
        if ($wpdb->query(
            "UPDATE {$receipts} SET provider_reference=external_id WHERE source='wayforpay' AND provider_reference=''"
        ) === false) {
            return false;
        }
        return true;
    }
}
