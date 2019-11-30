<?php

namespace HughCube\HTree;

class Index
{
    /**
     * @var int|string
     */
    public $id;

    /**
     * @var int
     */
    public $level;

    /**
     * @var int
     */
    public $left;

    /**
     * @var int
     */
    public $right;

    /**
     * @var int|string
     */
    public $parent;

    /**
     * @var static[]
     */
    public $children = [];

    public function __construct(array $properties)
    {
        foreach ($properties as $name => $property) {
            $this->{$name} = $property;
        }
    }

    public function addChild(self $index)
    {
        $this->children[] = $index;
    }
}
