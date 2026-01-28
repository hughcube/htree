<?php

namespace HughCube\HTree\Tests;

use HughCube\HTree\Exceptions\UnableBuildTreeException;
use HughCube\HTree\HTree;
use HughCube\HTree\Index;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class TreeTest extends TestCase
{
    public function testBadItems()
    {
        /** @var UnableBuildTreeException $exception */
        $exception = null;

        try {
            HTree::instance($this->getBadItems());
        } catch (\Throwable $exception) {
        }

        $this->assertInstanceOf(UnableBuildTreeException::class, $exception);
        $this->assertTrue(is_array($exception->getBadNodeIds()));
        $this->assertFalse(empty($exception->getBadNodeIds()));
    }

    public function testInstance()
    {
        $tree = $this->createTree();
        $this->assertInstanceOf(HTree::class, $tree);

        $tree2 = $this->createTreeFromIndexes();
        $this->assertInstanceOf(HTree::class, $tree2);
    }

    public function testGetItems()
    {
        $tree = $this->createTree();
        $items = $tree->getItems();
        foreach ($this->getItemKeyById() as $id => $item) {
            $this->assertArrayHasKey($id, $items);
            $this->assertSame($id, $item['id']);
        }
    }

    public function testGetItem()
    {
        $tree = $this->createTree();
        $items = $this->getItemKeyById();
        foreach ($items as $id => $item) {
            $treeItem = $tree->getItem($id);
            $this->assertSame($item['id'], $this->getNodeId($treeItem));
        }
        $this->assertSame($tree->getItem($this->randNonExistId()), null);
    }

    public function testHasItem()
    {
        $tree = $this->createTree();
        $items = $this->getItemKeyById();

        foreach ($items as $id => $item) {
            $this->assertTrue($tree->hasItem($id));
        }

        $this->assertFalse($tree->hasItem($this->randNonExistId()));
    }

    public function testAddNode()
    {
        $tree = $this->createTree();
        foreach ($this->getExistIds() as $pid) {
            $id = $this->randNonExistId();
            $tree->addNode(['id' => $id, 'parent' => $pid]);

            $pIndex = $tree->getIndex($pid);
            $index = $tree->getIndex($id);

            $this->assertInstanceOf(Index::class, $pIndex);
            $this->assertInstanceOf(Index::class, $index);

            $this->assertSame($index->level, ($pIndex->level + 1));
            $this->assertLessThan($index->left, $pIndex->left);
            $this->assertGreaterThan($index->right, $pIndex->right);
        }
    }

    /**
     * 测试添加多个节点后不会产生重复子节点
     */
    public function testAddNodeNoDuplicateChildren()
    {
        $tree = $this->createTree();
        $pid = 1;

        // 添加多个子节点
        $id1 = $this->randNonExistId();
        $id2 = $this->randNonExistId();
        $tree->addNode(['id' => $id1, 'parent' => $pid]);
        $tree->addNode(['id' => $id2, 'parent' => $pid]);

        // 检查父节点的children数组中没有重复
        $parentIndex = $tree->getIndex($pid);
        $childIds = array_map(function ($child) {
            return $child->id;
        }, $parentIndex->children);

        // 检查没有重复
        $this->assertSame(count($childIds), count(array_unique($childIds)));
    }

    public function testGetChildren()
    {
        $tree = $this->createTree();

        // 测试获取节点1的子节点
        $children = $tree->getChildren(1, false, true);
        $this->assertContains(2, $children);
        $this->assertContains(8, $children);
        $this->assertContains(19, $children);

        // 测试获取所有子节点（包括孙节点）
        $allChildren = $tree->getChildren(1, false, true);
        $this->assertNotEmpty($allChildren);

        // 测试 withSelf 参数
        $childrenWithSelf = $tree->getChildren(1, true, true);
        $this->assertContains(1, $childrenWithSelf);

        // 测试不存在的节点
        $emptyChildren = $tree->getChildren($this->randNonExistId(), false, true);
        $this->assertEmpty($emptyChildren);
    }

    public function testGetParent()
    {
        $tree = $this->createTree();

        // 测试获取父节点
        $parent = $tree->getParent(2);
        $this->assertNotNull($parent);
        $this->assertSame(1, $this->getNodeId($parent));

        // 测试获取指定层级的父节点
        $parent = $tree->getParent(5, false, 1);
        $this->assertNotNull($parent);
        $this->assertSame(1, $this->getNodeId($parent));

        // 测试只返回id
        $parentId = $tree->getParent(2, true);
        $this->assertSame(1, $parentId);

        // 测试根节点没有父节点
        $rootParent = $tree->getParent(1);
        $this->assertNull($rootParent);

        // 测试不存在的节点
        $nullParent = $tree->getParent($this->randNonExistId());
        $this->assertNull($nullParent);
    }

    public function testGetParents()
    {
        $tree = $this->createTree();

        // 测试获取所有父节点
        $parents = $tree->getParents(5, false, true);
        $this->assertContains(4, $parents);
        $this->assertContains(3, $parents);
        $this->assertContains(2, $parents);
        $this->assertContains(1, $parents);

        // 测试 withSelf 参数
        $parentsWithSelf = $tree->getParents(5, true, true);
        $this->assertContains(5, $parentsWithSelf);

        // 测试 startLevel 和 endLevel 参数
        $filteredParents = $tree->getParents(5, false, true, 1, 2);
        $this->assertContains(1, $filteredParents);
        $this->assertContains(2, $filteredParents);
        $this->assertNotContains(3, $filteredParents);

        // 测试不存在的节点
        $emptyParents = $tree->getParents($this->randNonExistId(), false, true);
        $this->assertEmpty($emptyParents);
    }

    public function testTreeSort()
    {
        $tree = $this->createTree();

        // 测试返回新实例
        $sortedTree = $tree->treeSort(function ($node) {
            return str_pad((string)$this->getNodeId($node), 10, '0', STR_PAD_LEFT);
        });
        $this->assertNotSame($tree, $sortedTree);
        $this->assertInstanceOf(HTree::class, $sortedTree);

        // 测试排序不影响原始树
        $originalItems = $tree->getItems();
        $tree->treeSort(function ($node) {
            return str_pad((string)$this->getNodeId($node), 10, '0', STR_PAD_LEFT);
        });
        $this->assertSame($originalItems, $tree->getItems());
    }

    /**
     * 测试 treeSort 的浅拷贝问题是否已修复
     */
    public function testTreeSortDeepClone()
    {
        $tree = $this->createTree();
        $originalIndex = $tree->getIndex(1);
        $originalChildrenCount = count($originalIndex->children);

        // 执行排序
        $sortedTree = $tree->treeSort(function ($node) {
            return str_pad((string)$this->getNodeId($node), 10, '0', STR_PAD_LEFT);
        });

        // 原始树的 Index 对象不应该被修改
        $this->assertSame($originalChildrenCount, count($tree->getIndex(1)->children));
    }

    public function testTreeMap()
    {
        $tree = $this->createTree();

        // 测试返回新实例
        $mappedTree = $tree->treeMap(function ($node) {
            if (is_array($node)) {
                $node['mapped'] = true;
            }
            return $node;
        });
        $this->assertNotSame($tree, $mappedTree);
        $this->assertInstanceOf(HTree::class, $mappedTree);

        // 测试映射不影响原始树
        $originalItem = $tree->getItem(1);
        $tree->treeMap(function ($node) {
            if (is_array($node)) {
                $node['changed'] = true;
            }
            return $node;
        });
        $this->assertSame($originalItem, $tree->getItem(1));
    }

    public function testGetTree()
    {
        $tree = $this->createTree();

        // 测试基本树形结构
        $treeData = $tree->getTree('children');
        $this->assertIsArray($treeData);
        $this->assertNotEmpty($treeData);

        // 测试树的第一层
        $rootNode = $treeData[0];
        $this->assertArrayHasKey('children', $rootNode);

        // 测试 format 参数
        $formattedTree = $tree->getTree('children', function ($node) {
            return [
                'id' => is_array($node) ? $node['id'] : $node->id,
                'parent' => is_array($node) ? $node['parent'] : $node->parent,
            ];
        });
        $this->assertIsArray($formattedTree);

        // 测试 keepEmptyChildrenKey 参数
        $treeWithEmpty = $tree->getTree('children', null, true);
        $treeWithoutEmpty = $tree->getTree('children', null, false);
        $this->assertIsArray($treeWithEmpty);
        $this->assertIsArray($treeWithoutEmpty);
    }

    public function testGetIndex()
    {
        $tree = $this->createTree();
        $items = $this->getItemKeyById();

        foreach ($tree->getItems() as $id => $item) {
            $index = $tree->getIndex($id);
            $this->autoAssertObjectHasAttribute('id', $index);
            $this->assertTrue(isset($items[$index->id]));

            $this->autoAssertObjectHasAttribute('level', $index);
            $this->assertTrue(is_int($index->level));

            $this->autoAssertObjectHasAttribute('left', $index);
            $this->assertTrue(is_int($index->left));

            $this->autoAssertObjectHasAttribute('right', $index);
            $this->assertTrue(is_int($index->right));

            $this->autoAssertObjectHasAttribute('parent', $index);
            $this->assertTrue(
                null == $index->parent || isset($items[$index->parent])
            );
        }
    }

    public function testGetIndexes()
    {
        $tree = $this->createTree();
        $indexes = $tree->getIndexes();
        $this->assertIsArray($indexes);
        $this->assertNotEmpty($indexes);

        foreach ($indexes as $id => $index) {
            $this->assertInstanceOf(Index::class, $index);
            $this->assertSame($id, $index->id);
        }
    }

    /**
     * 测试嵌套集合模型的完整性
     */
    public function testNestedSetIntegrity()
    {
        $tree = $this->createTree();
        $indexes = $tree->getIndexes();

        foreach ($indexes as $index) {
            // left 应该小于 right
            $this->assertLessThan($index->right, $index->left);

            // 如果有父节点，子节点的 left/right 应该在父节点范围内
            if ($index->parent && isset($indexes[$index->parent])) {
                $parentIndex = $indexes[$index->parent];
                $this->assertGreaterThan($parentIndex->left, $index->left);
                $this->assertLessThan($parentIndex->right, $index->right);
            }
        }
    }

    /**
     * 测试使用 Index 对象创建树
     */
    public function testCreateTreeFromIndexes()
    {
        $tree = $this->createTreeFromIndexes();
        $this->assertInstanceOf(HTree::class, $tree);

        $items = $tree->getItems();
        $this->assertNotEmpty($items);

        foreach ($items as $id => $item) {
            $this->assertInstanceOf(Index::class, $item);
        }
    }

    protected function createTree()
    {
        return HTree::instance($this->getItems(), 'id', 'parent');
    }

    protected function createTreeFromIndexes()
    {
        return HTree::instance(HTree::instance($this->getItems())->getIndexes());
    }

    protected function getExistIds()
    {
        return array_column($this->getItems(), 'id');
    }

    protected function randNonExistId()
    {
        return md5(serialize([microtime(), rand(1, 99999)]));
    }

    protected function getBadItems()
    {
        return [
            /* */ ['id' => 1, 'parent' => 2],
            /* */ /* */ ['id' => 2, 'parent' => 1],
        ];
    }

    protected function getItemKeyById()
    {
        $items = [];

        foreach ($this->getItems() as $item) {
            $items[$item['id']] = $item;
        }

        return $items;
    }

    /**
     * 获取节点的 id，兼容数组和对象
     *
     * @param mixed $node
     * @return mixed
     */
    protected function getNodeId($node)
    {
        if (is_array($node)) {
            return $node['id'];
        }
        return $node->id;
    }

    protected function getItems()
    {
        return [
            /* */ ['id' => 1, 'parent' => 0],
            /* */ /* */ ['id' => 2, 'parent' => 1],
            /* */ /* */ /* */ ['id' => 3, 'parent' => 2],
            /* */ /* */ /* */ /* */ ['id' => 4, 'parent' => 3],
            /* */ /* */ /* */ /* */ /* */ ['id' => 5, 'parent' => 4],
            /* */ /* */ /* */ /* */ /* */ ['id' => 6, 'parent' => 4],
            /* */ /* */ /* */ /* */ /* */ ['id' => 7, 'parent' => 4],
            /* */ /* */ ['id' => 8, 'parent' => 1],
            /* */ /* */ /* */ ['id' => 9, 'parent' => 8],
            /* */ /* */ /* */ /* */ ['id' => 14, 'parent' => 9],
            /* */ /* */ /* */ /* */ ['id' => 15, 'parent' => 9],
            /* */ /* */ /* */ /* */ ['id' => 16, 'parent' => 9],
            /* */ /* */ /* */ /* */ ['id' => 17, 'parent' => 9],
            /* */ /* */ /* */ /* */ ['id' => 18, 'parent' => 9],
            /* */ /* */ /* */ ['id' => 10, 'parent' => 8],
            /* */ /* */ /* */ ['id' => 11, 'parent' => 8],
            /* */ /* */ /* */ ['id' => 12, 'parent' => 8],
            /* */ /* */ /* */ ['id' => 13, 'parent' => 8],
            /* */ /* */ ['id' => 19, 'parent' => 1],
            /* */ /* */ ['id' => 20, 'parent' => 1],
            /* */ /* */ ['id' => 21, 'parent' => 1],
            /* */ /* */ ['id' => 22, 'parent' => 1],
            /* */ /* */ ['id' => 23, 'parent' => 1],
            /* */ /* */ ['id' => 24, 'parent' => 1],
        ];
    }

    public static function autoAssertObjectHasAttribute($name, $object, $message = '')
    {
        if (method_exists(static::class, 'assertObjectHasAttribute')) {
            static::assertObjectHasAttribute($name, $object, $message);
        }

        if (!is_object($object)) {
            static::assertTrue(false);
        }

        $reflection = new ReflectionClass($object);
        static::assertTrue($reflection->hasProperty($name));
    }
}
