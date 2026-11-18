{if $meest_order_delivery}
    <tr valign="top">
        <td class="es-p5t es-p5b" width="180px"><span>{$btr->sviat__meest__order_city|escape}:</span></td>
        <td class="es-p5t es-p5b"><span>{$meest_order_delivery.city|escape}</span></td>
    </tr>
    <tr valign="top">
        <td class="es-p5t es-p5b" width="180px"><span>{$btr->sviat__meest__order_point|escape}:</span></td>
        <td class="es-p5t es-p5b"><span>{$meest_order_delivery.point|escape}</span></td>
    </tr>
    {if $meest_order_delivery.estimatedDate}
    <tr valign="top">
        <td class="es-p5t es-p5b" width="180px"><span>{$btr->sviat__meest__order_estimated|escape}:</span></td>
        <td class="es-p5t es-p5b"><span>{$meest_order_delivery.estimatedDate|date_format:'%d.%m.%Y'|escape}</span></td>
    </tr>
    {/if}
    {if $meest_order_delivery.priceUnconfirmed}
    <tr valign="top">
        <td class="es-p5t es-p5b" colspan="2"><span style="color:#a35a00;">{$btr->sviat__meest__order_price_fallback|escape}</span></td>
    </tr>
    {/if}
{/if}
