<?php

namespace App\Protocols;

class ClashVerge extends ClashMeta
{
    public $flag = 'verge';

    protected function templateName(): string
    {
        return 'clashverge';
    }
}
