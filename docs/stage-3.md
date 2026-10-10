# Stage 3 — Seller management and product routing

Status: implemented locally on `feature/stage-3-seller-management`.

This stage adds the reusable seller and product-routing foundation to the fork. It does not enable or implement checkout, payment requests, callbacks, order assignment, or payment confirmation.

## Included

- An unlimited number of seller profiles, each with an internal label, legal recipient, tax identifier, and active/archive state.
- Append-only payment configuration versions containing recipient details, IBAN and bank, payment-purpose template, WayForPay merchant account/domain, enabled methods, and operator confirmation metadata.
- Legal recipient name and tax ID become immutable after the first order marks a seller as used. The order integration that calls `SellerRepository::mark_used()` belongs to a later stage.
- Encrypted WayForPay secret storage using AES-256-GCM. The secret is never rendered back into the admin form or stored in plaintext.
- Category-to-seller rules, nearest-ancestor category inheritance, and manual product assignment taking precedence over category rules.
- Variation products resolve through their parent product.
- A paginated product audit showing products for which seller assignment is missing, conflicting, or invalid.
- Capability and nonce checks on administrative writes; archived seller profiles are retained rather than deleted.

## Encryption key setup

For card-enabled profiles, define these constants in `wp-config.php` or an equivalent deployment-level configuration before activating card payments:

```php
define('WFPMS_MASTER_KEY', 'REPLACE_WITH_64_HEX_CHARACTERS_GENERATED_OUTSIDE_WORDPRESS');
define('WFPMS_MASTER_KEY_ID', 'key-2026-01');
```

Generate a unique 32-byte random key with a trusted secret manager or cryptographic random generator. Do not commit the real key, put it in the WordPress database, or paste it into support logs. `WFPMS_MASTER_KEY_ID` is a non-secret identifier stored with ciphertext. Changing either the key or its ID makes existing secrets unavailable; a key-rotation procedure with old-key decryption and re-encryption is not included in this stage. Keep a protected backup of the key alongside the database backup.

IBAN-only configurations do not require OpenSSL or the master key, provided they contain no existing encrypted card secret that needs to be preserved.

## Important behavior

- Selecting the operator-confirmed checkbox records who confirmed the current configuration version and when. It starts unchecked on edits so a changed configuration is not silently treated as verified. This is an administrative assertion, not an automated bank-account verification.
- IBAN validation checks normalized syntax and the ISO 13616 MOD-97 checksum. It does not verify account ownership or bank availability.
- One category may map to one seller. A product whose mapped categories resolve to different sellers is reported as a conflict. A manual product selection overrides category rules.
- Archiving a seller prevents its products from resolving to it for future routing. Existing category rules and manual assignments are retained and shown as assignment problems until corrected.
- `SellerRepository::mark_used()` must be called by the later order-creation stage when the seller is first committed to an order. Until then, legal identity fields are not locked by order history.

## Not included / release gate

This is not a production-ready payment plugin. No gateway is registered, no card or bank-transfer checkout is available, and no order or callback code consumes the seller/configuration versions. Later stages must snapshot seller ID and configuration version onto the order, enforce single-seller carts, implement idempotent payment/callback handling, define retry and late-payment policy, and connect safe fulfillment recovery before live payment testing.

## Verification

PHP syntax was checked with `php -l` for the plugin entry point and each file in `src/`. The WooCommerce admin screens, database installation, browser behavior, and payment flows were not exercised against a running WordPress/WooCommerce instance in this stage.
