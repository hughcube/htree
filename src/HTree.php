<?php

namespace HughCube\HTree;

use HughCube\HTree\Exceptions\UnableBuildTreeException;

class HTree
{
    /**
     * @var array
     */
    protected $items = [];

    /**
     * items的id的key.
     *
     * @var string
     */
    protected $idKey;

    /**
     * items的parent的key.
     *
     * @var string
     */
    protected $parentKey;

    /**
     * items 数据的索引.
     *
     * @var Index[]
     */
    protected $indexes = [];

    /**
     * index的tree.
     *
     * @var Index[]
     */
    protected $indexTree;

    /**
     * 正在构建的 item.
     *
     * @var array
     */
    protected $buildIndexInProcess = [];

    /**
     * 获取实例.
     *
     * @param  array  $items
     * @param  int|string  $idKey
     * @param  int|string  $parentKey
     * @return static
     */
    public static function instance(
        array $items,
        $idKey = 'id',
        $parentKey = 'parent'
    ) {
        /** @phpstan-ignore-next-line */
        return new static($items, $idKey, $parentKey);
    }

    /**
     * 从嵌套 children 结构创建实例.
     *
     * 将 [{id: 1, children: [{id: 2, children: [...]}]}] 这样的嵌套结构
     * 扁平化并自动添加 parentKey，然后构建 HTree。
     *
     * @param  array  $tree  嵌套树形结构数组
     * @param  int|string  $idKey  id属性的名字
     * @param  int|string  $parentKey  parent属性的名字（自动填充）
     * @param  int|string  $childrenKey  children属性的名字
     * @return static
     */
    public static function fromTree(
        array $tree,
        $idKey = 'id',
        $parentKey = 'parent',
        $childrenKey = 'children'
    ) {
        return static::instance(
            static::flattenTree($tree, $idKey, $parentKey, $childrenKey, null),
            $idKey,
            $parentKey
        );
    }

    /**
     * 递归扁平化嵌套树结构.
     *
     * @param  array  $nodes
     * @param  int|string  $idKey
     * @param  int|string  $parentKey
     * @param  int|string  $childrenKey
     * @param  mixed  $parentId
     * @return array
     */
    protected static function flattenTree(array $nodes, $idKey, $parentKey, $childrenKey, $parentId)
    {
        $items = [];

        foreach ($nodes as $node) {
            $children = $node[$childrenKey] ?? [];
            unset($node[$childrenKey]);
            $node[$parentKey] = $parentId;
            $nodeId = is_object($node) ? $node->{$idKey} : $node[$idKey];
            $items[] = $node;

            if (!empty($children)) {
                $items = array_merge($items, static::flattenTree($children, $idKey, $parentKey, $childrenKey, $nodeId));
            }
        }

        return $items;
    }

    /**
     * Tree constructor.
     *
     * @param  array  $items  构建树形结构的数组, 每个元素必需包含 id, parent 两个属性
     * @param  string  $idKey  id属性的名字
     * @param  string  $parentKey  parent属性的名字
     */
    protected function __construct(array $items, $idKey, $parentKey)
    {
        $this->idKey = $idKey;
        $this->parentKey = $parentKey;

        $this->buildIndex($items);
    }

    /**
     * 获取items.
     *
     * @return array
     */
    public function getItems()
    {
        return $this->items;
    }

    /**
     * 获取单个节点数据.
     *
     * @param  string  $id  id
     *
     * @return mixed|null
     */
    public function getItem($id)
    {
        return isset($this->items[$id]) ? $this->items[$id] : null;
    }

    /**
     * 是否存在某个节点数据.
     *
     * @param  string  $id  id
     *
     * @return bool
     */
    public function hasItem($id)
    {
        return array_key_exists($id, $this->items);
    }

    /**
     * 添加一个节点.
     *
     * @param  array|object  $node  节点数据
     * @return $this
     */
    public function addNode($node)
    {
        $this->pushNodeToTree($node);
        $this->buildIndexTree();

        return $this;
    }

