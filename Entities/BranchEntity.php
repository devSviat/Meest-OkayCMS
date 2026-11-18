<?php

namespace Okay\Modules\Sviat\Meest\Entities;

use Okay\Core\Entity\Entity;

/** Відділення й поштомати Meest; `id` — `branchID` (UUID у нижньому регістрі), його приймають `calculate` і `parcel`. */
class BranchEntity extends Entity
{
    protected static $fields = [
        'id',
        'region_id',
        'city_id',
        'number',
        'kind',
        'type_code',
        'name_ua',
        'address_ua',
        'zip',
        'lat',
        'lng',
        'schedule_json',
        'weight_place_max',
        'weight_total_max',
        'volume_place_max',
        'insurance_max',
        'length_max',
        'width_max',
        'height_max',
        'phone_required',
        'sending_only',
        'receiving_only',
        'inbound_from',
        'inbound_to',
        'overloaded',
        'row_hash',
        'seen_run',
        'updated_at',
    ];

    protected static $defaultOrderFields = ['number', 'id'];
    protected static $table = 'sviat__meest__branches';
    protected static $tableAlias = 'meb';
}
