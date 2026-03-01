<?php

namespace HughCube\HTree;

use HughCube\HTree\Exceptions\UnableBuildTreeException;

/**
 * HTree — 基于嵌套集合模型 (Nested Set Model) 的内存树形结构.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │                        整体架构概览                               │
 * ├──────────────────────────────────────────────────────────────────┤
 * │                                                                  │
 * │  输入数据 (扁平数组)          内部索引 (嵌套集合)                    │
 * │  ┌─────────────────┐         ┌─────────────────────┐            │
 * │  │ id=1, parent=0  │  ──→    │ id=1, L=1, R=10     │            │
 * │  │ id=2, parent=1  │  ──→    │ id=2, L=2, R=7      │            │
 * │  │ id=3, parent=2  │  ──→    │ id=3, L=3, R=4      │            │
 * │  │ id=4, parent=2  │  ──→    │ id=4, L=5, R=6      │            │
 * │  │ id=5, parent=1  │  ──→    │ id=5, L=8, R=9      │            │
 * │  └─────────────────┘         └─────────────────────┘            │
 * │                                                                  │
 * │  逻辑树结构:                  嵌套集合区间可视化:                    │
 * │       1                       1[======================]10        │
 * │      / \                      . 2[==========]7  8[==]9           │
 * │     2   5                     . . 3[=]4  5[=]6  . .. .           │
 * │    / \                                                           │
 * │   3   4                                                          │
 * │                                                                  │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *
 * ## 核心原理: 嵌套集合模型 (Nested Set Model)
 *
 * 嵌套集合模型是一种将层级树结构编码为一维数值区间的方法。
 * 它为每个节点分配两个整数 left 和 right, 相当于对树进行深度优先遍历时,
 * 进入节点时记录一个递增序号 (left), 离开节点时再记录一个递增序号 (right)。
 *
 * 关键性质:
 *   1. 子孙判断: B 是 A 的子孙 ⟺ A.left < B.left && B.right < A.right
 *      (B 的区间被 A 的区间严格包含)
 *   2. 祖先判断: A 是 B 的祖先 ⟺ A.left < B.left && B.right < A.right
 *      (与子孙判断等价, 只是视角不同)
 *   3. 子孙计数: 节点的子孙数量 = (right - left - 1) / 2
 *   4. 叶子判断: 节点是叶子节点 ⟺ right = left + 1
 *   5. 区间唯一: 所有 left/right 值在整棵树中唯一, 取值 1 ~ 2N
 *
 *
 * ## 内部数据结构
 *
 * HTree 内部维护三组相互关联的数据:
 *
 *   $items    — 原始节点数据, 以 id 为键的关联数组
 *               保存用户传入的原始数据 (数组或对象), 不做任何修改
 *
 *   $indexes  — 嵌套集合索引, 以 id 为键的 Index 对象数组
 *               每个 Index 记录节点的 id, level, left, right, parent
 *               这是所有查询操作的核心数据结构
 *
 *   $indexTree — 树形结构的根节点列表, Index 对象数组
 *               通过 Index::$children 形成树形引用链
 *               支撑 getTree, treeSort, treeMap 等需要按树形递归的操作
 *
 *
 * ## 构建过程 (buildIndex)
 *
 * 输入数据可以是无序的扁平数组, 构建过程会自动处理依赖关系:
 *
 *   1. 以 id 为键重新索引输入数据
 *   2. 遍历每个节点, 如果其父节点尚未建立索引, 则递归先处理父节点 (拓扑排序思想)
 *   3. 处理过程中检测循环依赖 (A→B→A), 发现则抛出 UnableBuildTreeException
 *   4. 每个节点通过 pushNodeToTree 插入嵌套集合:
 *      - 将新节点紧贴在父节点的 left 值之后插入
 *      - 所有 left/right 大于插入位置的已有节点, 其 left/right 各加 2 (为新节点腾出空间)
 *      - 新节点的 left = pLeft+1, right = pLeft+2, level = pLevel+1
 *   5. 最后通过 buildIndexTree 根据 parent 关系构建树形引用链
 *
 *
 * ## 查询算法
 *
 * ### getChildren — 获取子孙节点
 *
 *   采用 "区间合并 + 单次遍历" 策略:
 *
 *   1. 收集所有输入节点的 [left, right] 区间
 *   2. 合并重叠/包含的区间 (mergeRanges):
 *      - 在嵌套集合中, 如果同时传入父节点和子节点, 子节点的区间必然被父节点包含
 *      - 合并后可以消除冗余, 例如传入 [1, 2] 其中 2 是 1 的子孙,
 *        合并后只保留节点 1 的区间
 *   3. 单次遍历所有 indexes, 判断每个节点是否落在某个合并后的区间内:
 *      index.left > range.left && index.right < range.right
 *   4. 可选的 level 过滤在遍历中一并完成
 *
 *   复杂度: O(N + M·log M), 其中 N 为总节点数, M 为输入节点数
 *
 * ### getParents — 获取祖先节点
 *
 *   采用 "parent 链向上遍历" 策略:
 *
 *   1. 从输入节点出发, 沿 Index::$parent 链逐级向上走
 *   2. 每经过一个祖先节点, 检查是否符合 level 过滤条件
 *   3. 遇到 parent 不在 indexes 中时 (到达根节点) 停止
 *   4. 使用 visited 集合防止循环引用导致死循环
 *
 *   复杂度: O(P × D), 其中 P 为输入节点数, D 为树的最大深度
 *   相比旧的全表扫描 O(P × N) 大幅优化
 *
 * ### getParent — 获取某一级父节点
 *
 *   是 getParents 的便捷封装:
 *   默认返回直接父节点 (level = 当前节点 level - 1),
 *   也可以通过 $level 参数指定获取某一级的祖先
 *
 *
 * ## 变换操作
 *
 * ### treeSort — 按自定义规则对同级兄弟节点排序
 *
 *   1. 深拷贝 (deep clone) 所有 Index 对象, 确保排序不影响原始树
 *   2. 自底向上递归: 先排序每个节点的子节点, 再排序当前层级的兄弟节点
 *   3. 排序基于用户提供的 callable, 该函数返回字符串作为排序键, 通过 strcmp 比较
 *
 * ### treeMap — 对每个节点应用变换函数
 *
 *   1. 浅拷贝 HTree 实例 (clone), items 数组是值类型所以自动独立
 *   2. 按 indexTree 的树形顺序递归遍历, 对每个节点调用 callable
 *   3. callable 的返回值替换原始节点数据
 *
 * ### getTree — 将内部结构还原为嵌套数组
 *
 *   按 indexTree 的树形结构递归遍历, 为每个节点添加 children 属性,
 *   还原出 [{id: 1, children: [{id: 2, children: [...]}]}] 的嵌套结构.
 *   支持 format 回调自定义每个节点的输出格式,
 *   支持 keepEmptyChildrenKey 控制叶子节点是否保留空的 children 数组.
 *
 *
 * ## 使用示例
 *
 * ```php
 * // 从扁平数组构建
 * $tree = HTree::instance([
 *     ['id' => 1, 'parent' => 0, 'name' => '根节点'],
 *     ['id' => 2, 'parent' => 1, 'name' => '子节点A'],
 *     ['id' => 3, 'parent' => 1, 'name' => '子节点B'],
 *     ['id' => 4, 'parent' => 2, 'name' => '孙节点'],
 * ]);
 *
 * // 从嵌套结构构建
 * $tree = HTree::fromTree([
 *     ['id' => 1, 'name' => '根', 'children' => [
 *         ['id' => 2, 'name' => '子A', 'children' => [
 *             ['id' => 4, 'name' => '孙'],
 *         ]],
 *         ['id' => 3, 'name' => '子B'],
 *     ]],
 * ]);
 *
 * // 查询
 * $tree->getChildren(1, false, true);       // [2, 3, 4] — 所有后代的 id
 * $tree->getParents(4, false, true);        // [1, 2]    — 所有祖先的 id
 * $tree->getParent(4, true);               // 2         — 直接父节点 id
 * $tree->getChildren(1, true, true, 2, 2); // [1, 2, 3] — level=2 的后代 + 自身
 *
 * // 输出嵌套结构
 * $tree->getTree('children');  // 还原为嵌套数组
 * ```
 */