    /**
     * 推送一个节点到树形结构.
     *
     * @param $node
     */
    protected function pushNodeToTree($node)
    {
        $parent = $this->getNodeProperty($node, $this->parentKey);
        $id = $this->getNodeProperty($node, $this->idKey);

        $this->items[$id] = $node;

        if (empty($parent) || !isset($this->indexes[$parent])) {
            $pLevel = $pLeft = 0;
        } else {
            $pLeft = $this->indexes[$parent]->left;
            $pLevel = $this->indexes[$parent]->level;
        }

        /* 改变其他元素的, 给当前节点留出位置 */
        foreach ($this->indexes as $index) {
            if ($index->left > $pLeft) {
                $index->left += 2;
            }

            if ($index->right > $pLeft) {
                $index->right += 2;
            }
        }

        $this->indexes[$id] = new Index([
            'id' => $id,
            'level' => $pLevel + 1,
            'left' => $pLeft + 1,
            'right' => ($pLeft + 1) + 1,
            'parent' => $parent,
        ]);
    }

    /**
     * 构建 index 的树形结构.
     */
    protected function buildIndexTree()
    {
        $this->indexTree = [];

        // 先清空所有 Index 的 children，避免重复添加
        foreach ($this->indexes as $index) {
            $index->children = [];
        }

        foreach ($this->indexes as $index) {
            if (isset($this->indexes[$index->parent])) {
                $this->indexes[$index->parent]->addChild($index);
            } else {
                $this->indexTree[$index->id] = $index;
            }
        }
    }

    /**
     * 获取节点的子节点.
     *
     * @param  string  $nid  节点id
     * @param  bool  $onlyId  是否只返回 id
     * @param  null|int  $startLevel  往下多少级-起始, 为空不限制
     * @param  null|int  $endLevel  往下多少级-结束, 为空不限制
     * @param  bool  $withSelf  结果是否包括自己
     *
     * @return array
     */
    public function getChildren(
        $nid,
        $withSelf = false,
        $onlyId = false,
        $startLevel = null,
        $endLevel = null
    ) {
        $nodes = $parents = [];

        /* 先收集一次, 防止父 $nid 不存在不能被收集 */
        foreach ($this->items as $id => $item) {
            $parentValue = $this->getNodeProperty($item, $this->parentKey);
            if (strval($nid) === strval($parentValue)) {
                $parents[] = $id;
                $nodes[$id] = $item;
            }
        }

        /* 收集所有的子节点 */
        foreach ($parents as $parent) {
            foreach ($this->indexes as $index) {
                if ($index->left > $this->indexes[$parent]->left
                    && $index->right < $this->indexes[$parent]->right
                ) {
                    $nodes[$index->id] = $this->items[$index->id];
                }
            }
        }

        /* 过滤不符合的节点 */
        foreach ($nodes as $id => $node) {
            $level = $this->indexes[$id]->level;
            if ((null === $startLevel || $startLevel <= $level)
                && (null === $endLevel || $endLevel >= $level)
            ) {
                continue;
            }

            unset($nodes[$id]);
        }

        /* 是否返回自己本身 */
        if ($withSelf && $this->hasItem($nid)) {
            $nodes[$nid] = $this->getItem($nid);
        }

        /* 排序 */
        uasort($nodes, function ($a, $b) {
            $aLevel = $this
                ->getIndex($this->getNodeProperty($a, $this->idKey))->level;

            $bLevel = $this
                ->getIndex($this->getNodeProperty($b, $this->idKey))->level;

            return ($aLevel == $bLevel) ? 0 : ($aLevel > $bLevel ? 1 : -1);
        });

        if ($onlyId) {
            return array_keys($nodes);
        }

        return $nodes;
    }

    /**
     * 获取某一节点的父节点.
     *
     * @param  string  $nid  节点id
     * @param  bool  $onlyId  是否只返回 id
     * @param  null|int  $level  取第几级父节点, 默认取上一级
     *
     * @return int|mixed|null|string
     */
    public function getParent($nid, $onlyId = false, $level = null)
    {
        $index = $this->getIndex($nid);
        if (!$index instanceof Index) {
            return;
        }

        $level = null === $level ? ($index->level - 1) : $level;

        $parents = $this->getParents($nid, false, $onlyId, $level, $level);
        $parents = array_values($parents);

        return isset($parents[0]) ? $parents[0] : null;
    }

