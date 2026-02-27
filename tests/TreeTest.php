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
        $children = $tree->getChildrenByNids([2, 8], false, true);
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
        $childrenWithSelf = $tree->getChildrenByNids([2, 8], true, true);
        $this->assertContains(2, $childrenWithSelf);
        $this->assertContains(8, $childrenWithSelf);
        $this->assertContains(3, $childrenWithSelf);
        $this->assertContains(9, $childrenWithSelf);

        // 空数组
        $empty = $tree->getChildrenByNids([], false, true);
        $this->assertEmpty($empty);

        // 不存在的节点
        $empty = $tree->getChildrenByNids([$this->randNonExistId()], false, true);
        $this->assertEmpty($empty);

        // onlyId = false 返回节点数据
        $nodesData = $tree->getChildrenByNids([2], false, false);
        foreach ($nodesData as $id => $item) {
            $this->assertSame($id, $this->getNodeId($item));
        }

        // 结果去重：两个节点有共同子孙时不重复
        $children1 = $tree->getChildrenByNids([1], false, true);
        $children12 = $tree->getChildrenByNids([1, 2], false, true);
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

}
