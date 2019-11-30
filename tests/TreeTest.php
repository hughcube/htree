<?php

namespace HughCube\HTree\Tests;

use HughCube\HTree\Exceptions\UnableBuildTreeException;
use HughCube\HTree\HTree;
use HughCube\HTree\Index;
use PHPUnit\Framework\TestCase;

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

    /**
     * @param HTree $tree
     * @dataProvider dataProviderTree
     */
    public function testInstance(HTree $tree)
    {
        $this->assertInstanceOf(HTree::class, $tree);
    }

    /**
     * @param HTree $tree
     * @dataProvider dataProviderTree
     */
    public function testGetItems(HTree $tree)
    {
        $items = $tree->getItems();
        foreach ($this->getItemKeyById() as $id => $item) {
            $this->assertArrayHasKey($id, $items);
            $this->assertSame($id, $item['id']);

            $this->assertItemSame($id, $item['id']);
        }
    }

    /**
     * @param HTree $tree
     * @dataProvider dataProviderTree
     *
     * @throws \Exception
     */
    public function testGetItem(HTree $tree)
    {
        $items = $this->getItemKeyById();
        foreach ($items as $id => $item) {
            $this->assertItemSame($tree->getItem($id), $items[$id]);
        }
        $this->assertSame($tree->getItem($this->randNonExistId()), null);
    }

    /**
     * @param HTree $tree
     * @dataProvider dataProviderTree
     *
     * @throws \Exception
     */
    public function hasItem(HTree $tree)
    {
        $items = $this->getItemKeyById();

        foreach ($items as $id => $item) {
            $this->assertTrue($tree->hasItem($id));
        }

        $this->assertFalse($tree->hasItem($this->randNonExistId()));
    }

    /**
     * @param HTree $tree
     * @dataProvider dataProviderTree
     */
    public function testAddNode(HTree $tree)
    {
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
     * @param HTree $tree
     * @dataProvider dataProviderTree
     */
    public function testGetChildren(HTree $tree)
    {
        $this->markTestSkipped();
    }

    /**
     * @param HTree $tree
     * @dataProvider dataProviderTree
     */
    public function testGetParent(HTree $tree)
    {
        $this->markTestSkipped();
    }

    /**
     * @param HTree $tree
     * @dataProvider dataProviderTree
     */
    public function testGetParents(HTree $tree)
    {
        $this->markTestSkipped();
    }

    /**
     * @param HTree $tree
     * @dataProvider dataProviderTree
     */
    public function testTreeSort(HTree $tree)
    {
        $this->assertNotSame($tree, $tree->treeSort(function () {
            return 1;
        }));
    }

    /**
     * @param HTree $tree
     * @dataProvider dataProviderTree
     */
    public function testTreeMap(HTree $tree)
    {
        $this->assertNotSame($tree, $tree->treeMap(function () {
            return 1;
        }));
    }

    /**
     * @param HTree $tree
     * @dataProvider dataProviderTree
     */
    public function testGetTree(HTree $tree)
    {
        $this->markTestSkipped();
    }

    /**
     * @param HTree $tree
     * @dataProvider dataProviderTree
     */
    public function testGetIndex(HTree $tree)
    {
        $items = $this->getItemKeyById();

        foreach ($tree->getItems() as $id => $item) {
            $index = $tree->getIndex($id);
            $this->assertObjectHasAttribute('id', $index);
            $this->assertTrue(isset($items[$index->id]));

            $this->assertObjectHasAttribute('level', $index);
            $this->assertTrue(is_int($index->level));

            $this->assertObjectHasAttribute('left', $index);
            $this->assertTrue(is_int($index->left));

            $this->assertObjectHasAttribute('right', $index);
            $this->assertTrue(is_int($index->right));

            $this->assertObjectHasAttribute('parent', $index);
            $this->assertTrue(
                null == $index->parent || isset($items[$index->parent])
            );
        }
    }

    protected function getExistIds()
    {
        return array_column($this->getItems(), 'id');
    }

    /**
     * @throws
     *
     * @return string
     */
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
     * @param $a
     * @param $b
     */
    protected function assertItemSame($a, $b)
    {
        $indexToArray = function (Index $index) {
            return ['id' => $index->id, 'parent' => $index->parent];
        };
        $a = $a instanceof Index ? $indexToArray($a) : $a;
        $b = $b instanceof Index ? $indexToArray($b) : $b;

        $this->assertSame($a, $b);
    }

    /**
     * @return HTree[]
     */
    public function dataProviderTree()
    {
        return [
            [
                HTree::instance($this->getItems(), 'id', 'parent'),
            ],
            [
                HTree::instance(HTree::instance($this->getItems())->getIndexes()),
            ],
        ];
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
}