    /**
     * 获取某一节点的所有父节点.
     *
     * @param  string  $nid  节点id
     * @param  bool  $onlyId  是否只返回 id
     * @param  null|int  $startLevel  往上多少级-起始, 为空不限制
     * @param  null|int  $endLevel  往上多少级-结束, 为空不限制
     *
     * @return array
     */
    public function getParents(
        $nid,
        $withSelf = false,
        $onlyId = false,
        $startLevel = null,
        $endLevel = null
    ) {
        $nodes = [];

        /* 是否返回自己本身 */
        if ($withSelf && $this->hasItem($nid)) {
            $nodes[$nid] = $this->getItem($nid);
        }

        if ($this->hasItem($nid)) {
            foreach ($this->indexes as $id => $index) {
                if ($index->left < $this->indexes[$nid]->left
                    && $index->right > $this->indexes[$nid]->right
                ) {
                    $nodes[$id] = $this->items[$id];
                }
            }
        }

        foreach ($nodes as $id => $node) {
            $level = $this->indexes[$id]->level;
            if ((null === $startLevel || $startLevel <= $level)
                && (null === $endLevel || $endLevel >= $level)
            ) {
                continue;
            }

            unset($nodes[$id]);
        }

        /* 排序 */
        uasort($nodes, function ($a, $b) {
            $aLevel = $this
                ->getIndex($this->getNodeProperty($a, $this->idKey))->level;

            $bLevel = $this
                ->getIndex($this->getNodeProperty($b, $this->idKey))->level;

            return ($aLevel == $bLevel) ? 0 : ($aLevel > $bLevel ? 1 : -1);
        });

        if ($onlyId) {
            return array_keys($nodes);
        }

        return $nodes;
    }

    /**
     * 树排序.
     *
     * @param  callable  $cmpSortCallable
     * @param  int  $sortType  SORT_ASC | SORT_DESC
     *
     * @return static
     *
     * 如果数字很长, 可以填充为相等长度的字符串, 使用0填充
     *
     * @see https://www.php.net/manual/en/function.strcmp.php
     */
    public function treeSort(callable $cmpSortCallable, $sortType = SORT_DESC)
    {
        $instance = clone $this;
        $instance->deepCloneIndexes();

        $instance->indexTree = $instance->recursiveTreeSort(
            $instance->indexTree,
            $cmpSortCallable,
            $sortType
        );

        return $instance;
    }

    /**
     * 深拷贝 indexes，避免修改原始对象.
     */
    protected function deepCloneIndexes()
    {
        $newIndexes = [];
        foreach ($this->indexes as $id => $index) {
            $newIndexes[$id] = clone $index;
            $newIndexes[$id]->children = [];
        }
        $this->indexes = $newIndexes;
        $this->buildIndexTree();
    }

    /**
     * 递归排序.
     *
     * @param  Index[]  $indexes
     * @param  callable  $cmpSortCallable
     * @param  int  $sortType  SORT_ASC | SORT_DESC
     *
     * @return Index[]
     */
    protected function recursiveTreeSort(
        $indexes,
        callable $cmpSortCallable,
        $sortType
    ) {
        /** @var Index $index */
        foreach ($indexes as $index) {
            $index->children = $this->recursiveTreeSort(
                $index->children,
                $cmpSortCallable,
                $sortType
            );
        }

        uasort(
            $indexes,
            function (Index $a, Index $b) use ($cmpSortCallable, $sortType) {
                $aSort = $cmpSortCallable($this->items[$a->id]);
                $bSort = $cmpSortCallable($this->items[$b->id]);

                $cmp = strcmp($aSort, $bSort);
                if (SORT_ASC == $sortType) {
                    return (0 == $cmp) ? 0 : ($cmp > 0 ? 1 : -1);
                } else {
                    return (0 == $cmp) ? 0 : ($cmp > 0 ? -1 : 1);
                }
            }
        );

        return $indexes;
    }

