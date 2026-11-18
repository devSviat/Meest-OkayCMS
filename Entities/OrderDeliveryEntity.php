<?php

namespace Okay\Modules\Sviat\Meest\Entities;

use Okay\Core\Entity\Entity;

/**
 * Що покупець обрав для замовлення, з копіями назв: пізніші зміни довідника не повинні переписувати історію.
 * Один рядок на замовлення (`order_id` унікальний); `id` потрібен базовому Entity для update().
 */
class OrderDeliveryEntity extends Entity
{
    protected static $fields = [
        'id',
        'order_id',
        'delivery_id',
        'city_id',
        'city_name',
        'branch_id',
        'branch_number',
        'branch_kind',
        'branch_title',
        'branch_address',
        'price',
        'price_source',
        'estimated_date',
        'created_at',
    ];

    protected static $defaultOrderFields = ['id DESC'];
    protected static $table = 'sviat__meest__order_delivery';
    protected static $tableAlias = 'meod';
}
