<?php

namespace HughCube\HTree;

/**
 * 嵌套集合模型的索引节点.
 *
 * 每个 Index 对象存储一个树节点在嵌套集合模型 (Nested Set Model) 中的位置信息。
 * 嵌套集合模型通过为每个节点分配 left 和 right 两个整数值, 将树结构编码为一维区间,
 * 从而可以用简单的数值比较完成子孙判断、祖先查找等操作。
 *
 * 各属性在树结构中的含义:
 *
 *   - id:       节点唯一标识, 与原始数据中的 id 对应
 *   - level:    节点深度, 根节点为 1, 每下一层 +1
 *   - left:     嵌套集合的左值, 进入该节点时的序号
 *   - right:    嵌套集合的右值, 离开该节点时的序号
 *   - parent:   父节点的 id, 根节点的 parent 为原始数据中设定的值 (通常为 0 或 null)
 *   - children: 直接子节点的 Index 对象数组, 用于 indexTree 树形结构的遍历
 *
 * left/right 值的核心规则:
 *   - 对任意节点: left < right
 *   - 节点 B 是节点 A 的子孙 ⟺ A.left < B.left && B.right < A.right
 *   - 节点的子孙数量 = (right - left - 1) / 2
 *   - 所有 left/right 值在整棵树中唯一, 取值范围为 1 ~ 2N (N 为节点总数)
 *
 * 示例 (对应下面的树结构):
 *
 *         A(1,10)
 *        /       \
 *     B(2,7)    C(8,9)
 *    /     \
 *  D(3,4) E(5,6)
 *
 *   节点 A: left=1,  right=10, level=1, parent=null
 *   节点 B: left=2,  right=7,  level=2, parent=A
 *   节点 D: left=3,  right=4,  level=3, parent=B
 *   节点 E: left=5,  right=6,  level=3, parent=B
 *   节点 C: left=8,  right=9,  level=2, parent=A
 *
 *   判断 D 是否是 A 的子孙: A.left(1) < D.left(3) && D.right(4) < A.right(10) → 是
 *   判断 C 是否是 B 的子孙: B.left(2) < C.left(8) → 但 C.right(9) < B.right(7) 不成立 → 不是
 *   节点 A 的子孙数量: (10 - 1 - 1) / 2 = 4
 */
class Index
{
    /**
     * 节点唯一标识.
     *
     * 与原始数据中通过 idKey 指定的属性值对应,
     * 同时也是 HTree::$indexes 数组的键.
     *
     * @var int|string
     */
    public $id;

    /**
     * 节点在树中的深度 (层级).
     *
     * 根节点 level=1, 其直接子节点 level=2, 以此类推.
     * 用于 getChildren/getParents 的 startLevel/endLevel 过滤.
     *
     * @var int
     */
    public $level;

    /**
     * 嵌套集合模型的左值.
     *
     * 可以理解为对树进行深度优先遍历时, "进入" 该节点时的时间戳.
     * 父节点的 left 值一定小于其所有子孙的 left 值.
     *
     * @var int
     */
    public $left;

    /**
     * 嵌套集合模型的右值.
     *
     * 可以理解为对树进行深度优先遍历时, "离开" 该节点时的时间戳.
     * 父节点的 right 值一定大于其所有子孙的 right 值.
     *
     * @var int
     */
    public $right;

    /**
     * 父节点的 id.
     *
     * 根节点的 parent 值来自原始数据 (通常为 0 或 null),
     * 它不会在 HTree::$indexes 中找到对应的 Index 对象.
     * 用于 getParents 的 parent 链向上遍历.
     *
     * @var int|string
     */
    public $parent;

    /**
     * 直接子节点的 Index 对象数组.
     *
     * 由 HTree::buildIndexTree() 填充, 用于 indexTree 的树形遍历,
     * 支撑 getTree、treeSort、treeMap 等需要按树形结构递归的操作.
     * 注意: 只包含直接子节点, 不包含更深层的后代.
     *
     * @var static[]
     */
    public $children = [];

    /**
     * @param array $properties 属性键值对, 支持 id、level、left、right、parent
     */
    public function __construct(array $properties)
    {
        foreach ($properties as $name => $property) {
            $this->{$name} = $property;
        }
    }

    /**
     * 添加一个直接子节点.
     *
     * @param static $index 子节点的 Index 对象
     */
    public function addChild($index)
    {
        $this->children[] = $index;
    }
}
