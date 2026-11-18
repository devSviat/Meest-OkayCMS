{if $meest_mass_result}
    <div class="row fn_meest_mass_result">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="boxed meest-mass">
                <div class="meest-mass__head">
                    <div class="meest-mass__title">{$btr->sviat__meest__mass_heading|escape}{if $meest_mass_result.title}: {$meest_mass_result.title|escape}{/if}</div>
                    <button type="button" class="meest-mass__hide fn_meest_mass_hide">{$btr->sviat__meest__mass_hide|escape}</button>
                </div>
                {if $meest_mass_result.error}
                    <div class="meest-mass__row meest-mass__row--failed">{$meest_mass_result.error|escape}</div>
                {/if}
                {foreach $meest_mass_result.rows as $row}
                    <div class="meest-mass__row meest-mass__row--{$row.kind|escape}"><a href="{url controller=OrderAdmin id=$row.orderId return=$smarty.server.REQUEST_URI}">№ {$row.orderId|escape}</a> — {$row.text|escape}</div>
                {/foreach}
                {if $meest_mass_result.untouched}
                    <div class="meest-mass__note">{$meest_mass_result.untouched|escape}</div>
                {/if}
            </div>
        </div>
    </div>
{/if}
