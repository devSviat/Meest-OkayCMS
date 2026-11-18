{if $meest_order_point || $meest_order_delivery}
{$p = $meest_order_point}
{$d = $meest_order_delivery}
<div class="meest-op mb-1{if $p} fn_meest_order_point{/if}"{if $p} data-url="{$smarty.server.SCRIPT_NAME|escape}?controller=Sviat.Meest.OrderPointAdmin" data-order="{$p.orderId|escape}" data-token="{$smarty.session.id|escape}" data-currency="{$p.currency|escape}" data-city-id="{$p.cityId|escape}" data-city-name="{$p.cityName|escape}" data-branch="{$p.branchId|escape}" data-texts="{$p.textsJson|escape}" data-open="{if $p.needsChoice && !$p.locked}1{/if}"{/if}>
    <div class="meest-op__head">
        <span class="heading_label">{$btr->sviat__meest__order_title|escape}</span>
        {if $p && !$p.locked}<button type="button" class="meest-op__link fn_meest_op_change"{if $p.needsChoice} hidden{/if}>{$btr->sviat__meest__op_change|escape}</button>{/if}
    </div>
    {if $p && $p.locked}<div class="meest-op__hint mb-h">{$btr->sviat__meest__op_locked|escape}</div>{/if}
    {if $p && $p.needsChoice && !$p.locked}
        <div class="alert alert--warning mb-h fn_meest_op_needs"><div class="alert__content"><p>{$btr->sviat__meest__op_needs_point|escape}</p></div></div>
    {/if}
    {* Редактор після збереження перемальовує цей блок сам, з тих самих рядків OrderDeliveryView. *}
    <div class="meest-op__summary">
    {if $d}
        {if $d.priceUnconfirmed}
            <div class="alert alert--icon alert--warning mb-h"><div class="alert__content"><p>{$btr->sviat__meest__order_price_fallback|escape}</p></div></div>
        {/if}
        <div class="meest-op__row"><span class="meest-op__label">{$btr->sviat__meest__order_city|escape}</span>{$d.city|escape}</div>
        <div class="meest-op__row"><span class="meest-op__label">{$btr->sviat__meest__order_point|escape}</span>{$d.point|escape}</div>
        {if $d.estimatedDate}
            <div class="meest-op__row"><span class="meest-op__label">{$btr->sviat__meest__order_estimated|escape}</span>{$d.estimatedDate|date_format:'%d.%m.%Y'|escape}</div>
        {/if}
    {/if}
    </div>
    {if $p}
        <div class="meest-op__message" role="status"></div>
        <div class="meest-op__editor" hidden></div>
    {/if}
</div>
{/if}