class HTree
{
    /**
     * 原始节点数据, 以 id 为键的关联数组.
     *
     * 保存用户传入的原始数据, 支持数组和对象两种格式.
     * 键为节点 id, 值为节点的完整数据.
     *
     * @var array
     */
    protected $items = [];

    /**
     * 节点 id 属性在原始数据中的键名.
     *
     * 默认为 'id', 可在构造时自定义.
     * 例如数据格式为 ['key' => 'abc', 'pid' => 'xyz'], 则 idKey 应设为 'key'.
     *
     * @var string
     */
    protected $idKey;

    /**
     * 节点 parent 属性在原始数据中的键名.
     *
     * 默认为 'parent', 可在构造时自定义.
     * 该属性值指向父节点的 id, 根节点的 parent 通常为 0 或 null.
     *
     * @var string
     */
    protected $parentKey;

    /**
     * 嵌套集合索引, 以 id 为键的 Index 对象数组.
     *
     * 这是所有查询操作 (getChildren, getParents, getParent) 的核心数据结构.
     * 每个 Index 对象记录节点在嵌套集合模型中的位置: id, level, left, right, parent.
     *
     * @var Index[]
     */
    protected $indexes = [];

    /**
     * 树形结构的根节点索引列表.
     *
     * 通过 Index::$children 引用链形成完整的树形结构.
     * 是 getTree, treeSort, treeMap 等树形递归操作的入口.
     * 在多根树 (forest) 的场景下, 这里会包含多个根节点.
     *
     * @var Index[]
     */
    protected $indexTree;

