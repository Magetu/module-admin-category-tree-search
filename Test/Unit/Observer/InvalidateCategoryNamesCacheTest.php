<?php

declare(strict_types=1);

namespace Magetu\AdminCategoryTreeSearch\Test\Unit\Observer;

use Magetu\AdminCategoryTreeSearch\Observer\InvalidateCategoryNamesCache;
use Magento\Catalog\Model\Category;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

class InvalidateCategoryNamesCacheTest extends TestCase
{
    /**
     * it cleans the cache by the category cache tag, as a single tags array
     * (CacheInterface::clean() takes one array argument, not the raw Zend_Cache
     * (mode, tags) signature — passing the mode as a first argument is a silent no-op)
     */
    public function testCleansCacheByCategoryTag(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('clean')->with([Category::CACHE_TAG]);

        $observer = new InvalidateCategoryNamesCache($cache);
        $observer->execute($this->createMock(Observer::class));
    }
}
