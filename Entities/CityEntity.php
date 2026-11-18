<?php

namespace Okay\Modules\Sviat\Meest\Entities;

use Okay\Core\Entity\Entity;

/** Населені пункти Meest з відкритими пунктами; `id` — `cityID` (UUID у нижньому регістрі). */
class CityEntity extends Entity
{
    protected static $fields = [
        'id',
        'region_id',
        'district_id',
        'name_ua',
        'type_ua',
        'district_ua',
        'region_ua',
        'search_text',
        'points',
        'row_hash',
        'seen_run',
        'updated_at',
    ];

    // Підказка міста показує перші записи, тож великі міста йдуть першими.
    protected static $defaultOrderFields = ['points DESC', 'name_ua', 'id'];
    protected static $searchFields = ['search_text'];
    protected static $table = 'sviat__meest__cities';
    protected static $tableAlias = 'mec';
}