    /**
     * 构建索引过程中的环检测栈.
     *
     * 记录当前递归链路上正在处理的节点 id.
     * 如果在递归处理某个节点时发现它已经在栈中, 说明存在循环依赖,
     * 此时抛出 UnableBuildTreeException.
     *
     * 仅在 buildIndex/recursiveBuildIndex 过程中使用, 构建完成后清空.
     *
     * @var array
     */
    protected $buildIndexInProcess = [];

    /**
     * 从扁平数组创建 HTree 实例 (工厂方法).
     *
     * 输入数组中每个元素必须包含 id 和 parent 属性 (属性名可通过参数自定义).
     * 数组无需按照父子顺序排列, 内部构建过程会自动处理依赖关系.
     *
     * @param array $items 节点数据的扁平数组, 每个元素为数组或对象
     * @param int|string $idKey 节点 id 属性的键名, 默认 'id'
     * @param int|string $parentKey 节点 parent 属性的键名, 默认 'parent'
     * @return static
     */
    public static function instance(array $items, $idKey = 'id', $parentKey = 'parent')
    {
        /** @phpstan-ignore-next-line */
        return new static($items, $idKey, $parentKey);
    }

    /**
     * 从嵌套 children 结构创建 HTree 实例.
     *
     * 接受前端常见的嵌套树形格式:
     *   [{id: 1, children: [{id: 2, children: [...]}]}]
     *
     * 内部流程:
     *   1. 调用 flattenTree 递归遍历嵌套结构, 将其扁平化为一维数组
     *   2. 在扁平化过程中自动为每个节点填充 parentKey 属性
     *   3. 移除节点数据中的 childrenKey 属性 (避免冗余)
     *   4. 用扁平化后的数组调用 instance 构建 HTree
     *
     * @param array $tree 嵌套树形结构数组
     * @param int|string $idKey id 属性的键名, 默认 'id'
     * @param int|string $parentKey parent 属性的键名 (扁平化时自动填充), 默认 'parent'
     * @param int|string $childrenKey children 属性的键名, 默认 'children'
     * @return static
     */
    public static function fromTree(array $tree, $idKey = 'id', $parentKey = 'parent', $childrenKey = 'children')
    {
        $items = static::flattenTree($tree, $idKey, $parentKey, $childrenKey, null);

        return static::instance($items, $idKey, $parentKey);
    }

    /**
     * 递归扁平化嵌套树结构.
     *
     * 深度优先遍历嵌套结构, 对每个节点:
     *   1. 先提取 children 子数组 (若不存在则默认空数组)
     *   2. 从节点数据中删除 childrenKey 属性
     *   3. 为节点设置 parentKey 属性 (值为父节点的 id, 顶层节点为 null)
     *   4. 将节点加入结果数组
     *   5. 递归处理 children, 以当前节点的 id 作为子节点的 parentId
     *
     * @param array $nodes 当前层级的节点数组
     * @param int|string $idKey id 属性的键名
     * @param int|string $parentKey parent 属性的键名
     * @param int|string $childrenKey children 属性的键名
     * @param mixed $parentId 当前层级节点的父节点 id, 顶层为 null
     * @return array 扁平化后的一维节点数组
     */
    protected static function flattenTree(array $nodes, $idKey, $parentKey, $childrenKey, $parentId)
    {
        $items = [];

        foreach ($nodes as $node) {
            /* 先提取 children, 再删除, 确保递归时 children 数据可用 */
            $children = static::getNodeProperty($node, $childrenKey, []);

            if (array_key_exists($childrenKey, $node)) {
                unset($node[$childrenKey]);
            }

            /* 设置父节点关系 */
            $node[$parentKey] = $parentId;
            $nodeId = static::getNodeProperty($node, $idKey);

            $items[] = $node;

            /* 递归处理子节点 */
            if (!empty($children)) {
                $items = array_merge($items, static::flattenTree($children, $idKey, $parentKey, $childrenKey, $nodeId));
            }
        }

        return $items;
    }

    /**
     * 构造函数 (protected, 通过 instance/fromTree 工厂方法创建).
     *
     * @param array $items 构建树形结构的数组, 每个元素必须包含 id 和 parent 属性
     * @param string $idKey id 属性的键名
     * @param string $parentKey parent 属性的键名
     */
    protected function __construct(array $items, $idKey, $parentKey)
    {
        $this->idKey = $idKey;
        $this->parentKey = $parentKey;

        $this->buildIndex($items);
    }

    /**
     * 获取所有节点的原始数据.
     *
     * @return array 以 id 为键的关联数组
     */
    public function getItems()
    {
        return $this->items;
    }

    /**
     * 获取单个节点的原始数据.
     *
     * @param int|string $id 节点 id
     * @return mixed|null 节点数据, 不存在返回 null
     */
    public function getItem($id)
    {
        return isset($this->items[$id]) ? $this->items[$id] : null;
    }

    /**
     * 判断是否存在指定 id 的节点.
     *
     * @param int|string $id 节点 id
     * @return bool
     */
    public function hasItem($id)
    {
        return array_key_exists($id, $this->items);
    }

