<?php

namespace HughCube\HTree;

class Index
{
    /**
     * @var integer|string
     */
    public $id;

    /**
     * @var integer
     */
    public $level;

    /**
     * @var integer
     */
    public $left;

    /**
     * @var integer
     */
    public $right;

    /**
     * @var integer|string
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
