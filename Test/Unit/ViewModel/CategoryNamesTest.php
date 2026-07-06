<?php

declare(strict_types=1);

namespace Magetu\AdminCategoryTreeSearch\Test\Unit\ViewModel;

use Magetu\AdminCategoryTreeSearch\ViewModel\CategoryNames;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CategoryNamesTest extends TestCase
{
    /** @var ResourceConnection&MockObject */
    private ResourceConnection $resourceConnection;

    /** @var EavConfig&MockObject */
    private EavConfig $eavConfig;

    /** @var MetadataPool&MockObject */
    private MetadataPool $metadataPool;

    /** @var CacheInterface&MockObject */
    private CacheInterface $cache;

    /** @var RequestInterface&MockObject */
    private RequestInterface $request;

    private Json $serializer;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->eavConfig = $this->createMock(EavConfig::class);
        $this->metadataPool = $this->createMock(MetadataPool::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->serializer = new Json();
    }

    private function viewModel(): CategoryNames
    {
        return new CategoryNames(
            $this->resourceConnection,
            $this->eavConfig,
            $this->metadataPool,
            $this->cache,
            $this->serializer,
            $this->request
        );
    }

    /**
     * it returns the cached map for the request store without touching the database
     */
    public function testCacheHitSkipsTheDatabase(): void
    {
        $this->request->method('getParam')->with('store', 0)->willReturn('3');
        $this->cache->method('load')
            ->with('magetu_cts_names_3')
            ->willReturn('{"132":"Orchids","85":"Lily Arrangements"}');
        $this->resourceConnection->expects($this->never())->method('getConnection');

        $this->assertSame(
            [132 => 'Orchids', 85 => 'Lily Arrangements'],
            $this->viewModel()->getCategoryNames()
        );
    }

    /**
     * it builds the map with a single fetchPairs query on a cache miss and caches it
     * under the category tag (no model hydration)
     */
    public function testCacheMissQueriesOncePerPairAndCachesUnderCategoryTag(): void
    {
        $this->request->method('getParam')->with('store', 0)->willReturn(0);
        $this->cache->method('load')->with('magetu_cts_names_0')->willReturn(false);

        $attribute = $this->getMockBuilder(AbstractAttribute::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getBackendTable'])
            ->getMockForAbstractClass();
        $attribute->method('getId')->willReturn(45);
        $attribute->method('getBackendTable')->willReturn('catalog_category_entity_varchar');
        $this->eavConfig->method('getAttribute')
            ->with(Category::ENTITY, 'name')
            ->willReturn($attribute);

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $this->metadataPool->method('getMetadata')
            ->with(CategoryInterface::class)
            ->willReturn($metadata);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->expects($this->exactly(2))->method('joinLeft')->willReturnSelf();
        $select->method('columns')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('getIfNullSql')->willReturn(new \Zend_Db_Expr('IFNULL(s.value, d.value)'));
        $connection->expects($this->once())->method('fetchPairs')->with($select)
            ->willReturn(['132' => 'Orchids']);

        $this->resourceConnection->method('getConnection')->willReturn($connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);

        $this->cache->expects($this->once())->method('save')->with(
            '{"132":"Orchids"}',
            'magetu_cts_names_0',
            [Category::CACHE_TAG],
            86400
        );

        $this->assertSame(['132' => 'Orchids'], $this->viewModel()->getCategoryNames());
    }
}
