{if $meest_order_delivery}
    {if $meest_order_family == 'vibe'}
        <dt><span data-language="sviat__meest__order_city">{$lang->sviat__meest__order_city|escape}</span></dt>
        <dd>{$meest_order_delivery.city|escape}</dd>
        <dt><span data-language="sviat__meest__order_point">{$lang->sviat__meest__order_point|escape}</span></dt>
        <dd>{$meest_order_delivery.point|escape}</dd>
        {if $meest_order_delivery.estimatedDate}
            <dt><span data-language="sviat__meest__order_estimated">{$lang->sviat__meest__order_estimated|escape}</span></dt>
            <dd>{$meest_order_delivery.estimatedDate|date_format:'%d.%m.%Y'|escape}</dd>
        {/if}
        {if $meest_order_delivery.priceUnconfirmed}
            <dt><span data-language="sviat__meest__order_delivery_cost">{$lang->sviat__meest__order_delivery_cost|escape}</span></dt>
            <dd>{$lang->sviat__meest__cart_price_by_manager|escape}</dd>
        {/if}
    {else}
        <tr>
            <td><span data-language="sviat__meest__order_city">{$lang->sviat__meest__order_city|escape}</span></td>
            <td>{$meest_order_delivery.city|escape}</td>
        </tr>
        <tr>
            <td><span data-language="sviat__meest__order_point">{$lang->sviat__meest__order_point|escape}</span></td>
            <td>{$meest_order_delivery.point|escape}</td>
        </tr>
        {if $meest_order_delivery.estimatedDate}
            <tr>
                <td><span data-language="sviat__meest__order_estimated">{$lang->sviat__meest__order_estimated|escape}</span></td>
                <td>{$meest_order_delivery.estimatedDate|date_format:'%d.%m.%Y'|escape}</td>
            </tr>
        {/if}
        {if $meest_order_delivery.priceUnconfirmed}
            <tr>
                <td><span data-language="sviat__meest__order_delivery_cost">{$lang->sviat__meest__order_delivery_cost|escape}</span></td>
                <td>{$lang->sviat__meest__cart_price_by_manager|escape}</td>
            </tr>
        {/if}
    {/if}
{/if}
