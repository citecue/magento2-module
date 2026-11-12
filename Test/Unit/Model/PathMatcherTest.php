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

    /**
     * @var PathMatcher
     */
    private $matcher;

    protected function setUp(): void
    {
        $this->matcher = new PathMatcher();
    }

    public function testExactSegmentMatches(): void
    {
        $this->assertTrue($this->matcher->isExcluded('/checkout', self::PREFIXES));
        $this->assertTrue($this->matcher->isExcluded('/checkout/', self::PREFIXES));
        $this->assertTrue($this->matcher->isExcluded('/checkout/cart', self::PREFIXES));
        $this->assertTrue($this->matcher->isExcluded('/CHECKOUT/CART/', self::PREFIXES));
        $this->assertTrue($this->matcher->isExcluded('/robots.txt', self::PREFIXES));
    }

    public function testMultiSegmentPrefix(): void
    {
        $this->assertTrue($this->matcher->isExcluded('/review/customer', self::PREFIXES));
        $this->assertTrue($this->matcher->isExcluded('/review/customer/index', self::PREFIXES));
        $this->assertFalse($this->matcher->isExcluded('/review/product/list', self::PREFIXES));
    }

    public function testPartialSegmentDoesNotMatch(): void
    {
        // A CMS page like /checkout-guide must stay eligible for optimization.
        $this->assertFalse($this->matcher->isExcluded('/checkout-guide', self::PREFIXES));
        $this->assertFalse($this->matcher->isExcluded('/customers', self::PREFIXES));
    }

    public function testRootAndUnrelatedPaths(): void
    {
        $this->assertFalse($this->matcher->isExcluded('/', self::PREFIXES));
        $this->assertFalse($this->matcher->isExcluded('/blog/ai-shopping-trends', self::PREFIXES));
        $this->assertFalse($this->matcher->isExcluded('/some-product.html', self::PREFIXES));
    }

    public function testEmptyPrefixListNeverExcludes(): void
    {
        $this->assertFalse($this->matcher->isExcluded('/checkout', []));
        $this->assertFalse($this->matcher->isExcluded('/checkout', ['']));
    }
}
