{if $meest_module_id && $delivery->module_id == $meest_module_id && isset($meest_prefill[$delivery->id])}
{$meest_choice = $meest_prefill[$delivery->id]}
{if $meest_unavailable}
<p class="meest-cart-unavailable" role="status">{$lang->sviat__meest__cart_dictionary_empty|escape}</p>
{else}
<div class="meest-cart meest-cart--{$meest_theme.family|escape}"
     data-delivery-id="{$delivery->id|escape}"
     data-city="{$meest_choice.city_json|escape}"
     data-branch="{$meest_choice.branch_json|escape}"
     data-texts="{$meest_texts_json|escape}"
     data-urls="{$meest_urls_json|escape}"
     data-chip="{$meest_theme.chip|escape}"
     data-chip-selected="{$meest_theme.chipSelected|escape}">

    {if $meest_popular}
        <div class="meest-cart__popular {$meest_theme.chips|escape}" role="group" aria-label="{$lang->sviat__meest__cart_popular|escape}">
            {foreach $meest_popular as $city}
                <button type="button" class="{$meest_theme.chip|escape}" aria-pressed="false" data-meest-city="{$city.id|escape}" data-meest-city-name="{$city.label|escape}" data-meest-city-type="{$city.type|escape}">{$city.label|escape}</button>
            {/foreach}
        </div>
    {/if}

    <div class="meest-cart__city {$meest_theme.field|escape}">
        {if $meest_theme.family == 'vibe'}
            <label class="{$meest_theme.label|escape}" for="meest_city_{$delivery->id|escape}">{$lang->sviat__meest__cart_city|escape}*</label>
        {/if}
        {* Ім'я задано лише для jQuery Validate теми (він пропускає поля без імені); сервер його ігнорує. *}
        <input id="meest_city_{$delivery->id|escape}" class="meest-cart__city-input {$meest_theme.input|escape}" type="text" autocomplete="off" name="meest_city_q[{$delivery->id|escape}]"
               role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="meest_city_list_{$delivery->id|escape}"
               value="{if $meest_choice.city}{$meest_choice.city.name|escape}{/if}"
               {if $meest_theme.family == 'vibe'}placeholder="{$lang->sviat__meest__cart_city_placeholder|escape}"{/if}>
        {if $meest_theme.family != 'vibe'}
            <span class="{$meest_theme.label|escape}">{$lang->sviat__meest__cart_city|escape}*</span>
        {/if}
        <ul id="meest_city_list_{$delivery->id|escape}" class="meest-cart__suggest" role="listbox" hidden></ul>
    </div>

    {* Показується, щойно місто вибрано, замість популярних міст і поля міста. *}
    <div class="meest-cart__city-line" hidden>
        <span class="meest-cart__city-line-label">{$lang->sviat__meest__cart_city|escape}:</span>
        <span class="meest-cart__city-line-type"></span> <b class="meest-cart__city-line-name"></b>
        <span class="meest-cart__city-line-area"></span>
        <button type="button" class="meest-cart__link meest-cart__change-city">{$lang->sviat__meest__cart_change_city|escape}</button>
    </div>

    {* Вибраний пункт замінює список: ніщо в блоці його не повторює. *}
    <div class="meest-cart__chosen" hidden>
        <div class="meest-cart__chosen-text">
            <span class="meest-cart__chosen-city"></span>
            <b class="meest-cart__chosen-title"></b>
            <span class="meest-cart__chosen-place"></span>
            <span class="meest-cart__chosen-schedule"></span>
        </div>
        <button type="button" class="meest-cart__link meest-cart__change">{$lang->sviat__meest__cart_change|escape}</button>
    </div>

    <div class="meest-cart__points" hidden>
        <div class="meest-cart__groups {$meest_theme.chips|escape}" role="group" aria-label="{$lang->sviat__meest__cart_groups|escape}"></div>
        <div class="meest-cart__search {$meest_theme.field|escape}" hidden>
            {if $meest_theme.family == 'vibe'}
                <label class="{$meest_theme.label|escape}" for="meest_search_{$delivery->id|escape}">{$lang->sviat__meest__cart_search|escape}</label>
            {/if}
            <input id="meest_search_{$delivery->id|escape}" class="meest-cart__search-input {$meest_theme.input|escape}" type="search" autocomplete="off" name="meest_search_q[{$delivery->id|escape}]"
                   {if $meest_theme.family == 'vibe'}placeholder="{$lang->sviat__meest__cart_search_placeholder|escape}"{/if}>
            {if $meest_theme.family != 'vibe'}
                <span class="{$meest_theme.label|escape}">{$lang->sviat__meest__cart_search|escape}</span>
            {/if}
        </div>
        {* Несе правило "виберіть пункт" з jQuery Validate, тож його повідомлення стоїть над списком. *}
        <input class="meest-cart__point-check" type="text" name="meest_point_check[{$delivery->id|escape}]" value="" tabindex="-1" aria-hidden="true" autocomplete="off">
        <div class="meest-cart__list" role="listbox" aria-label="{$lang->sviat__meest__cart_points|escape}"></div>
        <p class="meest-cart__more" hidden></p>
    </div>

    <p class="meest-cart__message" role="status" aria-live="polite" hidden></p>
    <div class="meest-cart__quote" role="status" aria-live="polite" hidden></div>

    <input type="hidden" class="meest-cart__city-id" name="meest_city_id[{$delivery->id|escape}]" value="{if $meest_choice.city}{$meest_choice.city.id|escape}{/if}">
    <input type="hidden" class="meest-cart__branch-id" name="meest_branch_id[{$delivery->id|escape}]" value="{if $meest_choice.branch}{$meest_choice.branch.id|escape}{/if}">
</div>
{/if}
{/if}
