<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Model;

/**
 * Pure, dependency-free request-path exclusion matcher. A prefix matches on
 * full path segments only: prefix "checkout" excludes "/checkout" and
 * "/checkout/cart" but NOT "/checkout-guide" (which is a legitimate CMS/blog
 * URL a merchant would want optimized).
 */
class PathMatcher
{
    /**
     * @param string $pathInfo Raw request path info (e.g. "/checkout/cart/")
     * @param string[] $prefixes Normalized prefixes (lowercase, no surrounding slashes)
     * @return bool
     */
    public static function isExcluded(string $pathInfo, array $prefixes): bool
    {
        $path = strtolower(trim($pathInfo, '/'));
        foreach ($prefixes as $prefix) {
            if ($prefix === '') {
                continue;
            }
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                return true;
            }
        }
        return false;
    }
}
