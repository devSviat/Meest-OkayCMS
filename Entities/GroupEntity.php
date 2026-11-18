<?php

namespace Okay\Modules\Sviat\Meest\Entities;

use Okay\Core\Entity\Entity;

/** Вкладка пунктів у кошику (напр. «Відділення»); `kinds` — JSON-список BranchKind. */
class GroupEntity extends Entity
{
    protected static $fields = [
        'id',
        'position',
        'enabled',
        'kinds',
    ];

    protected static $langFields = [
        'name',
    ];

    protected static $defaultOrderFields = ['position', 'id'];
    protected static $table = 'sviat__meest__groups';
    protected static $langObject = 'group';
    protected static $langTable = 'sviat__meest__groups';
    protected static $tableAlias = 'meg';
}