    /**
     * 递归遍历每一个元素, 按照指定的顺, 并且可以改变元素的值
     *
     * @param  callable  $callable  返回值作为该元素新的值
     *
     * @return $this
     */
    public function treeMap(callable $callable)
    {
        $instance = clone $this;

        $instance->recursiveTreeMap($instance->indexTree, $callable);

        return $instance;
    }

    /**
     * 递归遍历每一个元素.
     *
     * @param  Index[]  $indexes
     * @param  callable  $callable
     */
    protected function recursiveTreeMap($indexes, $callable)
    {
        foreach ($indexes as $index) {
            $this->items[$index->id] = $callable($this->items[$index->id]);
            $this->recursiveTreeMap($index->children, $callable);
        }
    }

    /**
     * 获取树结构.
     *
     * @param  string  $childrenKey  子集的数组key
     * @param  callable  $format  格式化返回的元素
     * @param  bool  $keepEmptyChildrenKey  是否保留空的ChildrenKey
     *
     * @return array
     */
    public function getTree(
        $childrenKey = 'children',
        $format = null,
        $keepEmptyChildrenKey = true
    ) {
        return $this->recursiveGetTree(
            $this->indexTree,
            $childrenKey,
            $format,
            $keepEmptyChildrenKey
        );
    }

    /**
     * 递归遍历每一个元素.
     *
     * @param  array  $indexes
     * @param  int|string  $childrenKey
     * @param  null|callable  $format
     * @param bool $keepEmptyChildrenKey
     * @return array
     */
    protected function recursiveGetTree(
        $indexes,
        $childrenKey,
        $format,
        $keepEmptyChildrenKey
    ) {
        $nodes = [];
        foreach ($indexes as $index) {
            $node = null === $format
                ? $this->items[$index->id]
                : $format($this->items[$index->id]);

            $children = $this->recursiveGetTree(
                $index->children,
                $childrenKey,
                $format,
                $keepEmptyChildrenKey
            );
            if (!empty($children) || (bool)$keepEmptyChildrenKey) {
                $node[$childrenKey] = $children;
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * 构建 index.
     */
    protected function buildIndex(array $items)
    {
        $this->indexTree = $this->buildIndexInProcess = [];

        $_ = [];
        foreach ($items as $item) {
            $_[$this->getNodeProperty($item, $this->idKey)] = $item;
        }

        foreach ($_ as $id => $item) {
            if (!isset($this->indexes[$id])) {
                $this->recursiveBuildIndex($id, $_);
            }
        }

        $this->buildIndexTree();
    }

    /**
     * 递归构建 index.
     *
     * @param $id
     */
    protected function recursiveBuildIndex($id, &$items)
    {
        if (in_array($id, $this->buildIndexInProcess)) {
            throw new UnableBuildTreeException(
                $this->buildIndexInProcess,
                '不能构建成一个树形'
            );
        }

        $this->buildIndexInProcess[$id] = $id;

        /** @var int $parent 需要处理的节点父节点id */
        $parent = $this->getNodeProperty($items[$id], $this->parentKey);

        /* 如果存在父节点, 并且父节点没有被被处理, 先处理父节点 */
        if (isset($items[$parent]) && !isset($this->indexes[$parent])) {
            $this->recursiveBuildIndex($parent, $items);
        }

        /* 添加节点 */
        $this->pushNodeToTree($items[$id]);
        unset($this->buildIndexInProcess[$id]);
    }

    /**
     * @param $id
     *
     * @return Index
     */
    public function getIndex($id)
    {
        return isset($this->indexes[$id]) ? $this->indexes[$id] : null;
    }

    /**
     * @return Index[]
     */
    public function getIndexes()
    {
        return $this->indexes;
    }

    /**
     * 获取数据节点的属性.
     *
     * @param  array|object  $node
     * @param  string  $name
     *
     * @return mixed
     */
    private function getNodeProperty($node, $name)
    {
        if (is_object($node)) {
            return $node->{$name};
        }

        return $node[$name];
    }
}
