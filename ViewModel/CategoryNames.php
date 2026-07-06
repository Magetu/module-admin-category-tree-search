<?php

declare(strict_types=1);

namespace Magetu\AdminCategoryTreeSearch\ViewModel;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Supplies the admin category-tree search filter with a clean {id: name} map of every category.
 *
 * The filter runs entirely client-side against jstree's already-loaded model (the tree ships
 * inline — Tree block sets useAjax=0), so it only needs a lookup of raw names to match against.
 * We build that map here rather than parsing it out of the rendered node labels, which keeps
 * matching precise and independent of the label format (e.g. Magetu_AdminCategoryTreeLabel's
 * "id — name" reorder). Names are read at the tree's current store scope so they match what the
 * admin sees when a store view is selected.
 *
 * Built as a single two-column fetchPairs query (no model hydration — a large catalog would pay
 * memory and hydration time for models we only read two fields from), cached per store view and
 * invalidated by the category cache tag. Core does NOT clean that tag on a plain category save
 * (only on move) — this module's InvalidateCategoryNamesCache observer does it.
 */
class CategoryNames implements ArgumentInterface
{
    private const CACHE_KEY_PREFIX = 'magetu_cts_names_';

    /**
     * Belt-and-braces expiry for writes that bypass the model layer (direct-SQL imports);
     * normal admin saves invalidate via the cat_c tag immediately.
     */
    private const CACHE_TTL = 86400;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
        private readonly MetadataPool $metadataPool,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * Map of category id => name for the tree's current store view.
     *
     * @return array<int, string>
     * @throws \Exception
     */
    public function getCategoryNames(): array
    {
        $storeId = (int) $this->request->getParam('store', 0);
        $cacheKey = self::CACHE_KEY_PREFIX . $storeId;

        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            return $this->serializer->unserialize($cached);
        }

        $map = $this->fetchNames($storeId);
        $this->cache->save(
            $this->serializer->serialize($map),
            $cacheKey,
            [Category::CACHE_TAG],
            self::CACHE_TTL
        );

        return $map;
    }

    /**
     * One two-column query: entity id + name resolved at store scope (store row falls back to
     * the store-0 default row). Joins on the entity link field so the shape holds on both
     * editions; on Adobe Commerce with Content Staging a staged version row can win the pair —
     * add created_in/updated_in filtering if this ever targets a staging-heavy install.
     *
     * @return array<int, string>
     * @throws \Exception
     */
    private function fetchNames(int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $attribute = $this->eavConfig->getAttribute(Category::ENTITY, 'name');
        $linkField = $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();
        $attributeId = (int) $attribute->getId();

        $select = $connection->select()
            ->from(
                ['e' => $this->resourceConnection->getTableName('catalog_category_entity')],
                ['entity_id']
            )
            ->joinLeft(
                ['d' => $attribute->getBackendTable()],
                sprintf('d.%1$s = e.%1$s AND d.attribute_id = %2$d AND d.store_id = 0', $linkField, $attributeId),
                []
            )
            ->joinLeft(
                ['s' => $attribute->getBackendTable()],
                sprintf(
                    's.%1$s = e.%1$s AND s.attribute_id = %2$d AND s.store_id = %3$d',
                    $linkField,
                    $attributeId,
                    $storeId
                ),
                []
            )
            ->columns(['name' => $connection->getIfNullSql('s.value', 'd.value')]);

        return $connection->fetchPairs($select);
    }
}