    /**
     * 动态添加一个节点到已构建的树中.
     *
     * 新节点会被插入到其父节点的 left 位置之后, 并自动调整其他节点的 left/right 值.
     * 添加完成后会重建 indexTree 树形引用链.
     *
     * 注意: addNode 不会进行循环依赖检测.
     * 如果 parent 指向不存在的节点, 新节点会被当作根节点处理.
     *
     * @param array|object $node 节点数据, 必须包含 id 和 parent 属性
     * @return $this
     */
    public function addNode($node)
    {
        $this->pushNodeToTree($node);
        $this->buildIndexTree();

        return $this;
    }

    /**
     * 将一个节点插入到嵌套集合模型中.
     *
     * 插入算法:
     *   1. 读取节点的 id 和 parent
     *   2. 如果 parent 不存在或不在 indexes 中, 作为根节点处理 (pLevel=0, pLeft=0)
     *   3. 所有 left > pLeft 的节点, left += 2 (为新节点腾出空间)
     *   4. 所有 right > pLeft 的节点, right += 2
     *   5. 新节点: left = pLeft+1, right = pLeft+2, level = pLevel+1
     *
     * 腾位示意 (在父节点 P 的 left 之后插入新节点 N):
     *
     *   插入前: P[1, ....., 8]
     *   插入后: P[1, N[2,3], ....., 10]  (原来的值从 2 起全部 +2)
     *
     * @param array|object $node 节点数据
     */
    protected function pushNodeToTree($node)
    {
        $parent = $this->getNodeProperty($node, $this->parentKey);
        $id = $this->getNodeProperty($node, $this->idKey);

        /* 存储原始节点数据 */
        $this->items[$id] = $node;

        /* 确定父节点的 level 和 left (用于计算新节点的位置) */
        if (empty($parent) || !isset($this->indexes[$parent])) {
            /* 根节点: parent 不存在或不在 indexes 中 */
            $pLevel = $pLeft = 0;
        } else {
            $pLeft = $this->indexes[$parent]->left;
            $pLevel = $this->indexes[$parent]->level;
        }

        /* 为新节点腾出 left/right 空间: 所有位于插入点之后的值各 +2 */
        foreach ($this->indexes as $index) {
            if ($index->left > $pLeft) {
                $index->left += 2;
            }

            if ($index->right > $pLeft) {
                $index->right += 2;
            }
        }

        /* 创建新节点的 Index, 紧贴在父节点 left 之后 */
        $this->indexes[$id] = new Index([
            'id' => $id,
            'level' => $pLevel + 1,
            'left' => $pLeft + 1,
            'right' => ($pLeft + 1) + 1,
            'parent' => $parent,
        ]);
    }

