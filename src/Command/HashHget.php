<?php

namespace Encore\Redis\Command;

class HashHget extends Command implements RoutableInterface
{
    use RoutableTrait;

    protected $argumentCount = 2;
}
