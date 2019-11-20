<?php

namespace HughCube\HTree;

class Index
{
    public $id;

    public $level;

    public $left;

    public $right;

    public $parent;

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
