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

    public function testGetChildrenByNids()
    {
        $tree = $this->createTree();

        // 批量获取节点 2 和 8 的所有子节点
        $children = $tree->getChildren([2, 8], false, true);
        // 节点2的子孙: 3,4,5,6,7
        $this->assertContains(3, $children);
        $this->assertContains(4, $children);
        $this->assertContains(5, $children);
        $this->assertContains(6, $children);
        $this->assertContains(7, $children);
        // 节点8的子孙: 9,10,11,12,13,14,15,16,17,18
        $this->assertContains(9, $children);
        $this->assertContains(10, $children);
        $this->assertContains(14, $children);
        // 不包含自身
        $this->assertNotContains(2, $children);
        $this->assertNotContains(8, $children);

        // withSelf = true
        $childrenWithSelf = $tree->getChildren([2, 8], true, true);
        $this->assertContains(2, $childrenWithSelf);
        $this->assertContains(8, $childrenWithSelf);
        $this->assertContains(3, $childrenWithSelf);
        $this->assertContains(9, $childrenWithSelf);

        // 空数组
        $empty = $tree->getChildren([], false, true);
        $this->assertEmpty($empty);

        // 不存在的节点
        $empty = $tree->getChildren([$this->randNonExistId()], false, true);
        $this->assertEmpty($empty);

        // onlyId = false 返回节点数据
        $nodesData = $tree->getChildren([2], false, false);
        foreach ($nodesData as $id => $item) {
            $this->assertSame($id, $this->getNodeId($item));
        }

        // 结果去重：两个节点有共同子孙时不重复
        $children1 = $tree->getChildren([1], false, true);
        $children12 = $tree->getChildren([1, 2], false, true);
        $this->assertSame(count($children1), count($children12));
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

    public function testGetParentsByIds()
    {
        $tree = $this->createTree();

        // 批量获取节点 5 和 9 的所有父节点
        $parents = $tree->getParents([5, 9], false, true);
        // 节点5的祖先: 1,2,3,4  节点9的祖先: 1,8
        $this->assertContains(1, $parents);
        $this->assertContains(2, $parents);
        $this->assertContains(3, $parents);
        $this->assertContains(4, $parents);
        $this->assertContains(8, $parents);
        // 不包含自身
        $this->assertNotContains(5, $parents);
        $this->assertNotContains(9, $parents);

        // withSelf = true
        $parentsWithSelf = $tree->getParents([5, 9], true, true);
        $this->assertContains(5, $parentsWithSelf);
        $this->assertContains(9, $parentsWithSelf);
        $this->assertContains(1, $parentsWithSelf);

        // 空数组
        $empty = $tree->getParents([], false, true);
        $this->assertEmpty($empty);

        // 不存在的节点
        $empty = $tree->getParents([$this->randNonExistId()], false, true);
        $this->assertEmpty($empty);

        // onlyId = false 返回节点数据
        $nodesData = $tree->getParents([5], false, false);
        foreach ($nodesData as $id => $item) {
            $this->assertSame($id, $this->getNodeId($item));
        }

        // 结果去重：节点5和节点6有共同祖先时不重复
        $parents5 = $tree->getParents([5], false, true);
        $parents56 = $tree->getParents([5, 6], false, true);
        $this->assertSame(count($parents5), count($parents56));

        // startLevel / endLevel 过滤
        $filtered = $tree->getParents([5, 9], false, true, 1, 2);
        $this->assertContains(1, $filtered);
        $this->assertContains(2, $filtered);
        $this->assertContains(8, $filtered);
        $this->assertNotContains(3, $filtered);
        $this->assertNotContains(4, $filtered);
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
        $this->assertTrue(is_array($treeData));
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
        $this->assertTrue(is_array($formattedTree));

        // 测试 keepEmptyChildrenKey 参数
        $treeWithEmpty = $tree->getTree('children', null, true);
        $treeWithoutEmpty = $tree->getTree('children', null, false);
        $this->assertTrue(is_array($treeWithEmpty));
        $this->assertTrue(is_array($treeWithoutEmpty));
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
        $this->assertTrue(is_array($indexes));
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

    // ==================== fromTree tests ====================

    public function testFromTreeBasic()
    {
        $tree = [
            [
                'id' => 1,
                'name' => 'root',
                'children' => [
                    [
                        'id' => 2,
                        'name' => 'child1',
                        'children' => [
                            ['id' => 4, 'name' => 'grandchild1', 'children' => []],
                            ['id' => 5, 'name' => 'grandchild2'],
                        ]
                    ],
                    ['id' => 3, 'name' => 'child2'],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);
        $this->assertInstanceOf(HTree::class, $hTree);

        // 所有 5 个节点都存在
        $this->assertTrue($hTree->hasItem(1));
        $this->assertTrue($hTree->hasItem(2));
        $this->assertTrue($hTree->hasItem(3));
        $this->assertTrue($hTree->hasItem(4));
        $this->assertTrue($hTree->hasItem(5));
        $this->assertCount(5, $hTree->getItems());

        // 扁平化后节点不应包含 children key
        foreach ($hTree->getItems() as $item) {
            $this->assertArrayNotHasKey('children', $item);
        }
    }

    public function testFromTreeParentRelationships()
    {
        $tree = [
            [
                'id' => 1,
                'name' => 'root',
                'children' => [
                    [
                        'id' => 2,
                        'name' => 'child1',
                        'children' => [
                            ['id' => 4, 'name' => 'grandchild1'],
                        ]
                    ],
                    ['id' => 3, 'name' => 'child2'],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);

        // 根节点没有父节点
        $rootParent = $hTree->getParent(1);
        $this->assertNull($rootParent);

        // child1 的父节点是 root
        $parent = $hTree->getParent(2, true);
        $this->assertSame(1, $parent);

        // child2 的父节点是 root
        $parent = $hTree->getParent(3, true);
        $this->assertSame(1, $parent);

        // grandchild1 的父节点是 child1
        $parent = $hTree->getParent(4, true);
        $this->assertSame(2, $parent);
    }

    public function testFromTreeChildrenRelationships()
    {
        $tree = [
            [
                'id' => 1,
                'name' => 'root',
                'children' => [
                    [
                        'id' => 2,
                        'name' => 'child1',
                        'children' => [
                            ['id' => 4, 'name' => 'gc1'],
                            ['id' => 5, 'name' => 'gc2'],
                        ]
                    ],
                    ['id' => 3, 'name' => 'child2'],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);

        // root 的所有子孙
        $children = $hTree->getChildren(1, false, true);
        $this->assertContains(2, $children);
        $this->assertContains(3, $children);
        $this->assertContains(4, $children);
        $this->assertContains(5, $children);

        // child1 的子节点
        $children = $hTree->getChildren(2, false, true);
        $this->assertContains(4, $children);
        $this->assertContains(5, $children);
        $this->assertNotContains(3, $children);

        // 叶子节点没有子节点
        $children = $hTree->getChildren(5, false, true);
        $this->assertEmpty($children);
    }

    public function testFromTreeGetTreeRoundTrip()
    {
        $tree = [
            [
                'id' => 1,
                'name' => 'root',
                'children' => [
                    [
                        'id' => 2,
                        'name' => 'child1',
                        'children' => [
                            ['id' => 4, 'name' => 'gc1'],
                        ]
                    ],
                    ['id' => 3, 'name' => 'child2'],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);

        // getTree 还原树形结构
        $result = $hTree->getTree('children', function ($item) {
            return ['id' => $item['id'], 'name' => $item['name']];
        });

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertCount(2, $result[0]['children']);

        // 验证 child1 有 1 个子节点
        $child1 = $result[0]['children'][0];
        $this->assertSame(2, $child1['id']);
        $this->assertCount(1, $child1['children']);
        $this->assertSame(4, $child1['children'][0]['id']);

        // 验证 child2 没有子节点
        $child2 = $result[0]['children'][1];
        $this->assertSame(3, $child2['id']);
        $this->assertEmpty($child2['children']);
    }

    public function testFromTreeCustomKeys()
    {
        $tree = [
            [
                'key' => 'root',
                'title' => 'Root',
                'items' => [
                    [
                        'key' => 'a',
                        'title' => 'A',
                        'items' => [
                            [
                                'key' => 'a1',
                                'title' => 'A1'
                            ],
                        ]
                    ],
                    [
                        'key' => 'b',
                        'title' => 'B'
                    ],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree, 'key', 'parent_key', 'items');

        $this->assertTrue($hTree->hasItem('root'));
        $this->assertTrue($hTree->hasItem('a'));
        $this->assertTrue($hTree->hasItem('a1'));
        $this->assertTrue($hTree->hasItem('b'));

        $parent = $hTree->getParent('a1', true);
        $this->assertSame('a', $parent);

        $children = $hTree->getChildren('root', false, true);
        $this->assertContains('a', $children);
        $this->assertContains('b', $children);
    }

    public function testFromTreeEmptyInput()
    {
        $hTree = HTree::fromTree([]);
        $this->assertInstanceOf(HTree::class, $hTree);
        $this->assertEmpty($hTree->getItems());
    }

    public function testFromTreeMultipleRoots()
    {
        $tree = [
            [
                'id' => 1,
                'name' => 'root1',
                'children' => [
                    ['id' => 3, 'name' => 'child1'],
                ]
            ],
            [
                'id' => 2,
                'name' => 'root2',
                'children' => [
                    ['id' => 4, 'name' => 'child2'],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);
        $this->assertCount(4, $hTree->getItems());

        $parent3 = $hTree->getParent(3, true);
        $this->assertSame(1, $parent3);

        $parent4 = $hTree->getParent(4, true);
        $this->assertSame(2, $parent4);
    }

    /**
     * 测试单个根节点无子节点
     */
    public function testFromTreeSingleNodeNoChildren()
    {
        $tree = [
            ['id' => 1, 'name' => 'lonely'],
        ];

        $hTree = HTree::fromTree($tree);
        $this->assertCount(1, $hTree->getItems());
        $this->assertTrue($hTree->hasItem(1));
        $this->assertNull($hTree->getParent(1));
        $this->assertEmpty($hTree->getChildren(1, false, true));
    }

    /**
     * 测试深层嵌套（4+ 层）及 level 正确性
     */
    public function testFromTreeDeepNesting()
    {
        $tree = [
            [
                'id' => 1,
                'children' => [
                    [
                        'id' => 2,
                        'children' => [
                            [
                                'id' => 3,
                                'children' => [
                                    [
                                        'id' => 4,
                                        'children' => [
                                            ['id' => 5],
                                        ]
                                    ],
                                ]
                            ],
                        ]
                    ],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);
        $this->assertCount(5, $hTree->getItems());

        // 验证每层 level 递增
        $this->assertSame(1, $hTree->getIndex(1)->level);
        $this->assertSame(2, $hTree->getIndex(2)->level);
        $this->assertSame(3, $hTree->getIndex(3)->level);
        $this->assertSame(4, $hTree->getIndex(4)->level);
        $this->assertSame(5, $hTree->getIndex(5)->level);

        // 验证祖先链
        $parents = $hTree->getParents(5, false, true);
        $this->assertCount(4, $parents);
        $this->assertContains(1, $parents);
        $this->assertContains(2, $parents);
        $this->assertContains(3, $parents);
        $this->assertContains(4, $parents);
    }

    /**
     * 测试节点额外属性在 fromTree 后被保留
     */
    public function testFromTreePreservesExtraAttributes()
    {
        $tree = [
            [
                'id' => 1,
                'name' => 'root',
                'color' => 'red',
                'weight' => 10,
                'children' => [
                    ['id' => 2, 'name' => 'child', 'color' => 'blue', 'weight' => 5],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);

        $root = $hTree->getItem(1);
        $this->assertSame('root', $root['name']);
        $this->assertSame('red', $root['color']);
        $this->assertSame(10, $root['weight']);

        $child = $hTree->getItem(2);
        $this->assertSame('child', $child['name']);
        $this->assertSame('blue', $child['color']);
        $this->assertSame(5, $child['weight']);
    }

    /**
     * 测试 fromTree 自动填充的 parent 值正确
     */
    public function testFromTreeParentKeyAutoFilled()
    {
        $tree = [
            [
                'id' => 1,
                'children' => [
                    [
                        'id' => 2,
                        'children' => [
                            ['id' => 3],
                        ]
                    ],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);

        // 根节点的 parent 应为 null
        $root = $hTree->getItem(1);
        $this->assertNull($root['parent']);

        // 子节点的 parent 应为其父节点的 id
        $child = $hTree->getItem(2);
        $this->assertSame(1, $child['parent']);

        $grandchild = $hTree->getItem(3);
        $this->assertSame(2, $grandchild['parent']);
    }

    /**
     * 测试嵌套集合模型在 fromTree 创建的树上的完整性
     */
    public function testFromTreeNestedSetIntegrity()
    {
        $tree = [
            [
                'id' => 1,
                'children' => [
                    [
                        'id' => 2,
                        'children' => [
                            ['id' => 4],
                            ['id' => 5],
                        ]
                    ],
                    [
                        'id' => 3,
                        'children' => [
                            ['id' => 6],
                        ]
                    ],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);
        $indexes = $hTree->getIndexes();

        foreach ($indexes as $index) {
            // left 应小于 right
            $this->assertLessThan($index->right, $index->left);

            // 如果有父节点，子节点的 left/right 应在父节点范围内
            if ($index->parent !== null && isset($indexes[$index->parent])) {
                $parentIndex = $indexes[$index->parent];
                $this->assertGreaterThan($parentIndex->left, $index->left);
                $this->assertLessThan($parentIndex->right, $index->right);
            }
        }

        // 验证 left/right 值覆盖范围 = 2 * 节点数
        $allValues = [];
        foreach ($indexes as $index) {
            $allValues[] = $index->left;
            $allValues[] = $index->right;
        }
        sort($allValues);
        $this->assertCount(12, $allValues); // 6 nodes * 2
        $this->assertSame(count($allValues), count(array_unique($allValues)));
    }

    /**
     * 测试使用字符串类型的 ID
     */
    public function testFromTreeWithStringIds()
    {
        $tree = [
            [
                'id' => 'root',
                'children' => [
                    [
                        'id' => 'alpha',
                        'children' => [
                            ['id' => 'alpha-1'],
                            ['id' => 'alpha-2'],
                        ]
                    ],
                    ['id' => 'beta'],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);
        $this->assertCount(5, $hTree->getItems());

        $this->assertTrue($hTree->hasItem('root'));
        $this->assertTrue($hTree->hasItem('alpha'));
        $this->assertTrue($hTree->hasItem('alpha-1'));
        $this->assertTrue($hTree->hasItem('alpha-2'));
        $this->assertTrue($hTree->hasItem('beta'));

        $parent = $hTree->getParent('alpha-1', true);
        $this->assertSame('alpha', $parent);

        $children = $hTree->getChildren('root', false, true);
        $this->assertContains('alpha', $children);
        $this->assertContains('beta', $children);
        $this->assertContains('alpha-1', $children);
        $this->assertContains('alpha-2', $children);
    }

    /**
     * 测试 fromTree 后执行 addNode
     */
    public function testFromTreeThenAddNode()
    {
        $tree = [
            [
                'id' => 1,
                'children' => [
                    ['id' => 2],
                    ['id' => 3],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);
        $hTree->addNode(['id' => 4, 'parent' => 2]);

        $this->assertCount(4, $hTree->getItems());
        $this->assertTrue($hTree->hasItem(4));

        $parent = $hTree->getParent(4, true);
        $this->assertSame(2, $parent);

        $children = $hTree->getChildren(2, false, true);
        $this->assertContains(4, $children);
    }

    /**
     * 测试 fromTree 后执行 treeSort
     */
    public function testFromTreeThenTreeSort()
    {
        $tree = [
            [
                'id' => 1,
                'name' => 'root',
                'children' => [
                    ['id' => 3, 'name' => 'c'],
                    ['id' => 2, 'name' => 'a'],
                    ['id' => 4, 'name' => 'b'],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);
        $sorted = $hTree->treeSort(function ($node) {
            return $node['name'];
        }, SORT_ASC);

        $this->assertNotSame($hTree, $sorted);
        $this->assertInstanceOf(HTree::class, $sorted);
        $this->assertCount(4, $sorted->getItems());
    }

    /**
     * 测试 fromTree 后执行 treeMap
     */
    public function testFromTreeThenTreeMap()
    {
        $tree = [
            [
                'id' => 1,
                'name' => 'root',
                'children' => [
                    ['id' => 2, 'name' => 'child'],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);
        $mapped = $hTree->treeMap(function ($node) {
            $node['name'] = strtoupper($node['name']);
            return $node;
        });

        $this->assertSame('ROOT', $mapped->getItem(1)['name']);
        $this->assertSame('CHILD', $mapped->getItem(2)['name']);

        // 原树不受影响
        $this->assertSame('root', $hTree->getItem(1)['name']);
    }

    /**
     * 测试 fromTree 与 getTree 完整往返：结构和数据一致性
     */
    public function testFromTreeGetTreeFullRoundTrip()
    {
        $tree = [
            [
                'id' => 1,
                'name' => 'A',
                'children' => [
                    [
                        'id' => 2,
                        'name' => 'B',
                        'children' => [
                            ['id' => 4, 'name' => 'D'],
                            ['id' => 5, 'name' => 'E'],
                        ]
                    ],
                    [
                        'id' => 3,
                        'name' => 'C',
                        'children' => [
                            ['id' => 6, 'name' => 'F'],
                        ]
                    ],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);
        $result = $hTree->getTree('children', function ($item) {
            return ['id' => $item['id'], 'name' => $item['name']];
        });

        // 顶层只有一个根
        $this->assertCount(1, $result);
        $root = $result[0];
        $this->assertSame(1, $root['id']);
        $this->assertSame('A', $root['name']);

        // root 有两个子节点
        $this->assertCount(2, $root['children']);

        // 子节点 B 有两个孙节点
        $b = $root['children'][0];
        $this->assertSame(2, $b['id']);
        $this->assertCount(2, $b['children']);
        $this->assertSame(4, $b['children'][0]['id']);
        $this->assertSame(5, $b['children'][1]['id']);

        // 子节点 C 有一个孙节点
        $c = $root['children'][1];
        $this->assertSame(3, $c['id']);
        $this->assertCount(1, $c['children']);
        $this->assertSame(6, $c['children'][0]['id']);
    }

    /**
     * 测试宽树（一个根下挂多个直接子节点）
     */
    public function testFromTreeWideStructure()
    {
        $children = [];
        for ($i = 2; $i <= 11; $i++) {
            $children[] = ['id' => $i, 'name' => "child_{$i}"];
        }

        $tree = [
            ['id' => 1, 'name' => 'root', 'children' => $children],
        ];

        $hTree = HTree::fromTree($tree);
        $this->assertCount(11, $hTree->getItems());

        $childIds = $hTree->getChildren(1, false, true);
        $this->assertCount(10, $childIds);
        for ($i = 2; $i <= 11; $i++) {
            $this->assertContains($i, $childIds);
            $this->assertSame(2, $hTree->getIndex($i)->level);
        }
    }

    /**
     * 测试 children 为空数组和缺少 children key 两种情况都能正常处理
     */
    public function testFromTreeChildrenKeyVariations()
    {
        $tree = [
            [
                'id' => 1,
                'children' => [
                    ['id' => 2, 'children' => []],   // 显式空数组
                    ['id' => 3],                       // 没有 children key
                    [
                        'id' => 4,
                        'children' => [        // 有子节点
                            ['id' => 5],
                        ]
                    ],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);
        $this->assertCount(5, $hTree->getItems());

        // id=2 和 id=3 都是叶子节点
        $this->assertEmpty($hTree->getChildren(2, false, true));
        $this->assertEmpty($hTree->getChildren(3, false, true));

        // id=4 有一个子节点
        $children4 = $hTree->getChildren(4, false, true);
        $this->assertCount(1, $children4);
        $this->assertContains(5, $children4);
    }

    /**
     * 测试 fromTree 创建的树 getIndex 返回正确
     */
    public function testFromTreeIndexAttributes()
    {
        $tree = [
            [
                'id' => 10,
                'children' => [
                    [
                        'id' => 20,
                        'children' => [
                            ['id' => 30],
                        ]
                    ],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);

        $index10 = $hTree->getIndex(10);
        $this->assertInstanceOf(Index::class, $index10);
        $this->assertSame(10, $index10->id);
        $this->assertNull($index10->parent);

        $index20 = $hTree->getIndex(20);
        $this->assertInstanceOf(Index::class, $index20);
        $this->assertSame(20, $index20->id);
        $this->assertSame(10, $index20->parent);

        $index30 = $hTree->getIndex(30);
        $this->assertInstanceOf(Index::class, $index30);
        $this->assertSame(30, $index30->id);
        $this->assertSame(20, $index30->parent);

        // 不存在的节点返回 null
        $this->assertNull($hTree->getIndex(999));
    }

    /**
     * 测试多根节点之间互相隔离
     */
    public function testFromTreeMultipleRootsIsolation()
    {
        $tree = [
            [
                'id' => 1,
                'children' => [
                    ['id' => 3],
                    ['id' => 4],
                ]
            ],
            [
                'id' => 2,
                'children' => [
                    ['id' => 5],
                    ['id' => 6],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);

        // root1 的子孙不包含 root2 的子节点
        $children1 = $hTree->getChildren(1, false, true);
        $this->assertContains(3, $children1);
        $this->assertContains(4, $children1);
        $this->assertNotContains(5, $children1);
        $this->assertNotContains(6, $children1);

        // root2 的子孙不包含 root1 的子节点
        $children2 = $hTree->getChildren(2, false, true);
        $this->assertContains(5, $children2);
        $this->assertContains(6, $children2);
        $this->assertNotContains(3, $children2);
        $this->assertNotContains(4, $children2);

        // 两个根节点互不为对方的祖先
        $parents3 = $hTree->getParents(3, false, true);
        $this->assertContains(1, $parents3);
        $this->assertNotContains(2, $parents3);
    }

    // ==================== getChildren 重构验证测试 ====================

    /**
     * 验证 getChildren 区间合并：传入有父子关系的 nid，结果应与只传入祖先节点一致
     */
    public function testGetChildrenOverlappingNidsParentChild()
    {
        $tree = $this->createTree();

        // 节点1包含节点2, 传入[1, 2]的结果应该和只传入[1]一致
        $children1 = $tree->getChildren(1, false, true);
        $children12 = $tree->getChildren([1, 2], false, true);

        sort($children1);
        sort($children12);
        $this->assertSame($children1, $children12);
    }

    /**
     * 验证 getChildren 区间合并：传入多层嵌套的祖孙节点
     */
    public function testGetChildrenOverlappingNidsGrandparentChild()
    {
        $tree = $this->createTree();

        // 1 > 2 > 3 > 4, 传入 [1, 3, 4] 应与 [1] 一致
        $children1 = $tree->getChildren(1, false, true);
        $children134 = $tree->getChildren([1, 3, 4], false, true);

        sort($children1);
        sort($children134);
        $this->assertSame($children1, $children134);
    }

    /**
     * 验证 getChildren：传入不相交子树的兄弟节点
     */
    public function testGetChildrenDisjointSiblingNids()
    {
        $tree = $this->createTree();

        // 节点2和节点8是兄弟节点, 各自有独立的子树
        $children2 = $tree->getChildren(2, false, true);
        $children8 = $tree->getChildren(8, false, true);
        $children28 = $tree->getChildren([2, 8], false, true);

        $expected = array_unique(array_merge($children2, $children8));
        sort($expected);
        sort($children28);
        $this->assertSame($expected, $children28);
    }

    /**
     * 验证 getChildren：传入部分不存在的 nid
     */
    public function testGetChildrenMixedExistAndNonExistNids()
    {
        $tree = $this->createTree();
        $nonExist = $this->randNonExistId();

        // 只有存在的节点应该产生结果
        $children2 = $tree->getChildren(2, false, true);
        $childrenMixed = $tree->getChildren([2, $nonExist], false, true);

        sort($children2);
        sort($childrenMixed);
        $this->assertSame($children2, $childrenMixed);
    }

    /**
     * 验证 getChildren level 过滤：startLevel 和 endLevel 精确筛选
     */
    public function testGetChildrenLevelFilter()
    {
        $tree = $this->createTree();

        // 节点1的子孙中 level=3 的节点: 3, 9, 10, 11, 12, 13
        $level3 = $tree->getChildren(1, false, true, 3, 3);
        $this->assertContains(3, $level3);
        $this->assertContains(9, $level3);
        $this->assertContains(10, $level3);
        $this->assertContains(11, $level3);
        $this->assertContains(12, $level3);
        $this->assertContains(13, $level3);
        // 不包含 level 2 或 level 4+ 的节点
        $this->assertNotContains(2, $level3);
        $this->assertNotContains(8, $level3);
        $this->assertNotContains(4, $level3);
        $this->assertNotContains(5, $level3);
        $this->assertNotContains(14, $level3);
    }

    /**
     * 验证 getChildren level 过滤：startLevel 到 endLevel 范围
     */
    public function testGetChildrenLevelFilterRange()
    {
        $tree = $this->createTree();

        // 节点1的子孙中 level 2-3 的节点
        $level23 = $tree->getChildren(1, false, true, 2, 3);
        // level 2: 2, 8, 19, 20, 21, 22, 23, 24
        $this->assertContains(2, $level23);
        $this->assertContains(8, $level23);
        $this->assertContains(19, $level23);
        // level 3: 3, 9, 10, 11, 12, 13
        $this->assertContains(3, $level23);
        $this->assertContains(9, $level23);
        // 不包含 level 4+
        $this->assertNotContains(4, $level23);
        $this->assertNotContains(5, $level23);
        $this->assertNotContains(14, $level23);
    }

    /**
     * 验证 getChildren withSelf 不受 level 过滤影响
     */
    public function testGetChildrenWithSelfBypassesLevelFilter()
    {
        $tree = $this->createTree();

        // 节点1在 level=1, 过滤 level 3-5
        $result = $tree->getChildren(1, true, true, 3, 5);

        // withSelf 应该包含节点1, 即使节点1在 level=1 不在 3-5 范围内
        $this->assertContains(1, $result);

        // 同时也应该包含 level 3-5 的子孙
        $this->assertContains(3, $result);  // level 3
        $this->assertContains(4, $result);  // level 4
        $this->assertContains(5, $result);  // level 5

        // 不应包含 level 2 的节点 (非 self)
        $this->assertNotContains(2, $result);
        $this->assertNotContains(8, $result);
    }

    /**
     * 验证 getChildren withSelf=false 时自身不在结果中
     */
    public function testGetChildrenWithoutSelf()
    {
        $tree = $this->createTree();

        $result = $tree->getChildren(1, false, true);
        $this->assertNotContains(1, $result);
        $this->assertNotEmpty($result);
    }

    /**
     * 验证 getChildren onlyId=true 按 level 排序
     */
    public function testGetChildrenOnlyIdSortedByLevel()
    {
        $tree = $this->createTree();

        $ids = $tree->getChildren(1, false, true);

        // 验证按 level 非递减排序
        $prevLevel = 0;
        foreach ($ids as $id) {
            $level = $tree->getIndex($id)->level;
            $this->assertGreaterThanOrEqual($prevLevel, $level);
            $prevLevel = $level;
        }
    }

    /**
     * 验证 getChildren onlyId=false 按 level 排序
     */
    public function testGetChildrenNonOnlyIdSortedByLevel()
    {
        $tree = $this->createTree();

        $nodes = $tree->getChildren(1, false, false);

        $prevLevel = 0;
        foreach ($nodes as $id => $node) {
            $level = $tree->getIndex($id)->level;
            $this->assertGreaterThanOrEqual($prevLevel, $level);
            $prevLevel = $level;
        }
    }

    /**
     * 验证 getChildren onlyId 与非 onlyId 返回相同的节点集合
     */
    public function testGetChildrenOnlyIdConsistencyWithNonOnlyId()
    {
        $tree = $this->createTree();

        $ids = $tree->getChildren(1, true, true);
        $nodes = $tree->getChildren(1, true, false);

        $nodeIds = array_keys($nodes);
        sort($ids);
        sort($nodeIds);
        $this->assertSame($ids, $nodeIds);
    }

    /**
     * 验证 getChildren 叶子节点无子节点
     */
    public function testGetChildrenLeafNode()
    {
        $tree = $this->createTree();

        // 节点5是叶子节点
        $children = $tree->getChildren(5, false, true);
        $this->assertEmpty($children);

        $childrenData = $tree->getChildren(5, false, false);
        $this->assertEmpty($childrenData);
    }

    /**
     * 验证 getChildren 根节点获取全部后代
     */
    public function testGetChildrenRootGetsAllDescendants()
    {
        $tree = $this->createTree();

        $children = $tree->getChildren(1, false, true);
        $allItems = $tree->getItems();

        // 根节点的所有后代 = 全部节点 - 根节点自身
        $this->assertCount(count($allItems) - 1, $children);
    }

    /**
     * 验证 getChildren 子孙数量与嵌套集合区间匹配
     */
    public function testGetChildrenCountMatchesNestedSetRange()
    {
        $tree = $this->createTree();

        foreach ($tree->getIndexes() as $id => $index) {
            $children = $tree->getChildren($id, false, true);
            // 嵌套集合模型中子孙数量 = (right - left - 1) / 2
            $expectedCount = ($index->right - $index->left - 1) / 2;
            $this->assertCount((int)$expectedCount, $children, "节点 {$id} 的子孙数量不匹配");
        }
    }

    /**
     * 验证 getChildren 空数组输入
     */
    public function testGetChildrenEmptyArrayInput()
    {
        $tree = $this->createTree();

        $result = $tree->getChildren([], false, true);
        $this->assertEmpty($result);

        $result = $tree->getChildren([], true, true);
        $this->assertEmpty($result);
    }

    /**
     * 验证 getChildren 批量 nids 的 level 过滤
     */
    public function testGetChildrenBatchNidsWithLevelFilter()
    {
        $tree = $this->createTree();

        // 获取节点2和8的子孙, 限制 level 3-3
        $result = $tree->getChildren([2, 8], false, true, 3, 3);

        // 节点2的 level 3 子孙: 3
        $this->assertContains(3, $result);
        // 节点8的 level 3 子孙: 9, 10, 11, 12, 13
        $this->assertContains(9, $result);
        $this->assertContains(10, $result);
        // 不包含 level 4 以下
        $this->assertNotContains(4, $result);
        $this->assertNotContains(14, $result);
        // 不包含 level 2
        $this->assertNotContains(2, $result);
        $this->assertNotContains(8, $result);
    }

    /**
     * 验证 getChildren 批量 nids 带 withSelf 和 level 过滤
     */
    public function testGetChildrenBatchNidsWithSelfAndLevelFilter()
    {
        $tree = $this->createTree();

        // 获取节点2和8的子孙, level 3-4, withSelf
        $result = $tree->getChildren([2, 8], true, true, 3, 4);

        // self 不受 level 过滤: 节点2(level 2)和节点8(level 2)应在结果中
        $this->assertContains(2, $result);
        $this->assertContains(8, $result);
        // level 3-4 的子孙也应在结果中
        $this->assertContains(3, $result);  // level 3
        $this->assertContains(4, $result);  // level 4
        $this->assertContains(9, $result);  // level 3
        $this->assertContains(14, $result); // level 4
    }

    // ==================== getParents 重构验证测试 ====================

    /**
     * 验证 getParents parent 链遍历与全量扫描结果一致
     */
    public function testGetParentsChainTraversalCorrectness()
    {
        $tree = $this->createTree();

        // 验证每个节点的父节点链正确
        foreach ($tree->getIndexes() as $id => $index) {
            $parents = $tree->getParents($id, false, true);

            // 手动通过嵌套集合区间验证
            $expectedParents = [];
            foreach ($tree->getIndexes() as $otherId => $otherIndex) {
                if ($otherIndex->left < $index->left && $otherIndex->right > $index->right) {
                    $expectedParents[] = $otherId;
                }
            }
            sort($expectedParents);
            sort($parents);
            $this->assertSame($expectedParents, $parents, "节点 {$id} 的父节点链不正确");
        }
    }

    /**
     * 验证 getParents level 过滤
     */
    public function testGetParentsLevelFilter()
    {
        $tree = $this->createTree();

        // 节点5 (level 5) 的父节点: 4(level 4), 3(level 3), 2(level 2), 1(level 1)
        $parents = $tree->getParents(5, false, true, 2, 3);
        $this->assertContains(2, $parents);  // level 2
        $this->assertContains(3, $parents);  // level 3
        $this->assertNotContains(1, $parents);  // level 1, 不在范围
        $this->assertNotContains(4, $parents);  // level 4, 不在范围
    }

    /**
     * 验证 getParents withSelf 受 level 过滤影响
     */
    public function testGetParentsWithSelfRespectsLevelFilter()
    {
        $tree = $this->createTree();

        // 节点5 (level 5), level 过滤 1-2, withSelf=true
        $parents = $tree->getParents(5, true, true, 1, 2);

        // self(level 5) 不在 [1,2] 范围内, 所以不应包含自身
        $this->assertNotContains(5, $parents);
        // 应包含 level 1-2 的祖先
        $this->assertContains(1, $parents);
        $this->assertContains(2, $parents);
    }

    /**
     * 验证 getParents withSelf 在 level 范围内时包含自身
     */
    public function testGetParentsWithSelfInLevelRange()
    {
        $tree = $this->createTree();

        // 节点5 (level 5), level 过滤 3-5, withSelf=true
        $parents = $tree->getParents(5, true, true, 3, 5);

        // self(level 5) 在 [3,5] 范围内, 应包含自身
        $this->assertContains(5, $parents);
        $this->assertContains(3, $parents);
        $this->assertContains(4, $parents);
    }

    /**
     * 验证 getParents 批量 nids 去重
     */
    public function testGetParentsMultipleNidsDeduplication()
    {
        $tree = $this->createTree();

        // 节点5和6的父节点: 都有 1,2,3,4
        $parents5 = $tree->getParents(5, false, true);
        $parents56 = $tree->getParents([5, 6], false, true);

        // 共同祖先不应重复
        sort($parents5);
        sort($parents56);
        $this->assertSame($parents5, $parents56);
    }

    /**
     * 验证 getParents 批量 nids 来自不同子树
     */
    public function testGetParentsMultipleNidsFromDifferentSubtrees()
    {
        $tree = $this->createTree();

        // 节点5的祖先: 4, 3, 2, 1; 节点14的祖先: 9, 8, 1
        $parents = $tree->getParents([5, 14], false, true);

        $this->assertContains(1, $parents);
        $this->assertContains(2, $parents);
        $this->assertContains(3, $parents);
        $this->assertContains(4, $parents);
        $this->assertContains(8, $parents);
        $this->assertContains(9, $parents);
        // 不包含自身
        $this->assertNotContains(5, $parents);
        $this->assertNotContains(14, $parents);
        // 不包含无关节点
        $this->assertNotContains(19, $parents);
    }

    /**
     * 验证 getParents onlyId=true 按 level 排序
     */
    public function testGetParentsOnlyIdSortedByLevel()
    {
        $tree = $this->createTree();

        $ids = $tree->getParents(5, false, true);

        $prevLevel = 0;
        foreach ($ids as $id) {
            $level = $tree->getIndex($id)->level;
            $this->assertGreaterThanOrEqual($prevLevel, $level);
            $prevLevel = $level;
        }
    }

    /**
     * 验证 getParents onlyId=false 按 level 排序
     */
    public function testGetParentsNonOnlyIdSortedByLevel()
    {
        $tree = $this->createTree();

        $nodes = $tree->getParents(5, false, false);

        $prevLevel = 0;
        foreach ($nodes as $id => $node) {
            $level = $tree->getIndex($id)->level;
            $this->assertGreaterThanOrEqual($prevLevel, $level);
            $prevLevel = $level;
        }
    }

    /**
     * 验证 getParents onlyId 与非 onlyId 返回相同的节点集合
     */
    public function testGetParentsOnlyIdConsistencyWithNonOnlyId()
    {
        $tree = $this->createTree();

        $ids = $tree->getParents(5, true, true);
        $nodes = $tree->getParents(5, true, false);

        $nodeIds = array_keys($nodes);
        sort($ids);
        sort($nodeIds);
        $this->assertSame($ids, $nodeIds);
    }

    /**
     * 验证 getParents 根节点没有父节点
     */
    public function testGetParentsRootNode()
    {
        $tree = $this->createTree();

        $parents = $tree->getParents(1, false, true);
        $this->assertEmpty($parents);

        $parentsWithSelf = $tree->getParents(1, true, true);
        $this->assertCount(1, $parentsWithSelf);
        $this->assertContains(1, $parentsWithSelf);
    }

    /**
     * 验证 getParents 叶子节点有完整的祖先链
     */
    public function testGetParentsLeafNode()
    {
        $tree = $this->createTree();

        // 节点5的祖先链: 4 -> 3 -> 2 -> 1
        $parents = $tree->getParents(5, false, true);
        $this->assertCount(4, $parents);
        $this->assertSame([1, 2, 3, 4], $parents);
    }

    /**
     * 验证 getParents 空数组输入
     */
    public function testGetParentsEmptyArrayInput()
    {
        $tree = $this->createTree();

        $result = $tree->getParents([], false, true);
        $this->assertEmpty($result);
    }

    /**
     * 验证 getParents 不存在的节点
     */
    public function testGetParentsNonExistentNode()
    {
        $tree = $this->createTree();

        $result = $tree->getParents($this->randNonExistId(), false, true);
        $this->assertEmpty($result);

        $result = $tree->getParents($this->randNonExistId(), true, true);
        $this->assertEmpty($result);
    }

    /**
     * 验证 getParents 批量 nids 的 level 过滤
     */
    public function testGetParentsBatchNidsWithLevelFilter()
    {
        $tree = $this->createTree();

        // 节点5(level 5)和14(level 4) 的父节点, 限制 level 1-2
        $result = $tree->getParents([5, 14], false, true, 1, 2);

        $this->assertContains(1, $result);  // level 1
        $this->assertContains(2, $result);  // level 2 (5的祖先)
        $this->assertContains(8, $result);  // level 2 (14的祖先)
        $this->assertNotContains(3, $result);  // level 3
        $this->assertNotContains(4, $result);  // level 4
        $this->assertNotContains(9, $result);  // level 3
    }

    // ==================== getChildren 与 getParents 交叉验证 ====================

    /**
     * 验证 getChildren 和 getParents 的对偶关系：B是A的子孙 ⟺ A是B的祖先
     */
    public function testGetChildrenGetParentsDuality()
    {
        $tree = $this->createTree();

        foreach ($tree->getIndexes() as $id => $index) {
            $children = $tree->getChildren($id, false, true);
            foreach ($children as $childId) {
                $parents = $tree->getParents($childId, false, true);
                $this->assertContains($id, $parents,
                    "节点 {$childId} 是 {$id} 的子孙, 但 {$id} 不在 {$childId} 的祖先中");
            }
        }
    }

    /**
     * 验证反向对偶：A是B的祖先 ⟹ B是A的子孙
     */
    public function testGetParentsGetChildrenDuality()
    {
        $tree = $this->createTree();

        foreach ($tree->getIndexes() as $id => $index) {
            $parents = $tree->getParents($id, false, true);
            foreach ($parents as $parentId) {
                $children = $tree->getChildren($parentId, false, true);
                $this->assertContains($id, $children,
                    "节点 {$parentId} 是 {$id} 的祖先, 但 {$id} 不在 {$parentId} 的子孙中");
            }
        }
    }

    // ==================== addNode 后的 getParents 循环引用防护 ====================

    /**
     * 验证 getParents 在 parent 指针形成环时不会死循环
     */
    public function testGetParentsNoInfiniteLoopOnCircularParent()
    {
        $tree = $this->createTree();

        // 先添加一个孤儿节点 (parent 不存在)，其 index.parent 指向不存在的节点
        $orphanId = 9999;
        $tree->addNode(['id' => $orphanId, 'parent' => 88888]);

        // 验证可以正常调用不会挂起
        $parents = $tree->getParents($orphanId, false, true);
        $this->assertEmpty($parents);
    }

    /**
     * 验证 addNode 后 getChildren 和 getParents 的一致性
     */
    public function testAddNodeThenGetChildrenAndParentsConsistency()
    {
        $tree = $this->createTree();

        $newId = 9001;
        $tree->addNode(['id' => $newId, 'parent' => 4]);

        // 新节点应该是4的子节点
        $children4 = $tree->getChildren(4, false, true);
        $this->assertContains($newId, $children4);

        // 新节点的父节点链应该包含 4, 3, 2, 1
        $parents = $tree->getParents($newId, false, true);
        $this->assertContains(4, $parents);
        $this->assertContains(3, $parents);
        $this->assertContains(2, $parents);
        $this->assertContains(1, $parents);

        // 新节点应该是1的后代
        $children1 = $tree->getChildren(1, false, true);
        $this->assertContains($newId, $children1);
    }

    // ==================== 全量节点验证 ====================

    /**
     * 对每个节点全面验证 getChildren 结果的完整性和正确性
     */
    public function testGetChildrenExhaustiveVerification()
    {
        $tree = $this->createTree();
        $indexes = $tree->getIndexes();

        foreach ($indexes as $id => $index) {
            $childrenIds = $tree->getChildren($id, false, true);

            foreach ($childrenIds as $childId) {
                $childIndex = $tree->getIndex($childId);
                // 子节点的 left/right 必须严格包含在父节点的 left/right 内
                $this->assertGreaterThan($index->left, $childIndex->left,
                    "子节点 {$childId}.left 应大于父节点 {$id}.left");
                $this->assertLessThan($index->right, $childIndex->right,
                    "子节点 {$childId}.right 应小于父节点 {$id}.right");
            }

            // 确保没有遗漏的子节点
            foreach ($indexes as $otherId => $otherIndex) {
                if ($otherId === $id) {
                    continue;
                }
                $isDescendant = $otherIndex->left > $index->left && $otherIndex->right < $index->right;
                if ($isDescendant) {
                    $this->assertContains($otherId, $childrenIds,
                        "节点 {$otherId} 应是 {$id} 的子孙但未返回");
                } else {
                    $this->assertNotContains($otherId, $childrenIds,
                        "节点 {$otherId} 不应是 {$id} 的子孙但被返回");
                }
            }
        }
    }

    /**
     * 对每个节点全面验证 getParents 结果的完整性和正确性
     */
    public function testGetParentsExhaustiveVerification()
    {
        $tree = $this->createTree();
        $indexes = $tree->getIndexes();

        foreach ($indexes as $id => $index) {
            $parentIds = $tree->getParents($id, false, true);

            foreach ($parentIds as $parentId) {
                $parentIndex = $tree->getIndex($parentId);
                // 父节点的 left/right 必须包含当前节点
                $this->assertLessThan($index->left, $parentIndex->left,
                    "父节点 {$parentId}.left 应小于节点 {$id}.left");
                $this->assertGreaterThan($index->right, $parentIndex->right,
                    "父节点 {$parentId}.right 应大于节点 {$id}.right");
            }

            // 确保没有遗漏的父节点
            foreach ($indexes as $otherId => $otherIndex) {
                if ($otherId === $id) {
                    continue;
                }
                $isAncestor = $otherIndex->left < $index->left && $otherIndex->right > $index->right;
                if ($isAncestor) {
                    $this->assertContains($otherId, $parentIds,
                        "节点 {$otherId} 应是 {$id} 的祖先但未返回");
                } else {
                    $this->assertNotContains($otherId, $parentIds,
                        "节点 {$otherId} 不应是 {$id} 的祖先但被返回");
                }
            }
        }
    }

    // ==================== fromTree 与重构方法的交叉测试 ====================

    /**
     * 验证 fromTree 创建的树的 getChildren level 过滤
     */
    public function testFromTreeGetChildrenLevelFilter()
    {
        $tree = [
            [
                'id' => 1,
                'children' => [
                    [
                        'id' => 2,
                        'children' => [
                            [
                                'id' => 3,
                                'children' => [
                                    ['id' => 4],
                                ]
                            ],
                        ]
                    ],
                    ['id' => 5],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);

        // level 2 的子孙: 2, 5
        $level2 = $hTree->getChildren(1, false, true, 2, 2);
        $this->assertContains(2, $level2);
        $this->assertContains(5, $level2);
        $this->assertNotContains(3, $level2);
        $this->assertNotContains(4, $level2);
    }

    /**
     * 验证 fromTree 创建的树的 getParents parent 链
     */
    public function testFromTreeGetParentsChain()
    {
        $tree = [
            [
                'id' => 'A',
                'children' => [
                    [
                        'id' => 'B',
                        'children' => [
                            [
                                'id' => 'C',
                                'children' => [
                                    ['id' => 'D'],
                                ]
                            ],
                        ]
                    ],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);

        $parents = $hTree->getParents('D', false, true);
        $this->assertSame(['A', 'B', 'C'], $parents);
    }

    /**
     * 验证 fromTree 多根树的 getChildren 批量 nids
     */
    public function testFromTreeMultiRootGetChildrenBatchNids()
    {
        $tree = [
            [
                'id' => 1,
                'children' => [
                    ['id' => 3],
                    ['id' => 4],
                ]
            ],
            [
                'id' => 2,
                'children' => [
                    ['id' => 5],
                    ['id' => 6],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);

        // 批量获取两个根的所有子孙
        $children = $hTree->getChildren([1, 2], false, true);
        $this->assertContains(3, $children);
        $this->assertContains(4, $children);
        $this->assertContains(5, $children);
        $this->assertContains(6, $children);
        $this->assertCount(4, $children);
    }

    /**
     * 验证 fromTree 多根树的 getParents 批量 nids
     */
    public function testFromTreeMultiRootGetParentsBatchNids()
    {
        $tree = [
            [
                'id' => 1,
                'children' => [
                    ['id' => 3],
                ]
            ],
            [
                'id' => 2,
                'children' => [
                    ['id' => 4],
                ]
            ],
        ];

        $hTree = HTree::fromTree($tree);

        // 批量获取叶子节点的父节点
        $parents = $hTree->getParents([3, 4], false, true);
        $this->assertContains(1, $parents);
        $this->assertContains(2, $parents);
        $this->assertCount(2, $parents);
    }

    // ==================== 边界情况测试 ====================

    /**
     * 验证只有一个节点的树
     */
    public function testSingleNodeTree()
    {
        $tree = HTree::instance([['id' => 1, 'parent' => 0]]);

        $this->assertEmpty($tree->getChildren(1, false, true));
        $this->assertEmpty($tree->getParents(1, false, true));
        $this->assertSame([1], $tree->getChildren(1, true, true));
        $this->assertSame([1], $tree->getParents(1, true, true));
    }

    /**
     * 验证两个根节点的树
     */
    public function testTwoRootNodes()
    {
        $tree = HTree::instance([
            ['id' => 1, 'parent' => 0],
            ['id' => 2, 'parent' => 0],
        ]);

        // 互不影响
        $this->assertEmpty($tree->getChildren(1, false, true));
        $this->assertEmpty($tree->getChildren(2, false, true));
        $this->assertEmpty($tree->getParents(1, false, true));
        $this->assertEmpty($tree->getParents(2, false, true));
    }

    /**
     * 验证深度链状树 (退化为链表)
     */
    public function testDeepChainTree()
    {
        $items = [];
        $depth = 50;
        for ($i = 1; $i <= $depth; $i++) {
            $items[] = ['id' => $i, 'parent' => $i - 1];
        }

        $tree = HTree::instance($items);

        // 最深叶子节点的祖先数量应为 depth - 1
        $parents = $tree->getParents($depth, false, true);
        $this->assertCount($depth - 1, $parents);

        // 根节点的后代数量应为 depth - 1
        $children = $tree->getChildren(1, false, true);
        $this->assertCount($depth - 1, $children);

        // 验证排序 (level 递增)
        $prevLevel = 0;
        foreach ($parents as $id) {
            $level = $tree->getIndex($id)->level;
            $this->assertGreaterThanOrEqual($prevLevel, $level);
            $prevLevel = $level;
        }
    }

    /**
     * 验证宽树 (一个根下大量直接子节点)
     */
    public function testWideTree()
    {
        $items = [['id' => 1, 'parent' => 0]];
        $width = 100;
        for ($i = 2; $i <= $width + 1; $i++) {
            $items[] = ['id' => $i, 'parent' => 1];
        }

        $tree = HTree::instance($items);

        $children = $tree->getChildren(1, false, true);
        $this->assertCount($width, $children);

        // 批量获取所有子节点的父节点, 应该只有根节点
        $childIds = $tree->getChildren(1, false, true);
        $parents = $tree->getParents($childIds, false, true);
        $this->assertCount(1, $parents);
        $this->assertContains(1, $parents);
    }

    /**
     * 验证 getChildren/getParents 对 id=0 的处理
     */
    public function testNodeWithIdZero()
    {
        // id=0 在 PHP 中有特殊语义 (empty(0) === true)
        // 这里验证系统能正确处理
        $items = [
            ['id' => 'root', 'parent' => null],
            ['id' => 'child', 'parent' => 'root'],
        ];

        $tree = HTree::instance($items, 'id', 'parent');

        $children = $tree->getChildren('root', false, true);
        $this->assertContains('child', $children);

        $parents = $tree->getParents('child', false, true);
        $this->assertContains('root', $parents);
    }

    /**
     * 验证 getChildren startLevel > endLevel 时返回空结果
     */
    public function testGetChildrenInvalidLevelRange()
    {
        $tree = $this->createTree();

        // startLevel > endLevel, 不应有任何结果
        $result = $tree->getChildren(1, false, true, 5, 3);
        $this->assertEmpty($result);
    }

    /**
     * 验证 getParents startLevel > endLevel 时返回空结果
     */
    public function testGetParentsInvalidLevelRange()
    {
        $tree = $this->createTree();

        $result = $tree->getParents(5, false, true, 5, 1);
        $this->assertEmpty($result);
    }

    /**
     * 验证 getChildren 传入重复的 nid 不影响结果
     */
    public function testGetChildrenDuplicateNids()
    {
        $tree = $this->createTree();

        $children1 = $tree->getChildren(2, false, true);
        $children2 = $tree->getChildren([2, 2, 2], false, true);

        sort($children1);
        sort($children2);
        $this->assertSame($children1, $children2);
    }

    /**
     * 验证 getParents 传入重复的 nid 不影响结果
     */
    public function testGetParentsDuplicateNids()
    {
        $tree = $this->createTree();

        $parents1 = $tree->getParents(5, false, true);
        $parents2 = $tree->getParents([5, 5, 5], false, true);

        sort($parents1);
        sort($parents2);
        $this->assertSame($parents1, $parents2);
    }

    /**
     * 验证 treeSort 后 getChildren 和 getParents 仍然正确
     */
    public function testTreeSortThenGetChildrenAndParents()
    {
        $tree = $this->createTree();

        $sorted = $tree->treeSort(function ($node) {
            return str_pad((string)$this->getNodeId($node), 10, '0', STR_PAD_LEFT);
        });

        // 排序后, getChildren 的结果集应该不变 (只是顺序可能不同)
        $origChildren = $tree->getChildren(1, false, true);
        $sortedChildren = $sorted->getChildren(1, false, true);
        sort($origChildren);
        sort($sortedChildren);
        $this->assertSame($origChildren, $sortedChildren);

        // 排序后, getParents 的结果集应该不变
        $origParents = $tree->getParents(5, false, true);
        $sortedParents = $sorted->getParents(5, false, true);
        sort($origParents);
        sort($sortedParents);
        $this->assertSame($origParents, $sortedParents);
    }

    /**
     * 验证 treeMap 后 getChildren 和 getParents 仍然正确
     */
    public function testTreeMapThenGetChildrenAndParents()
    {
        $tree = $this->createTree();

        $mapped = $tree->treeMap(function ($node) {
            $node['extra'] = 'mapped';
            return $node;
        });

        $origChildren = $tree->getChildren(1, false, true);
        $mappedChildren = $mapped->getChildren(1, false, true);
        $this->assertSame($origChildren, $mappedChildren);

        $origParents = $tree->getParents(5, false, true);
        $mappedParents = $mapped->getParents(5, false, true);
        $this->assertSame($origParents, $mappedParents);
    }

    /**
     * 验证 getParent 方法 (单个父节点) 与 getParents 一致
     */
    public function testGetParentConsistencyWithGetParents()
    {
        $tree = $this->createTree();

        foreach ($tree->getIndexes() as $id => $index) {
            $parent = $tree->getParent($id, true);
            if ($parent === null) {
                // 根节点, getParents 也应为空
                $parents = $tree->getParents($id, false, true);
                $this->assertEmpty($parents);
            } else {
                // 非根节点, getParent 返回的应该在 getParents 中
                $parents = $tree->getParents($id, false, true);
                $this->assertContains($parent, $parents);

                // getParent 返回的是直接父节点 (level = 当前 level - 1)
                $parentIndex = $tree->getIndex($parent);
                $this->assertSame($index->level - 1, $parentIndex->level);
            }
        }
    }

    // ==================== 孤儿节点 & 父节点不存在 重点测试 ====================

    /**
     * 构造一棵包含孤儿节点的树.
     *
     * 结构:
     *   正常树:                 孤儿树 (parent=999 不存在):
     *     1 (root, parent=0)     100 (orphan root, parent=999)
     *     ├── 2                  ├── 101
     *     │   ├── 4              │   └── 103
     *     │   └── 5              └── 102
     *     └── 3
     *
     * 节点 100、101、102、103 的 parent=999 最终在 indexes 中不存在,
     * 所以 100 会被当作根节点处理.
     */
    protected function createOrphanTree()
    {
        return HTree::instance([
            // 正常树
            ['id' => 1, 'parent' => 0],
            ['id' => 2, 'parent' => 1],
            ['id' => 3, 'parent' => 1],
            ['id' => 4, 'parent' => 2],
            ['id' => 5, 'parent' => 2],
            // 孤儿树: parent=999 不存在于输入数据中
            ['id' => 100, 'parent' => 999],
            ['id' => 101, 'parent' => 100],
            ['id' => 102, 'parent' => 100],
            ['id' => 103, 'parent' => 101],
        ]);
    }

    /**
     * 孤儿节点应当被成功构建, 作为独立的根节点
     */
    public function testOrphanNodeConstruction()
    {
        $tree = $this->createOrphanTree();

        // 所有 9 个节点都应该存在
        $this->assertCount(9, $tree->getItems());
        foreach ([1, 2, 3, 4, 5, 100, 101, 102, 103] as $id) {
            $this->assertTrue($tree->hasItem($id), "节点 {$id} 应存在");
            $this->assertNotNull($tree->getIndex($id), "节点 {$id} 的 Index 应存在");
        }

        // 孤儿根节点 100 的 level 应该是 1 (被当作根节点)
        $this->assertSame(1, $tree->getIndex(100)->level);
        // 孤儿子节点 level 正确递增
        $this->assertSame(2, $tree->getIndex(101)->level);
        $this->assertSame(2, $tree->getIndex(102)->level);
        $this->assertSame(3, $tree->getIndex(103)->level);
    }

    /**
     * 孤儿树的嵌套集合完整性: left/right 不与正常树冲突
     */
    public function testOrphanNodeNestedSetIntegrity()
    {
        $tree = $this->createOrphanTree();
        $indexes = $tree->getIndexes();

        // 收集所有 left/right 值, 验证唯一性
        $allValues = [];
        foreach ($indexes as $index) {
            $this->assertLessThan($index->right, $index->left, "节点 {$index->id}: left 应 < right");
            $allValues[] = $index->left;
            $allValues[] = $index->right;
        }
        $this->assertCount(18, $allValues); // 9 nodes * 2
        $this->assertSame(count($allValues), count(array_unique($allValues)), "所有 left/right 值应唯一");

        // 验证父子包含关系
        foreach ($indexes as $index) {
            if ($index->parent && isset($indexes[$index->parent])) {
                $parentIndex = $indexes[$index->parent];
                $this->assertGreaterThan($parentIndex->left, $index->left,
                    "节点 {$index->id}.left 应在父节点 {$index->parent} 区间内");
                $this->assertLessThan($parentIndex->right, $index->right,
                    "节点 {$index->id}.right 应在父节点 {$index->parent} 区间内");
            }
        }
    }

    /**
     * getChildren: 正常根节点不会返回孤儿树的节点
     */
    public function testGetChildrenNormalRootExcludesOrphans()
    {
        $tree = $this->createOrphanTree();

        $children = $tree->getChildren(1, false, true);

        // 应包含正常树的子孙
        $this->assertContains(2, $children);
        $this->assertContains(3, $children);
        $this->assertContains(4, $children);
        $this->assertContains(5, $children);

        // 不应包含孤儿树的任何节点
        $this->assertNotContains(100, $children);
        $this->assertNotContains(101, $children);
        $this->assertNotContains(102, $children);
        $this->assertNotContains(103, $children);
    }

    /**
     * getChildren: 孤儿根节点正确返回自己的子孙
     */
    public function testGetChildrenOrphanRoot()
    {
        $tree = $this->createOrphanTree();

        $children = $tree->getChildren(100, false, true);

        // 应包含孤儿树的子孙
        $this->assertContains(101, $children);
        $this->assertContains(102, $children);
        $this->assertContains(103, $children);
        $this->assertCount(3, $children);

        // 不应包含正常树的节点
        $this->assertNotContains(1, $children);
        $this->assertNotContains(2, $children);
    }

    /**
     * getChildren: 孤儿树中间节点正确返回子孙
     */
    public function testGetChildrenOrphanMiddleNode()
    {
        $tree = $this->createOrphanTree();

        // 节点 101 是孤儿树的中间节点, 子孙只有 103
        $children = $tree->getChildren(101, false, true);
        $this->assertContains(103, $children);
        $this->assertCount(1, $children);

        // 节点 102 是孤儿树的叶子
        $children = $tree->getChildren(102, false, true);
        $this->assertEmpty($children);
    }

    /**
     * getChildren: 孤儿树叶子节点的子孙数量与嵌套集合区间匹配
     */
    public function testGetChildrenOrphanCountMatchesNestedSet()
    {
        $tree = $this->createOrphanTree();

        foreach ($tree->getIndexes() as $id => $index) {
            $children = $tree->getChildren($id, false, true);
            $expectedCount = ($index->right - $index->left - 1) / 2;
            $this->assertCount((int)$expectedCount, $children, "节点 {$id} 的子孙数量不匹配");
        }
    }

    /**
     * getChildren: 批量查询正常根和孤儿根, 结果互不污染
     */
    public function testGetChildrenBatchNormalAndOrphanRoots()
    {
        $tree = $this->createOrphanTree();

        $children = $tree->getChildren([1, 100], false, true);

        // 正常树子孙 + 孤儿树子孙 = 4 + 3 = 7
        $expected = [2, 3, 4, 5, 101, 102, 103];
        sort($expected);
        sort($children);
        $this->assertSame($expected, $children);
    }

    /**
     * getChildren: 批量查询包含正常节点和孤儿节点, withSelf
     */
    public function testGetChildrenBatchMixedWithSelf()
    {
        $tree = $this->createOrphanTree();

        $children = $tree->getChildren([2, 101], true, true);

        // withSelf: 包含节点2和节点101自身
        $this->assertContains(2, $children);
        $this->assertContains(101, $children);
        // 节点2的子孙: 4, 5
        $this->assertContains(4, $children);
        $this->assertContains(5, $children);
        // 节点101的子孙: 103
        $this->assertContains(103, $children);
        // 不包含无关节点
        $this->assertNotContains(1, $children);
        $this->assertNotContains(3, $children);
        $this->assertNotContains(100, $children);
        $this->assertNotContains(102, $children);
    }

    /**
     * getChildren: 对孤儿树使用 level 过滤
     */
    public function testGetChildrenOrphanWithLevelFilter()
    {
        $tree = $this->createOrphanTree();

        // 孤儿根100的子孙, 限制 level=2
        $result = $tree->getChildren(100, false, true, 2, 2);
        $this->assertContains(101, $result);
        $this->assertContains(102, $result);
        $this->assertNotContains(103, $result); // level 3
        $this->assertCount(2, $result);
    }

    /**
     * getChildren: 对孤儿根 withSelf + level 过滤, self 不受过滤影响
     */
    public function testGetChildrenOrphanWithSelfBypassesLevelFilter()
    {
        $tree = $this->createOrphanTree();

        // 孤儿根100 (level 1), 过滤 level 3, withSelf
        $result = $tree->getChildren(100, true, true, 3, 3);
        // self(level 1) 不在 [3,3] 范围内, 但 withSelf 应不受影响
        $this->assertContains(100, $result);
        // level 3 的子孙: 103
        $this->assertContains(103, $result);
        $this->assertCount(2, $result);
    }

    /**
     * getParents: 孤儿根节点没有父节点
     */
    public function testGetParentsOrphanRoot()
    {
        $tree = $this->createOrphanTree();

        // 孤儿根 100 的 parent=999 不在 indexes 中, 所以没有父节点
        $parents = $tree->getParents(100, false, true);
        $this->assertEmpty($parents);

        // withSelf 时只返回自身
        $parents = $tree->getParents(100, true, true);
        $this->assertCount(1, $parents);
        $this->assertContains(100, $parents);
    }

    /**
     * getParents: 孤儿树子节点的祖先链只包含孤儿树内的节点
     */
    public function testGetParentsOrphanChild()
    {
        $tree = $this->createOrphanTree();

        // 节点 103 的祖先链: 101 -> 100 (不会包含正常树的任何节点)
        $parents = $tree->getParents(103, false, true);
        $this->assertContains(100, $parents);
        $this->assertContains(101, $parents);
        $this->assertCount(2, $parents);

        // 不包含正常树节点
        $this->assertNotContains(1, $parents);
        $this->assertNotContains(2, $parents);
    }

    /**
     * getParents: 孤儿树直接子节点的直接父节点
     */
    public function testGetParentOrphanDirectChild()
    {
        $tree = $this->createOrphanTree();

        // 节点 101 的直接父节点是 100
        $parent = $tree->getParent(101, true);
        $this->assertSame(100, $parent);

        // 节点 103 的直接父节点是 101
        $parent = $tree->getParent(103, true);
        $this->assertSame(101, $parent);

        // 孤儿根 100 没有父节点
        $parent = $tree->getParent(100);
        $this->assertNull($parent);
    }

    /**
     * getParents: 批量查询正常节点和孤儿节点的父节点
     */
    public function testGetParentsBatchMixedNormalAndOrphan()
    {
        $tree = $this->createOrphanTree();

        // 节点4(正常树)的祖先: 2, 1; 节点103(孤儿树)的祖先: 101, 100
        $parents = $tree->getParents([4, 103], false, true);

        $this->assertContains(1, $parents);
        $this->assertContains(2, $parents);
        $this->assertContains(100, $parents);
        $this->assertContains(101, $parents);
        $this->assertCount(4, $parents);

        // 不包含自身
        $this->assertNotContains(4, $parents);
        $this->assertNotContains(103, $parents);
        // 不包含无关节点
        $this->assertNotContains(3, $parents);
        $this->assertNotContains(102, $parents);
    }

    /**
     * getParents: 孤儿节点使用 level 过滤
     */
    public function testGetParentsOrphanWithLevelFilter()
    {
        $tree = $this->createOrphanTree();

        // 节点103 (level 3) 的祖先, 限制 level=1
        $result = $tree->getParents(103, false, true, 1, 1);
        $this->assertContains(100, $result);  // level 1
        $this->assertNotContains(101, $result);  // level 2
        $this->assertCount(1, $result);
    }

    /**
     * getParents: 孤儿节点 withSelf 受 level 过滤影响
     */
    public function testGetParentsOrphanWithSelfRespectsLevelFilter()
    {
        $tree = $this->createOrphanTree();

        // 节点103 (level 3), level 过滤 1-2, withSelf
        $result = $tree->getParents(103, true, true, 1, 2);
        // self(level 3) 不在 [1,2] 范围内, 不应包含
        $this->assertNotContains(103, $result);
        $this->assertContains(100, $result);  // level 1
        $this->assertContains(101, $result);  // level 2
    }

    /**
     * 孤儿树: getChildren 与 getParents 的对偶关系
     */
    public function testOrphanGetChildrenGetParentsDuality()
    {
        $tree = $this->createOrphanTree();

        // 对所有节点 (含孤儿节点) 验证对偶关系
        foreach ($tree->getIndexes() as $id => $index) {
            $children = $tree->getChildren($id, false, true);
            foreach ($children as $childId) {
                $parents = $tree->getParents($childId, false, true);
                $this->assertContains($id, $parents,
                    "节点 {$childId} 是 {$id} 的子孙, 但 {$id} 不在 {$childId} 的祖先中");
            }

            $parents = $tree->getParents($id, false, true);
            foreach ($parents as $parentId) {
                $children = $tree->getChildren($parentId, false, true);
                $this->assertContains($id, $children,
                    "节点 {$parentId} 是 {$id} 的祖先, 但 {$id} 不在 {$parentId} 的子孙中");
            }
        }
    }

    /**
     * 孤儿树: 对每个节点穷举验证 getChildren 与嵌套集合区间的一致性
     */
    public function testOrphanGetChildrenExhaustive()
    {
        $tree = $this->createOrphanTree();
        $indexes = $tree->getIndexes();

        foreach ($indexes as $id => $index) {
            $childrenIds = $tree->getChildren($id, false, true);

            foreach ($indexes as $otherId => $otherIndex) {
                if ($otherId === $id) {
                    continue;
                }
                $isDescendant = $otherIndex->left > $index->left && $otherIndex->right < $index->right;
                if ($isDescendant) {
                    $this->assertContains($otherId, $childrenIds,
                        "节点 {$otherId} 应是 {$id} 的子孙 (区间包含) 但未返回");
                } else {
                    $this->assertNotContains($otherId, $childrenIds,
                        "节点 {$otherId} 不应是 {$id} 的子孙 (区间不包含) 但被返回");
                }
            }
        }
    }

    /**
     * 孤儿树: 对每个节点穷举验证 getParents 与嵌套集合区间的一致性
     */
    public function testOrphanGetParentsExhaustive()
    {
        $tree = $this->createOrphanTree();
        $indexes = $tree->getIndexes();

        foreach ($indexes as $id => $index) {
            $parentIds = $tree->getParents($id, false, true);

            foreach ($indexes as $otherId => $otherIndex) {
                if ($otherId === $id) {
                    continue;
                }
                $isAncestor = $otherIndex->left < $index->left && $otherIndex->right > $index->right;
                if ($isAncestor) {
                    $this->assertContains($otherId, $parentIds,
                        "节点 {$otherId} 应是 {$id} 的祖先 (区间包含) 但未返回");
                } else {
                    $this->assertNotContains($otherId, $parentIds,
                        "节点 {$otherId} 不应是 {$id} 的祖先 (区间不包含) 但被返回");
                }
            }
        }
    }

    /**
     * addNode 添加孤儿节点 (parent 不存在), 成为独立根节点
     */
    public function testAddNodeOrphan()
    {
        $tree = $this->createTree();
        $originalCount = count($tree->getItems());

        // 添加一个 parent 不存在的节点
        $tree->addNode(['id' => 8888, 'parent' => 77777]);

        // 节点应存在
        $this->assertTrue($tree->hasItem(8888));
        $this->assertCount($originalCount + 1, $tree->getItems());

        // 被当作根节点, level=1
        $this->assertSame(1, $tree->getIndex(8888)->level);

        // 没有父节点
        $parents = $tree->getParents(8888, false, true);
        $this->assertEmpty($parents);

        // 没有子节点
        $children = $tree->getChildren(8888, false, true);
        $this->assertEmpty($children);

        // 原有树不受影响
        $children1 = $tree->getChildren(1, false, true);
        $this->assertNotContains(8888, $children1);
    }

    /**
     * addNode 先添加孤儿节点, 再给孤儿节点添加子节点
     */
    public function testAddNodeOrphanThenAddChild()
    {
        $tree = HTree::instance([
            ['id' => 1, 'parent' => 0],
            ['id' => 2, 'parent' => 1],
        ]);

        // 添加孤儿根
        $tree->addNode(['id' => 50, 'parent' => 999]);
        // 给孤儿根添加子节点
        $tree->addNode(['id' => 51, 'parent' => 50]);
        $tree->addNode(['id' => 52, 'parent' => 51]);

        // 验证孤儿子树结构完整
        $children50 = $tree->getChildren(50, false, true);
        $this->assertContains(51, $children50);
        $this->assertContains(52, $children50);
        $this->assertCount(2, $children50);

        $children51 = $tree->getChildren(51, false, true);
        $this->assertContains(52, $children51);
        $this->assertCount(1, $children51);

        // 验证祖先链
        $parents52 = $tree->getParents(52, false, true);
        $this->assertContains(51, $parents52);
        $this->assertContains(50, $parents52);
        $this->assertCount(2, $parents52);

        // 与正常树完全隔离
        $children1 = $tree->getChildren(1, false, true);
        $this->assertNotContains(50, $children1);
        $this->assertNotContains(51, $children1);
        $this->assertNotContains(52, $children1);
    }

    /**
     * 多个独立的孤儿根节点 (来自不同的不存在父节点), 互相隔离
     */
    public function testMultipleOrphanRootsIsolation()
    {
        $tree = HTree::instance([
            // 正常树
            ['id' => 1, 'parent' => 0],
            ['id' => 2, 'parent' => 1],
            // 孤儿 A: parent=888
            ['id' => 10, 'parent' => 888],
            ['id' => 11, 'parent' => 10],
            // 孤儿 B: parent=777
            ['id' => 20, 'parent' => 777],
            ['id' => 21, 'parent' => 20],
            // 孤儿 C: parent=666
            ['id' => 30, 'parent' => 666],
        ]);

        $this->assertCount(7, $tree->getItems());

        // 正常树不包含任何孤儿节点
        $children1 = $tree->getChildren(1, false, true);
        $this->assertSame([2], $children1);

        // 孤儿 A 只包含自己的子孙
        $children10 = $tree->getChildren(10, false, true);
        $this->assertSame([11], $children10);
        $this->assertNotContains(21, $children10);
        $this->assertNotContains(30, $children10);

        // 孤儿 B 只包含自己的子孙
        $children20 = $tree->getChildren(20, false, true);
        $this->assertSame([21], $children20);
        $this->assertNotContains(11, $children20);

        // 孤儿 C 没有子孙
        $children30 = $tree->getChildren(30, false, true);
        $this->assertEmpty($children30);

        // 各孤儿子节点的祖先链互不交叉
        $parents11 = $tree->getParents(11, false, true);
        $this->assertSame([10], $parents11);

        $parents21 = $tree->getParents(21, false, true);
        $this->assertSame([20], $parents21);

        // 各孤儿根都没有父节点
        $this->assertEmpty($tree->getParents(10, false, true));
        $this->assertEmpty($tree->getParents(20, false, true));
        $this->assertEmpty($tree->getParents(30, false, true));
    }

    /**
     * 批量 getChildren 跨正常树和多个孤儿树
     */
    public function testGetChildrenBatchAcrossMultipleOrphanTrees()
    {
        $tree = HTree::instance([
            ['id' => 1, 'parent' => 0],
            ['id' => 2, 'parent' => 1],
            ['id' => 3, 'parent' => 1],
            ['id' => 10, 'parent' => 888],
            ['id' => 11, 'parent' => 10],
            ['id' => 20, 'parent' => 777],
            ['id' => 21, 'parent' => 20],
        ]);

        // 批量获取正常根 + 两个孤儿根的子孙
        $children = $tree->getChildren([1, 10, 20], false, true);
        $expected = [2, 3, 11, 21];
        sort($expected);
        sort($children);
        $this->assertSame($expected, $children);

        // 不包含根节点自身
        $this->assertNotContains(1, $children);
        $this->assertNotContains(10, $children);
        $this->assertNotContains(20, $children);
    }

    /**
     * 批量 getParents 跨正常树和多个孤儿树
     */
    public function testGetParentsBatchAcrossMultipleOrphanTrees()
    {
        $tree = HTree::instance([
            ['id' => 1, 'parent' => 0],
            ['id' => 2, 'parent' => 1],
            ['id' => 3, 'parent' => 2],
            ['id' => 10, 'parent' => 888],
            ['id' => 11, 'parent' => 10],
            ['id' => 12, 'parent' => 11],
        ]);

        // 批量获取正常叶子 + 孤儿叶子的祖先
        $parents = $tree->getParents([3, 12], false, true);
        // 节点3的祖先: 2, 1; 节点12的祖先: 11, 10
        $expected = [1, 2, 10, 11];
        sort($expected);
        sort($parents);
        $this->assertSame($expected, $parents);
    }

    /**
     * 孤儿节点的 getTree 输出: 孤儿根作为独立的顶层节点
     */
    public function testOrphanNodeGetTree()
    {
        $tree = HTree::instance([
            ['id' => 1, 'parent' => 0],
            ['id' => 2, 'parent' => 1],
            ['id' => 10, 'parent' => 888],
            ['id' => 11, 'parent' => 10],
        ]);

        $treeData = $tree->getTree('children', function ($item) {
            return ['id' => $item['id']];
        });

        // 应该有两个顶层节点: 正常根 1 和孤儿根 10
        $this->assertCount(2, $treeData);

        $rootIds = array_column($treeData, 'id');
        sort($rootIds);
        $this->assertSame([1, 10], $rootIds);

        // 每个根下面都有正确的子树
        foreach ($treeData as $rootNode) {
            if ($rootNode['id'] === 1) {
                $this->assertCount(1, $rootNode['children']);
                $this->assertSame(2, $rootNode['children'][0]['id']);
            }
            if ($rootNode['id'] === 10) {
                $this->assertCount(1, $rootNode['children']);
                $this->assertSame(11, $rootNode['children'][0]['id']);
            }
        }
    }

    /**
     * 全部节点 parent 都不存在 — 所有节点都是独立的孤儿根
     */
    public function testAllOrphanNodes()
    {
        $tree = HTree::instance([
            ['id' => 1, 'parent' => 100],
            ['id' => 2, 'parent' => 200],
            ['id' => 3, 'parent' => 300],
        ]);

        $this->assertCount(3, $tree->getItems());

        // 每个节点都是 level=1 的根
        foreach ([1, 2, 3] as $id) {
            $this->assertSame(1, $tree->getIndex($id)->level);
            $this->assertEmpty($tree->getChildren($id, false, true));
            $this->assertEmpty($tree->getParents($id, false, true));
        }

        // 批量查询: 互不影响
        $children = $tree->getChildren([1, 2, 3], false, true);
        $this->assertEmpty($children);

        $parents = $tree->getParents([1, 2, 3], false, true);
        $this->assertEmpty($parents);
    }

    /**
     * 孤儿节点: onlyId=true 和 onlyId=false 结果集一致
     */
    public function testOrphanGetChildrenOnlyIdConsistency()
    {
        $tree = $this->createOrphanTree();

        $ids = $tree->getChildren(100, true, true);
        $nodes = $tree->getChildren(100, true, false);
        $nodeIds = array_keys($nodes);
        sort($ids);
        sort($nodeIds);
        $this->assertSame($ids, $nodeIds);
    }

    /**
     * 孤儿节点: getParents onlyId=true 和 onlyId=false 结果集一致
     */
    public function testOrphanGetParentsOnlyIdConsistency()
    {
        $tree = $this->createOrphanTree();

        $ids = $tree->getParents(103, true, true);
        $nodes = $tree->getParents(103, true, false);
        $nodeIds = array_keys($nodes);
        sort($ids);
        sort($nodeIds);
        $this->assertSame($ids, $nodeIds);
    }

    /**
     * 孤儿节点: treeSort 后 getChildren/getParents 仍然正确
     */
    public function testOrphanTreeSortConsistency()
    {
        $tree = $this->createOrphanTree();

        $sorted = $tree->treeSort(function ($node) {
            return str_pad((string)$this->getNodeId($node), 10, '0', STR_PAD_LEFT);
        });

        // 排序后孤儿树的 getChildren 结果集不变
        $origChildren = $tree->getChildren(100, false, true);
        $sortedChildren = $sorted->getChildren(100, false, true);
        sort($origChildren);
        sort($sortedChildren);
        $this->assertSame($origChildren, $sortedChildren);

        // 排序后孤儿树的 getParents 结果集不变
        $origParents = $tree->getParents(103, false, true);
        $sortedParents = $sorted->getParents(103, false, true);
        sort($origParents);
        sort($sortedParents);
        $this->assertSame($origParents, $sortedParents);
    }

    /**
     * 孤儿节点: treeMap 后 getChildren/getParents 仍然正确
     */
    public function testOrphanTreeMapConsistency()
    {
        $tree = $this->createOrphanTree();

        $mapped = $tree->treeMap(function ($node) {
            $node['mapped'] = true;
            return $node;
        });

        // treeMap 后孤儿树的 getChildren 结果不变
        $origChildren = $tree->getChildren(100, false, true);
        $mappedChildren = $mapped->getChildren(100, false, true);
        $this->assertSame($origChildren, $mappedChildren);

        // treeMap 确实对孤儿节点执行了变换
        $this->assertTrue($mapped->getItem(100)['mapped']);
        $this->assertTrue($mapped->getItem(103)['mapped']);
    }

    /**
     * 构建时输入数据中同时存在正常节点和多种 parent 不存在的情况:
     * parent=0, parent=null, parent=不存在的id
     */
    public function testMixedRootStyles()
    {
        $tree = HTree::instance([
            ['id' => 1, 'parent' => 0],       // 经典根 (parent=0)
            ['id' => 2, 'parent' => null],     // null 根
            ['id' => 3, 'parent' => ''],       // 空字符串根
            ['id' => 4, 'parent' => 999],      // 孤儿根 (parent=999 不存在)
            ['id' => 5, 'parent' => 1],        // 正常子节点
            ['id' => 6, 'parent' => 4],        // 孤儿子节点
        ]);

        $this->assertCount(6, $tree->getItems());

        // 所有 "根" 都是 level=1
        foreach ([1, 2, 3, 4] as $id) {
            $this->assertSame(1, $tree->getIndex($id)->level, "节点 {$id} 应是根 (level=1)");
        }

        // 各自的子树互不影响
        $this->assertSame([5], $tree->getChildren(1, false, true));
        $this->assertEmpty($tree->getChildren(2, false, true));
        $this->assertEmpty($tree->getChildren(3, false, true));
        $this->assertSame([6], $tree->getChildren(4, false, true));

        // 子节点的祖先链正确
        $this->assertSame([1], $tree->getParents(5, false, true));
        $this->assertSame([4], $tree->getParents(6, false, true));
    }

    /**
     * 孤儿节点深层子树: 验证深度 > 3 的孤儿子树
     */
    public function testDeepOrphanSubtree()
    {
        $items = [
            ['id' => 1, 'parent' => 0],
            // 孤儿深链: 100 → 101 → 102 → 103 → 104
            ['id' => 100, 'parent' => 888],
            ['id' => 101, 'parent' => 100],
            ['id' => 102, 'parent' => 101],
            ['id' => 103, 'parent' => 102],
            ['id' => 104, 'parent' => 103],
        ];

        $tree = HTree::instance($items);

        // 孤儿根 100 的所有后代
        $children = $tree->getChildren(100, false, true);
        $this->assertSame([101, 102, 103, 104], $children);

        // 最深叶子 104 的所有祖先
        $parents = $tree->getParents(104, false, true);
        $this->assertSame([100, 101, 102, 103], $parents);

        // level 正确
        $this->assertSame(1, $tree->getIndex(100)->level);
        $this->assertSame(5, $tree->getIndex(104)->level);

        // 与正常树完全隔离
        $this->assertEmpty($tree->getChildren(1, false, true));
        $parents1 = $tree->getParents(104, false, true);
        $this->assertNotContains(1, $parents1);
    }
}
