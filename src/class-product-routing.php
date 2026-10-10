<?php
declare(strict_types=1);

namespace WfpMultiSeller;

/** Resolves seller ownership from a product override or its effective categories. */
final class ProductRouting
{
    public const META_SELLER_ID = '_wfpms_seller_id';

    /** @return array{ok:bool,seller_id:?int,source:string,reason:string,seller:?array} */
    public static function resolve(int $productId): array
    {
        $product = wc_get_product($productId);
        if (!$product) {
            return self::failure('missing_product');
        }
        if ($product->is_type('variation')) {
            $productId = (int) $product->get_parent_id();
            $product = $productId > 0 ? wc_get_product($productId) : false;
            if (!$product) {
                return self::failure('missing_parent');
            }
        }

        $manualId = (int) get_post_meta($productId, self::META_SELLER_ID, true);
        if ($manualId > 0) {
            $seller = SellerRepository::find($manualId);
            if ($seller === null) {
                return self::failure('unknown_manual_seller');
            }
            if ((int) $seller['active'] !== 1) {
                return self::failure('archived_manual_seller');
            }
            return self::success($manualId, 'manual', $seller);
        }

        $categoryIds = wp_get_object_terms($productId, 'product_cat', ['fields' => 'ids']);
        if (is_wp_error($categoryIds)) {
            return self::failure('category_lookup_failed');
        }
        $rules = SellerRepository::category_rules();
        $resolved = [];
        foreach (array_unique(array_map('intval', $categoryIds)) as $categoryId) {
            foreach (self::ancestors_nearest_first($categoryId) as $ancestorId) {
                if (isset($rules[$ancestorId])) {
                    $resolved[$rules[$ancestorId]] = true;
                    break;
                }
            }
        }

        if (count($resolved) === 0) {
            return self::failure('seller_not_assigned');
        }
        if (count($resolved) > 1) {
            return self::failure('category_seller_conflict');
        }
        $sellerId = (int) array_key_first($resolved);
        $seller = SellerRepository::find($sellerId);
        if ($seller === null) {
            return self::failure('category_rule_unknown_seller');
        }
        if ((int) $seller['active'] !== 1) {
            return self::failure('category_rule_archived_seller');
        }
        return self::success($sellerId, 'category', $seller);
    }

    /** @return list<int> */
    private static function ancestors_nearest_first(int $categoryId): array
    {
        $ids = [$categoryId];
        $parents = get_ancestors($categoryId, 'product_cat', 'taxonomy');
        foreach (is_array($parents) ? $parents : [] as $parentId) {
            $ids[] = (int) $parentId;
        }
        return $ids;
    }

    /** @return array{ok:bool,seller_id:?int,source:string,reason:string,seller:?array} */
    private static function success(int $sellerId, string $source, array $seller): array
    {
        return ['ok' => true, 'seller_id' => $sellerId, 'source' => $source, 'reason' => '', 'seller' => $seller];
    }

    /** @return array{ok:bool,seller_id:null,source:string,reason:string,seller:null} */
    private static function failure(string $reason): array
    {
        return ['ok' => false, 'seller_id' => null, 'source' => '', 'reason' => $reason, 'seller' => null];
    }
}
