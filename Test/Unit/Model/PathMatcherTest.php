<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Test\Unit\Model;

use Citecue\Delivery\Model\PathMatcher;
use PHPUnit\Framework\TestCase;

class PathMatcherTest extends TestCase
{
    private const PREFIXES = ['checkout', 'customer', 'review/customer', 'robots.txt'];

    public function testExactSegmentMatches(): void
    {
        $this->assertTrue(PathMatcher::isExcluded('/checkout', self::PREFIXES));
        $this->assertTrue(PathMatcher::isExcluded('/checkout/', self::PREFIXES));
        $this->assertTrue(PathMatcher::isExcluded('/checkout/cart', self::PREFIXES));
        $this->assertTrue(PathMatcher::isExcluded('/CHECKOUT/CART/', self::PREFIXES));
        $this->assertTrue(PathMatcher::isExcluded('/robots.txt', self::PREFIXES));
    }

    public function testMultiSegmentPrefix(): void
    {
        $this->assertTrue(PathMatcher::isExcluded('/review/customer', self::PREFIXES));
        $this->assertTrue(PathMatcher::isExcluded('/review/customer/index', self::PREFIXES));
        $this->assertFalse(PathMatcher::isExcluded('/review/product/list', self::PREFIXES));
    }

    public function testPartialSegmentDoesNotMatch(): void
    {
        // A CMS page like /checkout-guide must stay eligible for optimization.
        $this->assertFalse(PathMatcher::isExcluded('/checkout-guide', self::PREFIXES));
        $this->assertFalse(PathMatcher::isExcluded('/customers', self::PREFIXES));
    }

    public function testRootAndUnrelatedPaths(): void
    {
        $this->assertFalse(PathMatcher::isExcluded('/', self::PREFIXES));
        $this->assertFalse(PathMatcher::isExcluded('/blog/ai-shopping-trends', self::PREFIXES));
        $this->assertFalse(PathMatcher::isExcluded('/some-product.html', self::PREFIXES));
    }

    public function testEmptyPrefixListNeverExcludes(): void
    {
        $this->assertFalse(PathMatcher::isExcluded('/checkout', []));
        $this->assertFalse(PathMatcher::isExcluded('/checkout', ['']));
    }
}
