<?php

namespace HughCube\HTree\Exceptions;

use Throwable;

/**
 * Class InvalidArgumentException
 * @package HughCube\HTree\Exceptions
 */
class UnableBuildTreeException extends InvalidArgumentException
{
    /**
     * @var array 不正确的数据id
     */
    protected $badNodeIds = [];

    /**
     * UnableBuildTreeException constructor.
     * @param array $badIds
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct(
        array $badIds,
        $message = "",
        $code = 0,
        Throwable $previous = null
    ) {
        $this->badNodeIds = $badIds;

        parent::__construct($message, $code, $previous);
    }

    /**
     * @return integer[]|string[]
     */
    public function getBadNodeIds()
    {
        return $this->badNodeIds;
    }
}
