{* Блок посилки Meest. Він усередині форми замовлення, тож поля мають data-field замість name:
   збереження замовлення не повинне їх надсилати. Дії йдуть у ParcelAdmin окремими POST. *}
{if $meest_parcel}
{$s = $meest_parcel}
{function name=meest_parcel_error field=''}<span class="meest-parcel__error" data-for="{$field|escape}">{if isset($s.issues[$field])}{$btr->getTranslation($s.issues[$field])|escape}{/if}</span>{/function}
{function name=meest_parcel_attach}
<div class="meest-parcel__attach" hidden>
    <div class="meest-parcel__row">
        <input class="form-control" data-attach="barcode" maxlength="20" autocomplete="off" spellcheck="false" placeholder="{$btr->sviat__meest__parcel_attach_placeholder|escape}" aria-label="{$btr->sviat__meest__parcel_attach_placeholder|escape}">
        <button type="button" class="btn btn_small btn_border_blue fn_meest_parcel_attach">{$btr->sviat__meest__parcel_attach|escape}</button>
    </div>
    <div class="meest-parcel__issues meest-parcel__issues--attach" role="alert"></div>
</div>
{/function}
<div class="meest-parcel fn_meest_parcel{if $s.mode == 'card'} meest-parcel--card{/if}" data-url="{$smarty.server.SCRIPT_NAME|escape}?controller=Sviat.Meest.ParcelAdmin" data-order="{$s.orderId|escape}" data-token="{$smarty.session.id|escape}"
     data-confirm-remove="{$btr->sviat__meest__parcel_confirm_remove|escape}" data-confirm-unlink="{$btr->sviat__meest__parcel_confirm_unlink|escape}" data-confirm-unlink-check="{$btr->sviat__meest__parcel_confirm_unlink_check|escape}"
     data-busy="{$btr->sviat__meest__parcel_creating|escape}" data-failed="{$btr->sviat__meest__parcel_failed|escape}" data-network="{$btr->sviat__meest__op_network|escape}" data-expired="{$btr->sviat__meest__parcel_err_session|escape}" data-unexpected="{$btr->sviat__meest__parcel_err_unexpected|escape}"
     data-toast-ok="{$btr->toastr_success|escape}" data-toast-error="{$btr->toastr_error|escape}" data-order-text="{$btr->sviat__meest__parcel_open_order|escape}" data-choose="{$btr->sviat__meest__parcel_choose|escape}"
     data-done-create="{$btr->sviat__meest__parcel_done_create|escape}" data-done-attach="{$btr->sviat__meest__parcel_done_attach|escape}" data-done-lookup="{$btr->sviat__meest__parcel_done_lookup|escape}" data-done-choose="{$btr->sviat__meest__parcel_done_lookup|escape}" data-done-remove="{$btr->sviat__meest__parcel_done_remove|escape}" data-done-unlink="{$btr->sviat__meest__parcel_done_unlink|escape}" data-done-refresh="{$btr->sviat__meest__parcel_done_refresh|escape}">
    <div class="meest-parcel__head">
        <span class="{if $s.mode == 'card'}heading_box{else}heading_label{/if}">{if $s.mode == 'form'}{$btr->sviat__meest__parcel_form_title|escape}{else}{$btr->sviat__meest__parcel_title|escape}{/if}</span>
    </div>

    {if $s.auth}
        <div class="alert alert--error meest-parcel__auth" role="alert"><div class="alert__content">
            <p class="text_600">{$btr->sviat__meest__auth_title|escape}</p>
            {foreach $s.auth as $meest_failure}
                <p>{$btr->getTranslation('sviat__meest__auth_'|cat:$meest_failure.api)|escape}</p>
            {/foreach}
            <p class="text_grey">{$btr->sviat__meest__parcel_advice_key|escape}</p>
        </div></div>
    {/if}
    <div class="meest-parcel__notices" role="status"></div>

    {if $s.mode == 'check'}
        <div class="alert alert--warning"><div class="alert__content"><p>{$btr->sviat__meest__parcel_check_text|escape}</p></div></div>
        <div class="meest-parcel__actions">
            <button type="button" class="btn btn_small btn_blue fn_meest_parcel_lookup">{$btr->sviat__meest__parcel_lookup|escape}</button>
            <button type="button" class="btn btn_small btn_border_blue fn_meest_parcel_attach_toggle" aria-expanded="false">{$btr->sviat__meest__parcel_attach_open|escape}</button>
        </div>
        <div class="meest-parcel__candidates" hidden></div>
        {meest_parcel_attach}
        <div class="meest-parcel__issues" role="alert"></div>
        <div class="meest-parcel__unlink">
            <p class="text_grey">{$btr->sviat__meest__parcel_unlink_check_warning|escape}</p>
            <button type="button" class="btn btn_small btn_border_blue fn_meest_parcel_unlink" data-uncertain="1">{$btr->sviat__meest__parcel_unlink|escape}</button>
        </div>
    {elseif $s.mode == 'pending' || $s.mode == 'refusal'}
        <div class="alert alert--{if $s.mode == 'pending'}info{else}warning{/if}"><div class="alert__content"><p>{$btr->getTranslation($s.refusalKey)|escape}</p></div></div>
        {if isset($s.can.attach)}
            <div class="meest-parcel__actions"><button type="button" class="btn btn_small btn_border_blue fn_meest_parcel_attach_toggle" aria-expanded="false">{$btr->sviat__meest__parcel_attach_open|escape}</button></div>
            {meest_parcel_attach}
        {/if}
        <div class="meest-parcel__issues" role="alert"></div>
    {elseif $s.mode == 'form'}
        {$f = $s.form}
        <div class="meest-parcel__form">
            <div class="meest-parcel__caps">{$btr->sviat__meest__parcel_receiver|escape}</div>
            <label class="meest-parcel__field"><span class="heading_label">{$btr->sviat__meest__parcel_receiver_name|escape}</span><input class="form-control" data-field="receiver_name" maxlength="250" autocomplete="off" value="{$f.receiverName|escape}"></label>
            {meest_parcel_error field='receiver_name'}
            <label class="meest-parcel__field"><span class="heading_label">{$btr->sviat__meest__parcel_phone|escape}</span><input class="form-control" data-field="receiver_phone" maxlength="20" inputmode="tel" autocomplete="off" value="{$f.receiverPhone|escape}"></label>
            {meest_parcel_error field='receiver_phone'}
            {if $f.point}<div class="meest-parcel__line"><span class="meest-parcel__label">{$btr->sviat__meest__parcel_point|escape}</span>{$f.point|escape}</div>{/if}
            {meest_parcel_error field='point'}

            <div class="meest-parcel__caps">{$btr->sviat__meest__parcel_section|escape}</div>
            <label class="meest-parcel__field"><span class="heading_label">{$btr->sviat__meest__parcel_weight|escape}</span><input class="form-control" data-field="weight" inputmode="decimal" value="{$f.weight|escape}"></label>
            {meest_parcel_error field='weight'}
            <span class="heading_label">{$btr->sviat__meest__parcel_box_size|escape}</span>
            <div class="meest-parcel__grid meest-parcel__grid--three">
                <input class="form-control" data-field="length" inputmode="numeric" value="{$f.length|escape}" aria-label="{$btr->sviat__meest__parcel_length|escape}" title="{$btr->sviat__meest__parcel_length|escape}">
                <input class="form-control" data-field="width" inputmode="numeric" value="{$f.width|escape}" aria-label="{$btr->sviat__meest__parcel_width|escape}" title="{$btr->sviat__meest__parcel_width|escape}">
                <input class="form-control" data-field="height" inputmode="numeric" value="{$f.height|escape}" aria-label="{$btr->sviat__meest__parcel_height|escape}" title="{$btr->sviat__meest__parcel_height|escape}">
            </div>
            {meest_parcel_error field='sides'}
            <label class="meest-parcel__field"><span class="heading_label">{$btr->sviat__meest__parcel_declared|escape}</span><input class="form-control" data-field="declared_value" inputmode="decimal" value="{$f.declared|escape}"></label>
            {meest_parcel_error field='declared_value'}
            <div class="meest-parcel__grid">
                <label class="meest-parcel__field"><span class="heading_label">{$btr->sviat__meest__parcel_payer|escape}</span><select class="form-control" data-field="payer">
                    <option value="receiver" {if $f.payer == 'receiver'}selected{/if}>{$btr->sviat__meest__parcel_party_receiver|escape}</option>
                    <option value="sender" {if $f.payer == 'sender'}selected{/if}>{$btr->sviat__meest__parcel_party_sender|escape}</option>
                </select></label>
                <label class="meest-parcel__field"><span class="heading_label">{$btr->sviat__meest__parcel_pay_form|escape}</span><select class="form-control" data-field="pay_type">
                    {foreach $f.payTypes as $meest_pay_type}
                        <option value="{$meest_pay_type|escape}" {if $f.payType == $meest_pay_type}selected{/if}>{$btr->getTranslation('sviat__meest__parcel_'|cat:$meest_pay_type)|escape}</option>
                    {/foreach}
                </select></label>
            </div>
            {meest_parcel_error field='payer'}{meest_parcel_error field='pay_type'}
            <label class="meest-parcel__field"><span class="heading_label">{$btr->sviat__meest__parcel_description|escape}</span><input class="form-control" data-field="description" maxlength="250" required value="{$f.description|escape}"></label>
            {meest_parcel_error field='description'}
            <label class="meest-parcel__field"><span class="heading_label">{$btr->sviat__meest__parcel_notation|escape}</span><input class="form-control" data-field="notation" maxlength="255" value="{$f.notation|escape}"></label>
            {meest_parcel_error field='notation'}
        </div>
        <div class="meest-parcel__issues" role="alert"></div>
        <div class="meest-parcel__actions">
            <button type="button" class="btn btn_small btn_blue fn_meest_parcel_create">{$btr->sviat__meest__parcel_create|escape}</button>
            <button type="button" class="btn btn_small btn_border_blue fn_meest_parcel_attach_toggle" aria-expanded="false">{$btr->sviat__meest__parcel_attach_open|escape}</button>
        </div>
        {meest_parcel_attach}
    {else}
        {$c = $s.card}
        <div class="meest-card">
            <div class="meest-card__top">
                {if $c.created}<span class="meest-card__muted">{$btr->sviat__meest__parcel_created_at|escape} {$c.created|escape}</span>{/if}
                <div class="meest-card__head">
                    <svg class="meest-card__logo" width="16" height="16" viewBox="40 39 433 433" aria-hidden="true"><path fill="#0056A4" d="M126 307.633H208.444L236.987 254.886L208.7 203.122H126V307.633Z"/><path fill="#E40137" d="M283.084 65L387 254.886L283.084 446H179.169L283.084 254.886L179.169 65H283.084Z"/></svg>
                    {if $c.barcode}<button type="button" class="meest-card__number fn_meest_parcel_copy" data-copy="{$c.barcode|escape}" data-copied="{$btr->sviat__meest__parcel_copied|escape}" data-copy-failed="{$btr->sviat__meest__parcel_copy_failed|escape}" title="{$btr->sviat__meest__parcel_copy|escape}">{$c.barcode|escape}</button>{/if}
                    {if $c.trackUrl}<a class="meest-card__track" href="{$c.trackUrl|escape}" target="_blank" rel="noopener noreferrer" title="{$btr->sviat__meest__parcel_track|escape}" aria-label="{$btr->sviat__meest__parcel_track|escape}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 7l-10 10"/><path d="M8 7l9 0l0 9"/></svg></a>{/if}
                    {if $c.statusKey}<span class="meest-parcel__badge meest-parcel__badge--{$c.statusGroup|escape}">{$btr->getTranslation($c.statusKey)|escape}</span>{/if}
                </div>
                <div class="meest-card__event">
                    {if $c.event}
                        <b>{$c.event.name|escape}</b>{if $c.event.place}<span class="meest-card__dot">{$c.event.place|escape}</span>{/if}{if $c.event.at}<span class="meest-card__dot">{$c.event.at|escape}</span>{/if}
                    {elseif $c.checked}
                        <span class="text_grey">{$btr->sviat__meest__parcel_no_events|escape}</span>
                    {else}
                        <span class="text_grey">{$btr->sviat__meest__parcel_not_checked|escape}</span>
                    {/if}
                </div>
            </div>
            {if $c.recipient || $c.phone || $c.point || $c.deliveryPrice || $c.payTypeKey || $c.plannedDate || $c.declared}
            <div class="meest-card__body">
                <dl class="meest-card__facts">
                    {if $c.recipient || $c.phone}<div><dt>{$btr->sviat__meest__parcel_receiver|escape}</dt><dd>{if $c.recipient}{$c.recipient|escape}{/if}{if $c.recipient && $c.phone}<br>{/if}{if $c.phone}{$c.phone|escape}{/if}</dd></div>{/if}
                    {if $c.point}<div><dt>{$btr->sviat__meest__parcel_point|escape}</dt><dd>{$c.point|escape}</dd></div>{/if}
                    {if $c.deliveryPrice}<div><dt>{$btr->sviat__meest__parcel_delivery_price|escape}</dt><dd><b>{$c.deliveryPrice|escape} ₴</b>{if $c.payerKey}<span class="meest-card__dot">{$btr->getTranslation($c.payerKey)|escape}</span>{/if}</dd></div>{/if}
                    {if $c.payTypeKey}<div><dt>{$btr->sviat__meest__parcel_pay_form|escape}</dt><dd>{$btr->getTranslation($c.payTypeKey)|escape}</dd></div>{/if}
                    {if $c.plannedDate}<div><dt>{$btr->sviat__meest__parcel_planned|escape}</dt><dd>{$c.plannedDate|escape}</dd></div>{/if}
                    {if $c.declared}<div><dt>{$btr->sviat__meest__parcel_card_declared|escape}</dt><dd>{$c.declared|escape} ₴</dd></div>{/if}
                </dl>
            </div>
            {/if}
            {if $c.more || $c.history}
                <div class="meest-card__more" hidden><div class="meest-card__more-inner">
                    {if $c.more}
                        {$m = $c.more}
                        <dl class="meest-card__facts">
                            {if isset($m.weight)}<div><dt>{$btr->sviat__meest__parcel_weight_short|escape}</dt><dd>{$m.weight|escape}</dd></div>{/if}
                            {if isset($m.sides)}<div><dt>{$btr->sviat__meest__parcel_sides|escape}</dt><dd>{$m.sides|escape}</dd></div>{/if}
                            {if isset($m.volume)}<div><dt>{$btr->sviat__meest__parcel_volume|escape}</dt><dd>{$m.volume|escape}</dd></div>{/if}
                            {if isset($m.description)}<div><dt>{$btr->sviat__meest__parcel_description|escape}</dt><dd>{$m.description|escape}</dd></div>{/if}
                            {if isset($m.notation)}<div><dt>{$btr->sviat__meest__parcel_notation|escape}</dt><dd>{$m.notation|escape}</dd></div>{/if}
                            {if isset($m.parcelNumber)}<div><dt>{$btr->sviat__meest__parcel_number|escape}</dt><dd>{$m.parcelNumber|escape}</dd></div>{/if}
                        </dl>
                    {/if}
                    {if $c.history}
                        <div class="meest-card__caps">{$btr->sviat__meest__parcel_history|escape}</div>
                        <ol class="meest-card__history">
                            {foreach $c.history as $meest_event}
                                <li><span class="meest-card__when">{$meest_event.at|escape}</span><span>{$meest_event.name|escape}{if $meest_event.place}<span class="meest-card__dot text_grey">{$meest_event.place|escape}</span>{/if}</span></li>
                            {/foreach}
                        </ol>
                    {/if}
                </div></div>
            {/if}
            <div class="meest-parcel__issues meest-card__issues" role="alert"></div>
            <div class="meest-card__footer">
                {if $c.more || $c.history}<button type="button" class="meest-card__more-toggle fn_meest_parcel_more" aria-expanded="false" data-more="{$btr->sviat__meest__parcel_more|escape}" data-less="{$btr->sviat__meest__parcel_less|escape}"><span>{$btr->sviat__meest__parcel_more|escape}</span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6l6 -6"/></svg></button>{/if}
                <span class="meest-card__tools">
                    {if $c.checked}<span class="meest-card__meta">{$btr->sviat__meest__parcel_checked_at|escape} {$c.checked|escape}</span>{/if}
                    <button type="button" class="meest-card__icon fn_meest_parcel_refresh" title="{$btr->sviat__meest__parcel_refresh|escape}" aria-label="{$btr->sviat__meest__parcel_refresh|escape}"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"/><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"/></svg></button>
                    <span class="meest-card__sep" aria-hidden="true"></span>
                    <button type="button" class="btn btn_small btn_blue meest-card__sticker fn_meest_parcel_sticker"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2"/><path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4"/><path d="M7 15a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z"/></svg>{$btr->sviat__meest__parcel_sticker|escape}</button>
                    {if isset($s.can.remove)}<button type="button" class="meest-card__icon meest-card__icon--danger fn_meest_parcel_remove" title="{$btr->sviat__meest__parcel_remove_title|escape}" aria-label="{$btr->sviat__meest__parcel_remove_title|escape}"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/></svg></button>{/if}
                    <button type="button" class="meest-card__icon meest-card__icon--danger fn_meest_parcel_unlink" title="{$btr->sviat__meest__parcel_unlink_title|escape}" aria-label="{$btr->sviat__meest__parcel_unlink_title|escape}"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 15l6 -6"/><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464"/><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463"/><path d="M16 19h5"/></svg></button>
                </span>
            </div>
        </div>
    {/if}
</div>
{/if}