    /**
     * 根据 Index::$parent 关系构建树形引用链.
     *
     * 遍历所有 indexes:
     *   - 如果节点的 parent 在 indexes 中存在, 将节点添加到父节点的 children 数组
     *   - 如果 parent 不在 indexes 中 (根节点), 将节点加入 indexTree 根列表
     *
     * 调用时机: 初始构建完成后, 以及每次 addNode 后.
     */
    protected function buildIndexTree()
    {
        $this->indexTree = [];

        /* 先清空所有 children, 避免重复添加 (addNode 场景下会多次调用) */
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
     * 获取节点的所有子孙节点.
     *
     * 支持传入单个 id 或 id 数组进行批量查询.
     *
     * ## 算法: 区间合并 + 单次遍历
     *
     * 利用嵌套集合模型的核心性质:
     *   节点 B 是节点 A 的子孙 ⟺ A.left < B.left && B.right < A.right
     *
     * 步骤:
     *   1. 收集所有输入节点的 [left, right] 区间
     *   2. 调用 mergeRanges 合并重叠/包含的区间
     *      例: 传入父节点和子节点, 子节点区间被父节点包含, 合并后只保留父节点区间
     *   3. 单次遍历 indexes, 对每个节点检查是否被某个合并后的区间严格包含
     *   4. level 过滤在遍历中一并完成, 避免二次遍历
     *
     * ## withSelf 行为
     *
     * withSelf 不受 startLevel/endLevel 过滤影响.
     * 即使输入节点不在 level 范围内, withSelf=true 时仍会包含自身.
     *
     * ## 返回值排序
     *
     * 结果按 level 从小到大排序 (浅层节点在前).
     *
     * @param string|int|array $nid 节点 id 或节点 id 数组
     * @param bool $withSelf 结果是否包含输入节点自身, 默认 false
     * @param bool $onlyId true 返回 id 数组, false 返回 id=>节点数据 的关联数组
     * @param null|int $startLevel 仅返回 level >= startLevel 的节点, null 不限制
     * @param null|int $endLevel 仅返回 level <= endLevel 的节点, null 不限制
     * @return array
     */
    public function getChildren($nid, $withSelf = false, $onlyId = false, $startLevel = null, $endLevel = null)
    {
        $nids = is_array($nid) ? $nid : [$nid];
        $nodes = [];

        /* 第一步: 收集输入节点的 [left, right] 区间 */
        $ranges = [];
        foreach ($nids as $id) {
            if (isset($this->indexes[$id])) {
                $ranges[] = [$this->indexes[$id]->left, $this->indexes[$id]->right];
            }
        }

        if (!empty($ranges)) {
            /* 第二步: 合并重叠/包含的区间, 消除冗余比较 */
            $merged = $this->mergeRanges($ranges);

            /* 第三步: 单次遍历, 找出所有落在合并区间内的子孙节点 */
            foreach ($this->indexes as $id => $index) {
                /* level 过滤: 不在范围内的直接跳过 */
                if (null !== $startLevel && $index->level < $startLevel) {
                    continue;
                }
                if (null !== $endLevel && $index->level > $endLevel) {
                    continue;
                }

                /* 判断是否被某个区间严格包含 (即是否为某个输入节点的子孙) */
                foreach ($merged as $range) {
                    if ($index->left > $range[0] && $index->right < $range[1]) {
                        $nodes[$id] = $this->items[$id];
                        break; // 已确认是子孙, 无需检查其他区间
                    }
                }
            }
        }

        /* withSelf: 不受 level 过滤影响 */
        if ($withSelf) {
            foreach ($nids as $id) {
                if ($this->hasItem($id)) {
                    $nodes[$id] = $this->getItem($id);
                }
            }
        }

        /* 结果排序: 按 level 从小到大 */
        if ($onlyId) {
            /* onlyId 优化: 构建轻量的 id=>level 映射排序, 避免反复读取节点属性 */
            $levels = [];
            foreach ($nodes as $id => $node) {
                $levels[$id] = $this->indexes[$id]->level;
            }
            asort($levels);
            return array_keys($levels);
        }

        /* 非 onlyId: 对节点数据数组按 level 排序 */
        uasort($nodes, function ($a, $b) {
            $aLevel = $this->getIndex($this->getNodeProperty($a, $this->idKey))->level;
            $bLevel = $this->getIndex($this->getNodeProperty($b, $this->idKey))->level;
            return ($aLevel == $bLevel) ? 0 : ($aLevel > $bLevel ? 1 : -1);
        });

        return $nodes;
    }

    /**
     * 获取某一节点的单个父节点.
     *
     * 是 getParents 的便捷封装. 默认返回直接父节点 (上一级),
     * 也可以通过 $level 参数指定获取某一层级的祖先.
     *
     * @param int|string $nid 节点 id
     * @param bool $onlyId true 返回父节点 id, false 返回父节点完整数据
     * @param null|int $level 指定获取哪一级的祖先, null 表示直接父节点 (当前 level - 1)
     * @return int|string|mixed|null 父节点数据/id, 不存在返回 null
     */
    public function getParent($nid, $onlyId = false, $level = null)
    {
        $index = $this->getIndex($nid);
        if (!$index instanceof Index) {
            return;
        }

        /* 默认取上一级: 当前 level - 1 */
        $level = null === $level ? ($index->level - 1) : $level;

        /* 利用 getParents 的 level 过滤, 将 startLevel 和 endLevel 都设为目标 level */
        $parents = $this->getParents($nid, false, $onlyId, $level, $level);
        $parents = array_values($parents);

        return isset($parents[0]) ? $parents[0] : null;
    }

    /**
     * 获取节点的所有祖先节点.
     *
     * 支持传入单个 id 或 id 数组进行批量查询.
     *
     * ## 算法: Parent 链向上遍历
     *
     * 从输入节点出发, 沿 Index::$parent 链逐级向上走到根节点,
     * 途中收集所有经过的祖先节点.
     *
     * 复杂度 O(P × D), 其中 P 为输入节点数, D 为树的最大深度.
     * 相比旧的全表扫描 O(P × N) (N 为总节点数) 大幅优化.
     *
     * ## withSelf 行为
     *
     * 与 getChildren 不同, getParents 的 withSelf 受 startLevel/endLevel 过滤影响.
     * 即如果输入节点不在 level 范围内, withSelf=true 时也不会包含自身.
     *
     * ## 循环引用防护
     *
     * 使用 visited 集合记录已访问的节点, 防止 parent 指针形成环时导致死循环.
     * (通过 addNode 可能创建出不合法的 parent 引用)
     *
     * ## 返回值排序
     *
     * 结果按 level 从小到大排序 (浅层祖先在前).
     *
     * @param string|int|array $nid 节点 id 或节点 id 数组
     * @param bool $withSelf 结果是否包含输入节点自身 (受 level 过滤影响), 默认 false
     * @param bool $onlyId true 返回 id 数组, false 返回 id=>节点数据 的关联数组
     * @param null|int $startLevel 仅返回 level >= startLevel 的节点, null 不限制
     * @param null|int $endLevel 仅返回 level <= endLevel 的节点, null 不限制
     * @return array
     */
    public function getParents($nid, $withSelf = false, $onlyId = false, $startLevel = null, $endLevel = null)
    {
        if (empty($nids = is_array($nid) ? $nid : [$nid])) {
            return [];
        }

        $nodes = [];

        foreach ($nids as $id) {
            if (!isset($this->indexes[$id])) {
                continue;
            }

            /* withSelf: 受 level 过滤影响 */
            if ($withSelf) {
                $level = $this->indexes[$id]->level;
                if ((null === $startLevel || $startLevel <= $level) && (null === $endLevel || $endLevel >= $level)) {
                    $nodes[$id] = $this->items[$id];
                }
            }

            /*
             * 沿 parent 链向上遍历:
             * visited 防止循环引用导致死循环 (正常构建的树不会有环,
             * 但 addNode 可能创建出 parent 指向不合法节点的情况).
             */
            $visited = [$id => true];
            $currentId = $this->indexes[$id]->parent;
            while (isset($this->indexes[$currentId]) && !isset($visited[$currentId])) {
                $visited[$currentId] = true;
                $level = $this->indexes[$currentId]->level;
                if ((null === $startLevel || $startLevel <= $level) && (null === $endLevel || $endLevel >= $level)) {
                    $nodes[$currentId] = $this->items[$currentId];
                }
                $currentId = $this->indexes[$currentId]->parent;
            }
        }

        /* 结果排序: 按 level 从小到大 */
        if ($onlyId) {
            /* onlyId 优化: 轻量 id=>level 映射排序 */
            $levels = [];
            foreach ($nodes as $id => $node) {
                $levels[$id] = $this->indexes[$id]->level;
            }
            asort($levels);
            return array_keys($levels);
        }

        /* 非 onlyId: 对节点数据数组按 level 排序 */
        uasort($nodes, function ($a, $b) {
            $aLevel = $this->getIndex($this->getNodeProperty($a, $this->idKey))->level;
            $bLevel = $this->getIndex($this->getNodeProperty($b, $this->idKey))->level;
            return ($aLevel == $bLevel) ? 0 : ($aLevel > $bLevel ? 1 : -1);
        });

        return $nodes;
    }

    /**
     * 按自定义规则对同级兄弟节点进行排序.
     *
     * 返回一个新的 HTree 实例, 原始实例不受影响 (深拷贝).
     *
     * 排序逻辑:
     *   1. 深拷贝所有 Index 对象, 确保排序操作不污染原始树
     *   2. 自底向上递归: 先排序每个节点的 children, 再排序当前层的兄弟节点
     *   3. 用户通过 $cmpSortCallable 提供排序键 (返回字符串), 内部使用 strcmp 比较
     *
     * $cmpSortCallable 返回的字符串用于 strcmp 比较, 因此:
     *   - 如果排序键是数字, 建议用 str_pad 左填充 0 确保字典序与数值序一致
     *   - 例如: str_pad((string)$node['sort'], 10, '0', STR_PAD_LEFT)
     *
     * @param callable $cmpSortCallable 接收节点数据, 返回排序键字符串
     * @param int $sortType SORT_ASC 升序 | SORT_DESC 降序 (默认)
     * @return static 排序后的新 HTree 实例
     *
     * @see https://www.php.net/manual/en/function.strcmp.php
     */
    public function treeSort(callable $cmpSortCallable, $sortType = SORT_DESC)
    {
        $instance = clone $this;
        $instance->deepCloneIndexes();

        $instance->indexTree = $instance->recursiveTreeSort($instance->indexTree, $cmpSortCallable, $sortType);

        return $instance;
    }

    /**
     * 深拷贝所有 Index 对象.
     *
     * PHP 的 clone 是浅拷贝, $indexes 数组中的 Index 对象仍然是引用.
     * 此方法逐个 clone 每个 Index 并重建 children 引用链,
     * 确保后续排序操作不会影响原始树的 Index 对象.
     */
    protected function deepCloneIndexes()
    {
        $newIndexes = [];
        foreach ($this->indexes as $id => $index) {
            $newIndexes[$id] = clone $index;
            $newIndexes[$id]->children = []; // 清空引用, 由 buildIndexTree 重建
        }
        $this->indexes = $newIndexes;
        $this->buildIndexTree();
    }

    /**
     * 递归排序 indexTree.
     *
     * 自底向上: 先递归排序每个节点的 children, 再对当前层级的兄弟节点排序.
     * 使用 uasort 保持键的关联关系.
     *
     * @param Index[] $indexes 当前层级的 Index 数组
     * @param callable $cmpSortCallable 排序键提取函数
     * @param int $sortType SORT_ASC | SORT_DESC
     * @return Index[] 排序后的 Index 数组
     */
    protected function recursiveTreeSort($indexes, callable $cmpSortCallable, $sortType)
    {
        /* 先递归排序每个节点的子节点 */
        /** @var Index $index */
        foreach ($indexes as $index) {
            $index->children = $this->recursiveTreeSort($index->children, $cmpSortCallable, $sortType);
        }

        /* 再排序当前层级的兄弟节点 */
        uasort($indexes, function (Index $a, Index $b) use ($cmpSortCallable, $sortType) {
            $aSort = $cmpSortCallable($this->items[$a->id]);
            $bSort = $cmpSortCallable($this->items[$b->id]);
            $cmp = strcmp($aSort, $bSort);
            if (SORT_ASC == $sortType) {
                return (0 == $cmp) ? 0 : ($cmp > 0 ? 1 : -1);
            } else {
                return (0 == $cmp) ? 0 : ($cmp > 0 ? -1 : 1);
            }
        });

        return $indexes;
    }

    /**
     * 对每个节点应用变换函数, 返回新的 HTree 实例.
     *
     * 按 indexTree 的树形顺序 (深度优先) 遍历每个节点,
     * 将 callable 的返回值作为该节点的新数据.
     * 原始 HTree 实例不受影响 (通过 clone 实现).
     *
     * 注意: clone 对 $items (PHP 数组) 是值拷贝, 对 $indexes 中的 Index 对象是引用拷贝.
     * 但 treeMap 只修改 $items, 不修改 $indexes, 所以浅拷贝即可.
     *
     * @param callable $callable 接收节点数据, 返回变换后的新数据
     * @return static 变换后的新 HTree 实例
     */
    public function treeMap(callable $callable)
    {
        $instance = clone $this;

        $instance->recursiveTreeMap($instance->indexTree, $callable);

        return $instance;
    }

    /**
     * 递归遍历 indexTree, 对每个节点执行变换函数.
     *
     * @param Index[] $indexes 当前层级的 Index 数组
     * @param callable $callable 变换函数
     */
    protected function recursiveTreeMap($indexes, $callable)
    {
        foreach ($indexes as $index) {
            $this->items[$index->id] = $callable($this->items[$index->id]);
            $this->recursiveTreeMap($index->children, $callable);
        }
    }

    /**
     * 将内部结构还原为嵌套树形数组.
     *
     * 按 indexTree 的树形结构递归遍历, 为每个节点添加 children 属性,
     * 输出类似 [{id: 1, children: [{id: 2, children: [...]}]}] 的嵌套结构.
     *
     * 配合 fromTree 可以实现 "嵌套结构 → HTree → 嵌套结构" 的完整往返.
     *
     * @param string $childrenKey 输出中子节点数组的属性名, 默认 'children'
     * @param callable|null $format 格式化函数, 接收原始节点数据, 返回输出格式; null 则原样输出
     * @param bool $keepEmptyChildrenKey 叶子节点是否保留空的 children 数组, 默认 true
     * @return array 嵌套树形数组
     */
    public function getTree($childrenKey = 'children', $format = null, $keepEmptyChildrenKey = true)
    {
        return $this->recursiveGetTree($this->indexTree, $childrenKey, $format, $keepEmptyChildrenKey);
    }

    /**
     * 递归遍历 indexTree, 构建嵌套输出.
     *
     * @param Index[] $indexes 当前层级的 Index 数组
     * @param int|string $childrenKey children 属性名
     * @param null|callable $format 节点格式化函数
     * @param bool $keepEmptyChildrenKey 是否保留空 children
     * @return array 当前层级的嵌套节点数组
     */
    protected function recursiveGetTree($indexes, $childrenKey, $format, $keepEmptyChildrenKey)
    {
        $nodes = [];
        foreach ($indexes as $index) {
            /* 格式化节点数据 */
            $node = null === $format ? $this->items[$index->id] : $format($this->items[$index->id]);

            /* 递归处理子节点 */
            $children = $this->recursiveGetTree($index->children, $childrenKey, $format, $keepEmptyChildrenKey);
            if (!empty($children) || (bool)$keepEmptyChildrenKey) {
                $node[$childrenKey] = $children;
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * 从扁平数据构建嵌套集合索引 (核心构建方法).
     *
     * 构建过程:
     *   1. 以 id 为键重新索引输入数据 (去重, 保留最后出现的)
     *   2. 遍历每个节点, 递归确保父节点先于子节点被处理 (拓扑排序思想)
     *   3. 每个节点通过 pushNodeToTree 插入嵌套集合模型
     *   4. 最后通过 buildIndexTree 构建树形引用链
     *
     * 递归构建的必要性:
     *   输入数据可能是无序的 (子节点出现在父节点之前).
     *   递归构建确保: 处理任何节点之前, 其父节点的 Index 已经存在.
     *
     * @param array $items 原始节点数据数组
     */
    protected function buildIndex(array $items)
    {
        $this->indexTree = $this->buildIndexInProcess = [];

        /* 以 id 为键重新索引 */
        $_ = [];
        foreach ($items as $item) {
            $_[$this->getNodeProperty($item, $this->idKey)] = $item;
        }

        /* 递归构建每个节点的 Index (自动处理依赖顺序) */
        foreach ($_ as $id => $item) {
            if (!isset($this->indexes[$id])) {
                $this->recursiveBuildIndex($id, $_);
            }
        }

        /* 构建树形引用链 */
        $this->buildIndexTree();
    }

    /**
     * 递归构建单个节点的 Index.
     *
     * 处理逻辑:
     *   1. 检测循环依赖: 如果当前节点已在处理栈中, 说明存在 A→B→...→A 的环, 抛出异常
     *   2. 将当前节点加入处理栈
     *   3. 如果父节点存在且尚未构建 Index, 递归先构建父节点 (确保父在子前)
     *   4. 调用 pushNodeToTree 将当前节点插入嵌套集合
     *   5. 从处理栈中移除当前节点
     *
     * 这实际上是一个带循环检测的拓扑排序:
     *   - buildIndexInProcess 相当于 DFS 的 "灰色标记" (正在处理)
     *   - indexes 中已存在的节点相当于 "黑色标记" (已完成)
     *   - 遇到灰色节点说明存在回边 (循环依赖)
     *
     * @param int|string $id 待处理的节点 id
     * @param array $items 以 id 为键的全量节点数据
     * @throws UnableBuildTreeException 存在循环依赖时抛出
     */
    protected function recursiveBuildIndex($id, &$items)
    {
        /* 循环检测: 当前 id 已在递归栈中, 说明存在环 */
        if (in_array($id, $this->buildIndexInProcess)) {
            throw new UnableBuildTreeException($this->buildIndexInProcess, '不能构建成一个树形');
        }

        /* 入栈: 标记当前节点正在处理 */
        $this->buildIndexInProcess[$id] = $id;

        /** @var int|string $parent 当前节点的父节点 id */
        $parent = $this->getNodeProperty($items[$id], $this->parentKey);

        /* 如果父节点存在于输入数据中, 且尚未构建 Index, 则递归先处理父节点 */
        if (isset($items[$parent]) && !isset($this->indexes[$parent])) {
            $this->recursiveBuildIndex($parent, $items);
        }

        /* 将节点插入嵌套集合模型 */
        $this->pushNodeToTree($items[$id]);

        /* 出栈: 当前节点处理完成 */
        unset($this->buildIndexInProcess[$id]);
    }

    /**
     * 获取单个节点的 Index 对象.
     *
     * @param int|string $id 节点 id
     * @return Index|null 对应的 Index 对象, 不存在返回 null
     */
    public function getIndex($id)
    {
        return isset($this->indexes[$id]) ? $this->indexes[$id] : null;
    }

    /**
     * 获取所有节点的 Index 对象.
     *
     * @return Index[] 以 id 为键的 Index 关联数组
     */
    public function getIndexes()
    {
        return $this->indexes;
    }

    /**
     * 合并重叠/包含的区间 (标准区间合并算法).
     *
     * 在 getChildren 批量查询中, 如果同时传入父节点和子节点,
     * 子节点的 [left, right] 区间必然被父节点的区间包含.
     * 合并后只保留最外层的区间, 可以大幅减少后续遍历中的比较次数.
     *
     * 算法:
     *   1. 按 left 值升序排序
     *   2. 维护一个 merged 结果列表, 初始放入第一个区间
     *   3. 依次检查后续区间:
     *      - 如果与 merged 最后一个区间重叠/包含 (当前 left <= 上一个 right), 合并
     *      - 否则作为新的独立区间加入 merged
     *
     * 示例:
     *   输入: [[1,10], [2,7], [12,15]]
     *   排序后: [[1,10], [2,7], [12,15]]
     *   合并过程:
     *     - [1,10] 放入结果
     *     - [2,7]: 2 <= 10, 被 [1,10] 包含, 合并后仍为 [1,10]
     *     - [12,15]: 12 > 10, 独立区间
     *   结果: [[1,10], [12,15]]
     *
     * @param array $ranges [[left, right], ...] 区间列表
     * @return array 合并后的区间列表
     */
    protected function mergeRanges(array $ranges)
    {
        if (empty($ranges)) {
            return [];
        }

        /* 按 left 值升序排序 */
        usort($ranges, function ($a, $b) {
            return $a[0] - $b[0];
        });

        $merged = [$ranges[0]];
        for ($i = 1; $i < count($ranges); $i++) {
            $last = &$merged[count($merged) - 1];
            if ($ranges[$i][0] <= $last[1]) {
                /* 当前区间与上一个重叠/包含, 合并 (取较大的 right) */
                if ($ranges[$i][1] > $last[1]) {
                    $last[1] = $ranges[$i][1];
                }
            } else {
                /* 不重叠, 作为独立区间 */
                $merged[] = $ranges[$i];
            }
            unset($last); // 解除引用, 避免后续循环中意外修改
        }

        return $merged;
    }

    /**
     * 获取节点数据的属性值 (兼容数组和对象两种格式).
     *
     * HTree 支持数组和对象作为节点数据, 此方法统一了属性访问方式.
     * 在静态方法 (flattenTree) 和实例方法 (pushNodeToTree, 排序比较器) 中均被调用,
     * 因此声明为 protected static.
     *
     * @param array|object $node 节点数据
     * @param string $name 属性名
     * @param mixed $default 属性不存在时的默认值
     * @return mixed 属性值
     */
    protected static function getNodeProperty($node, $name, $default = null)
    {
        if (is_object($node)) {
            return isset($node->{$name}) ? $node->{$name} : $default;
        }

        return array_key_exists($name, $node) ? $node[$name] : $default;
    }
}
