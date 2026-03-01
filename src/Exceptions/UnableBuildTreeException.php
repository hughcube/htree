<?php

namespace HughCube\HTree\Exceptions;

use InvalidArgumentException;
use Throwable;

/**
 * 无法构建树形结构时抛出的异常.
 *
 * 当输入数据中存在循环依赖 (如 A 的 parent 是 B, B 的 parent 是 A),
 * 导致无法构建出合法的树形结构时, 会抛出此异常.
 * 通过 getBadNodeIds() 可以获取形成环的节点 id 列表, 方便定位问题数据.
 */
class UnableBuildTreeException extends InvalidArgumentException
{
    /**
     * 形成循环依赖的节点 id 列表.
     *
     * @var array
     */
    protected $badNodeIds = [];

    /**
     * @param array          $badIds   形成环的节点 id 数组
     * @param string         $message  异常描述
     * @param int            $code     异常代码
     * @param Throwable|null $previous 上一个异常
     */
    public function __construct(
        array $badIds,
        $message = '',
        $code = 0,
        Throwable $previous = null
    ) {
        $this->badNodeIds = $badIds;

        parent::__construct($message, $code, $previous);
    }

    /**
     * 获取形成循环依赖的节点 id 列表.
     *
     * @return int[]|string[]
     */
    public function getBadNodeIds()
    {
        return $this->badNodeIds;
    }
}
