<?php

namespace HughCube\HTree\Exceptions;

use Throwable;

/**
 * Class InvalidArgumentException
 * @package HughCube\HTree\Exceptions
 */
class UnableBuildTreeException
    extends InvalidArgumentException
{
    protected $badIds = [];

    public function __construct(
        array $badIds,
        $message = "",
        $code = 0,
        Throwable $previous = null
    ) {
        $this->badIds = $badIds;

        parent::__construct($message, $code, $previous);
    }

    public function getBadIds()
    {
        return $this->badIds;
    }
}
