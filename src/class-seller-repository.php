<?php
declare(strict_types=1);

namespace WfpMultiSeller;

/** Persistence boundary for stable seller identities and append-only payment configurations. */
final class SellerRepository
{
    public static function sellers_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wfpms_sellers';
    }

    public static function configs_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wfpms_seller_configs';
    }

    /** @return list<array<string,mixed>> */
    public static function all(bool $includeArchived = true): array
    {
        global $wpdb;
        $where = $includeArchived ? '' : ' WHERE active=1';
        $rows = $wpdb->get_results('SELECT * FROM ' . self::sellers_table() . $where . ' ORDER BY internal_label,id', ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public static function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::sellers_table() . ' WHERE id=%d', $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function is_active(int $sellerId, bool $forUpdate = false): bool
    {
        global $wpdb;
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT active FROM ' . self::sellers_table() . ' WHERE id=%d' . $suffix,
            $sellerId
        )) === 1;
    }

    public static function current_config(int $sellerId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT c.* FROM ' . self::sellers_table() . ' s INNER JOIN ' . self::configs_table()
                . ' c ON c.id=s.current_config_id WHERE s.id=%d',
            $sellerId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function config(int $configId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::configs_table() . ' WHERE id=%d', $configId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public static function configs_for_seller(int $sellerId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::configs_table() . ' WHERE seller_id=%d ORDER BY version DESC',
            $sellerId
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Save identity and, when changed, append a new immutable payment configuration.
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $configuration
     */
    public static function save(?int $sellerId, array $identity, array $configuration, string $secretCiphertext, int $actorId): int
    {
        global $wpdb;
        $sellers = self::sellers_table();
        $configs = self::configs_table();
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Could not start seller save transaction.');
        }

        try {
            $existing = $sellerId === null
                ? null
                : $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $sellers . ' WHERE id=%d FOR UPDATE', $sellerId), ARRAY_A);
            $existing = is_array($existing) ? $existing : null;
            if ($sellerId !== null && $existing === null) {
                throw new \RuntimeException('Seller not found.');
            }
            if ($existing !== null && !empty($existing['used_at'])
                && ($existing['legal_name'] !== $identity['legal_name'] || $existing['tax_id'] !== $identity['tax_id'])) {
                throw new \RuntimeException('Legal recipient is fixed after the first order. Create a new seller profile.');
            }

            $now = current_time('mysql', true);
            if ($existing === null) {
                $uuid = wp_generate_uuid4();
                $inserted = $wpdb->insert($sellers, [
                    'seller_uuid' => $uuid,
                    'internal_label' => $identity['internal_label'],
                    'legal_name' => $identity['legal_name'],
                    'tax_id' => $identity['tax_id'],
                    'active' => 1,
                    'current_config_id' => 0,
                    'used_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], ['%s', '%s', '%s', '%s', '%d', '%d', null, '%s', '%s']);
                if ($inserted !== 1) {
                    throw new \RuntimeException('Could not create seller profile.');
                }
                $sellerId = (int) $wpdb->insert_id;
                $existing = self::find($sellerId);
            } else {
                $updated = $wpdb->update($sellers, [
                    'internal_label' => $identity['internal_label'],
                    'legal_name' => $identity['legal_name'],
                    'tax_id' => $identity['tax_id'],
                    'updated_at' => $now,
                ], ['id' => $sellerId], ['%s', '%s', '%s', '%s'], ['%d']);
                if ($updated === false) {
                    throw new \RuntimeException('Could not update seller profile.');
                }
            }

            $current = self::current_config($sellerId);
            $same = $current !== null
                && $current['recipient_name'] === $configuration['recipient_name']
                && $current['tax_id'] === $identity['tax_id']
                && $current['iban'] === $configuration['iban']
                && $current['bank_name'] === $configuration['bank_name']
                && $current['purpose_template'] === $configuration['purpose_template']
                && $current['merchant_account'] === $configuration['merchant_account']
                && $current['merchant_domain'] === $configuration['merchant_domain']
                && (int) $current['card_enabled'] === $configuration['card_enabled']
                && (int) $current['iban_enabled'] === $configuration['iban_enabled'];
            $currentCiphertext = $current === null ? '' : (string) $current['secret_ciphertext'];
            $currentSecret = $current === null
                ? null
                : ($currentCiphertext === '' ? '' : SecretStore::decrypt($currentCiphertext));
            $same = $same
                && $currentSecret !== null
                && hash_equals($currentSecret, $configuration['secret_plaintext'])
                && (empty($configuration['operator_checked']) || !empty($current['checked_by']));

            if (!$same) {
                $version = $current === null ? 1 : (int) $current['version'] + 1;
                $inserted = $wpdb->insert($configs, [
                    'seller_id' => $sellerId,
                    'version' => $version,
                    'recipient_name' => $configuration['recipient_name'],
                    'tax_id' => $identity['tax_id'],
                    'iban' => $configuration['iban'],
                    'bank_name' => $configuration['bank_name'],
                    'purpose_template' => $configuration['purpose_template'],
                    'merchant_account' => $configuration['merchant_account'],
                    'merchant_domain' => $configuration['merchant_domain'],
                    'card_enabled' => $configuration['card_enabled'],
                    'iban_enabled' => $configuration['iban_enabled'],
                    'secret_ciphertext' => $secretCiphertext,
                    'checked_by' => !empty($configuration['operator_checked']) ? $actorId : null,
                    'checked_at' => !empty($configuration['operator_checked']) ? $now : null,
                    'created_at' => $now,
                ]);
                if ($inserted !== 1) {
                    throw new \RuntimeException('Could not save seller payment configuration.');
                }
                $configId = (int) $wpdb->insert_id;
                if ($wpdb->update($sellers, ['current_config_id' => $configId], ['id' => $sellerId], ['%d'], ['%d']) === false) {
                    throw new \RuntimeException('Could not activate seller payment configuration.');
                }
            }

            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Could not commit seller profile.');
            }
            return $sellerId;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    public static function archive(int $sellerId): bool
    {
        global $wpdb;
        return $wpdb->update(self::sellers_table(), [
            'active' => 0,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $sellerId], ['%d', '%s'], ['%d']) !== false;
    }

    public static function activate(int $sellerId): bool
    {
        global $wpdb;
        return $wpdb->update(self::sellers_table(), [
            'active' => 1,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $sellerId], ['%d', '%s'], ['%d']) !== false;
    }

    public static function has_category_rule(int $categoryId): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT category_id FROM ' . self::category_rules_table() . ' WHERE category_id=%d', $categoryId
        ));
    }

    public static function category_rules_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wfpms_category_rules';
    }

    /** @return array<int,int> category_id => seller_id */
    public static function category_rules(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT category_id,seller_id FROM ' . self::category_rules_table(), ARRAY_A);
        $rules = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $rules[(int) $row['category_id']] = (int) $row['seller_id'];
        }
        return $rules;
    }

    /** @param array<int,int> $rules */
    public static function replace_category_rules(array $rules): void
    {
        global $wpdb;
        $table = self::category_rules_table();
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Could not start category rule transaction.');
        }
        try {
            if ($wpdb->query('DELETE FROM ' . $table) === false) {
                throw new \RuntimeException('Could not clear category rules.');
            }
            foreach ($rules as $categoryId => $sellerId) {
                if ($wpdb->insert($table, [
                    'category_id' => $categoryId,
                    'seller_id' => $sellerId,
                    'updated_at' => current_time('mysql', true),
                ], ['%d', '%d', '%s']) !== 1) {
                    throw new \RuntimeException('Could not save category rule.');
                }
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Could not commit category rules.');
            }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    public static function mark_used(int $sellerId): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::sellers_table() . ' SET used_at=COALESCE(used_at,%s) WHERE id=%d',
            current_time('mysql', true), $sellerId
        ));
    }
}
