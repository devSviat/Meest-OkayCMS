{if !empty($order->meest_list_parcel)}
    {$mp = $order->meest_list_parcel}
    <div class="meest-list-status">
        <svg class="meest-list-status__logo" width="16" height="16" viewBox="40 39 433 433" aria-hidden="true"><path fill="#0056A4" d="M126 307.633H208.444L236.987 254.886L208.7 203.122H126V307.633Z"/><path fill="#E40137" d="M283.084 65L387 254.886L283.084 446H179.169L283.084 254.886L179.169 65H283.084Z"/></svg>
        {if $mp.barcode}<a href="" class="meest-list-status__barcode fn_meest_list_copy hint-bottom-middle-t-info-s-small-mobile hint-anim" data-hint="{$btr->sviat__meest__list_copy|escape}" data-copy-string="{$mp.barcode|escape}" data-copy-failed="{$btr->sviat__meest__parcel_copy_failed|escape}">{$mp.barcode|escape}</a>{/if}
        {if $mp.statusKey}<span class="meest-parcel__badge meest-parcel__badge--{$mp.group|escape}{if $mp.eventName} hint-bottom-middle-t-info-s-small-mobile hint-anim{/if}"{if $mp.eventName} data-hint="{$mp.eventName|escape}"{/if}>{$btr->getTranslation($mp.statusKey)|escape}</span>{/if}
    </div>
{/if}
