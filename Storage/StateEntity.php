<?php

namespace Okay\Modules\Sviat\Meest\Storage;

use Okay\Core\Entity\Entity;

class StateEntity extends Entity
{
    protected static $fields = [
        'id',
        'name',
        'value',
        'updated_at',
    ];

    protected static $table = 'sviat__meest__state';
    protected static $tableAlias = 'ms';
    protected static $alternativeIdField = 'name';
}
