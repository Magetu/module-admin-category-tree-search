<?php

declare(strict_types=1);

namespace Magetu\AdminCategoryTreeSearch\Observer;

use Magento\Catalog\Model\Category;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Invalidates the category-tree-search name cache on category save.
 *
 * Core does NOT do this for us: `AbstractModel::afterSave()` dispatches `clean_cache_by_tags` on
 * every save, but the only observer on that event (`Magento\Theme\Observer\InvalidateLayoutCacheObserver`)
 * cleans the Layout cache type, not the generic `CacheInterface` frontend this module caches under.
 * Category's own direct `$this->_cacheManager->clean([self::CACHE_TAG])` call lives in `move()`
 * only, not in the regular save path — so a plain rename never invalidated this cache until this
 * observer was added.
 */
class InvalidateCategoryNamesCache implements ObserverInterface
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function execute(Observer $observer): void
    {
        $this->cache->clean([Category::CACHE_TAG]);
    }
}
