{$meta_title = $btr->sviat__meest__title scope=global}

<div class="main_header">
    <div class="main_header__item">
        <div class="main_header__inner">
            <div class="box_heading heading_page">{$btr->sviat__meest__title|escape}</div>
        </div>
    </div>
</div>

{if $meest_access.flags}
    <div id="meest_auth_notice" class="alert alert--icon alert--error" role="alert">
        <div class="alert__content">
            <div class="alert__title">{$btr->sviat__meest__auth_title|escape}</div>
            {foreach $meest_access.flags as $meest_failure}
                <p>{$btr->getTranslation('sviat__meest__auth_'|cat:$meest_failure.api)|escape}</p>
            {/foreach}
            <p class="mb-0">{$btr->sviat__meest__auth_settings_advice|escape}</p>
        </div>
    </div>
{/if}

{* Секрети в сторінку не потрапляють: поле token завжди порожнє, збережений token показано лише як ••••. *}
<div id="meest_settings" class="meest-set" data-base="{$smarty.server.SCRIPT_NAME|escape}?controller=Sviat.Meest.MeestSettingsAdmin@">
<div class="row">
<div class="col-xl-6 col-lg-12">

<form method="post" id="meest_access_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__meest__access_box|escape}</div>
        <p class="text_grey">{$btr->sviat__meest__access_intro|escape}</p>
        {if $meest_errors}
            <div class="alert alert--error" role="alert"><div class="alert__content">
                {foreach $meest_errors as $meest_error}
                    <p>{$btr->getTranslation($meest_error)|escape}</p>
                {/foreach}
            </div></div>
        {/if}
        {if $meest_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__meest__saved_message|escape}</p></div></div>
        {/if}

        <div class="form-group">
            <label class="heading_label" for="meest_cabinet_token">{$btr->sviat__meest__token|escape}</label>
            <input id="meest_cabinet_token" type="password" name="cabinet_token" class="form-control" value=""
                   autocomplete="new-password" spellcheck="false"
                   placeholder="{if $meest_access.saved}{$btr->sviat__meest__token_stored|escape} ••••. {$btr->sviat__meest__token_keep|escape}{else}{$btr->sviat__meest__token_empty|escape}{/if}">
            {if $meest_access.saved}
                <div class="meest-set__key-meta">
                    <label class="meest-set__clear"><input type="checkbox" name="clear_cabinet_token" value="1"> {$btr->sviat__meest__token_clear|escape}</label>
                </div>
            {/if}
        </div>

        <div class="form-group">
            <label class="heading_label" for="meest_contract_id">{$btr->sviat__meest__contract_id|escape}</label>
            <input id="meest_contract_id" type="text" name="contract_id" class="form-control" value="{$meest_access.contract_id|escape}"
                   autocomplete="off" spellcheck="false" maxlength="36" {literal}pattern="[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}"{/literal}>
            <p class="text_grey mb-0">{$btr->sviat__meest__contract_id_hint|escape}</p>
        </div>

        <div class="meest-set__buttons">
            <button type="submit" name="meest_action" value="access" class="btn btn_small btn_blue"><span>{$btr->sviat__meest__save|escape}</span></button>
            <button type="submit" name="meest_action" value="check" class="btn btn_small btn-outline-info" data-meest-busy="{$btr->sviat__meest__checking|escape}"><span>{$btr->sviat__meest__check|escape}</span></button>
        </div>

        {if $meest_access.last_check}
            {assign var=meest_check value=$meest_access.last_check}
            <div id="meest_check_result" class="alert mt-1 {if $meest_check.has_problem}alert--warning{else}alert--success{/if}" role="status"><div class="alert__content">
                <div class="alert__title">{$btr->sviat__meest__result_title|escape}</div>
                <p class="text_grey">{$btr->sviat__meest__checked_at|escape} {$meest_check.checked_at|escape}</p>
                <ul>
                    <li>{$btr->sviat__meest__result_token|escape}: <span class="meest-set__badge meest-set__badge--{$meest_check.auth|escape}">{$btr->getTranslation('sviat__meest__status_'|cat:$meest_check.auth)|escape}</span></li>
                    <li>{$btr->sviat__meest__result_contract|escape}: <span class="meest-set__badge meest-set__badge--{$meest_check.contract|escape}">{$btr->getTranslation('sviat__meest__status_'|cat:$meest_check.contract)|escape}</span></li>
                </ul>
                {if $meest_check.info}
                    <dl class="meest-set__facts">
                        <dt>{$btr->sviat__meest__info_name|escape}</dt><dd>{$meest_check.info.name|escape}</dd>
                        <dt>{$btr->sviat__meest__info_edrpou|escape}</dt><dd>{$meest_check.info.edrpou|escape}</dd>
                        {if $meest_check.info.expires_at}
                            <dt>{$btr->sviat__meest__info_expires|escape}</dt><dd>{$meest_check.info.expires_at|escape}</dd>
                        {/if}
                        <dt>{$btr->sviat__meest__info_cash|escape}</dt><dd>{$btr->getTranslation('sviat__meest__flag_'|cat:$meest_check.info.cash)|escape}</dd>
                        <dt>{$btr->sviat__meest__info_noncash|escape}</dt><dd>{$btr->getTranslation('sviat__meest__flag_'|cat:$meest_check.info.noncash)|escape}</dd>
                    </dl>
                {/if}
                {if $meest_check_message}
                    <p class="mt-1 mb-0"><span class="text_grey">{$btr->sviat__meest__meest_answer|escape}:</span> {$meest_check_message|escape}</p>
                {/if}
                {if $meest_check.has_problem}
                    <p class="mt-1 mb-0">{$btr->sviat__meest__problem_advice|escape}</p>
                {/if}
            </div></div>
        {/if}
    </div>
</form>

<form method="post" id="meest_sender_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="meest_action" value="sender">
    {$meest_s = $meest_sender.values}
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__meest__sender_box|escape}</div>
        <p class="text_grey">{$btr->sviat__meest__sender_intro|escape}</p>
        {if $meest_sender_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__meest__saved_message|escape}</p></div></div>
        {/if}
        {if $meest_sender_errors}
            <div class="alert alert--error" role="alert"><div class="alert__content">
                {foreach $meest_sender_errors as $meest_error}
                    <p>{$btr->getTranslation($meest_error)|escape}</p>
                {/foreach}
            </div></div>
        {/if}

        <div class="form-group">
            <label class="heading_label" for="meest_sender_name">{$btr->sviat__meest__sender_name|escape}</label>
            <input id="meest_sender_name" class="form-control" type="text" name="sender_name" maxlength="250" value="{$meest_s.name|escape}">
            {if $meest_access.last_check && $meest_access.last_check.info}
                <p class="text_grey mb-0">{$btr->sviat__meest__sender_name_hint|escape}: {$meest_access.last_check.info.name|escape}</p>
            {/if}
        </div>
        <div class="form-group">
            <label class="heading_label" for="meest_sender_phone">{$btr->sviat__meest__sender_phone|escape}</label>
            <input id="meest_sender_phone" class="form-control" type="tel" inputmode="tel" name="sender_phone" maxlength="20" {literal}pattern="[0-9 \(\)+\-]{10,20}"{/literal} value="{$meest_s.phone|escape}" placeholder="+380XXXXXXXXX">
        </div>

        <div class="form-group" id="meest_sender_city">
            <label class="heading_label" for="meest_sender_city_input">{$btr->sviat__meest__sender_city|escape}</label>
            <input type="hidden" id="meest_sender_city_id" name="sender_city_id" value="{$meest_s.city_id|escape}">
            <div class="meest-set__city-chosen" {if !$meest_sender.city}hidden{/if}>
                <span class="meest-set__city-name">{if $meest_sender.city}<span class="text_grey">{$meest_sender.city.type|escape}</span> <b>{$meest_sender.city.name|escape}</b>{if $meest_sender.city.region}, {$meest_sender.city.region|escape}{/if}{/if}</span>
                <button type="button" class="btn btn_small btn-outline-info meest-set__city-change"><span>{$btr->sviat__meest__sender_city_change|escape}</span></button>
            </div>
            <div class="meest-set__search" {if $meest_sender.city}hidden{/if}>
                <input id="meest_sender_city_input" class="form-control meest-set__city-input" type="text" autocomplete="off" placeholder="{$btr->sviat__meest__sender_city_placeholder|escape}">
                <ul class="meest-set__suggest" role="listbox" hidden></ul>
            </div>
        </div>
        <div class="form-group">
            <label class="heading_label" for="meest_sender_branch_id">{$btr->sviat__meest__sender_branch|escape}</label>
            <select id="meest_sender_branch_id" name="sender_branch_id" class="selectpicker form-control" data-live-search="true" data-size="10">
                <option value="">{$btr->sviat__meest__sender_branch_choose|escape}</option>
                {foreach $meest_sender.branches as $meest_branch}
                    <option value="{$meest_branch.id|escape}" {if $meest_branch.id == $meest_s.branch_id}selected{/if}>{$meest_branch.title|escape}</option>
                {/foreach}
            </select>
            <p class="text_grey mb-0">{$btr->sviat__meest__sender_branch_hint|escape}</p>
        </div>

        <div class="meest-set__buttons">
            <button type="submit" class="btn btn_small btn_blue"><span>{$btr->sviat__meest__save|escape}</span></button>
        </div>
    </div>
</form>

<form method="post" id="meest_dict_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="meest_action" value="dictionaries_run">
    {$meest_d = $meest_dict}
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__meest__dict_box|escape}</div>
        <p class="text_grey">{$btr->sviat__meest__dict_intro|escape}</p>
        {if $meest_dict_requested == 'queued'}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__meest__dict_requested|escape}</p></div></div>
        {elseif $meest_dict_requested == 'no_token'}
            <div class="alert alert--warning" role="alert"><div class="alert__content"><p>{$btr->sviat__meest__dict_no_token|escape}</p></div></div>
        {/if}

        <dl class="meest-set__facts">
            <dt>{$btr->sviat__meest__dict_state|escape}</dt>
            <dd><span class="meest-set__badge meest-set__badge--{$meest_d.state|escape}">{$btr->getTranslation('sviat__meest__dict_state_'|cat:$meest_d.state)|escape}</span></dd>
            {if $meest_d.updated_at}
                <dt>{$btr->sviat__meest__dict_updated|escape}</dt><dd>{$meest_d.updated_at|escape}</dd>
            {/if}
            {if $meest_d.attempted_at}
                <dt>{$btr->sviat__meest__dict_attempted|escape}</dt><dd>{$meest_d.attempted_at|escape}</dd>
            {/if}
            <dt>{$btr->sviat__meest__dict_regions|escape}</dt><dd>{$meest_d.counts.regions|escape}</dd>
            <dt>{$btr->sviat__meest__dict_cities|escape}</dt><dd>{$meest_d.counts.cities|escape}</dd>
            <dt>{$btr->sviat__meest__dict_branches|escape}</dt><dd>{$meest_d.counts.branch|escape}</dd>
            <dt>{$btr->sviat__meest__dict_postomats|escape}</dt><dd>{$meest_d.counts.postomat|escape}</dd>
        </dl>

        {if $meest_d.error}
            <p class="mt-1 mb-0">
                <span class="text_grey">{$btr->sviat__meest__dict_error|escape}:</span>
                {$btr->getTranslation('sviat__meest__dict_err_'|cat:$meest_d.error.kind)|escape}
                {if $meest_d.error.detail}<span class="text_grey">({$meest_d.error.detail|escape})</span>{/if}
            </p>
        {/if}
        {if $meest_d.queued}
            <p class="mt-1 mb-0">{if $meest_d.scheduler_idle}{$btr->sviat__meest__dict_scheduler_idle|escape}{else}{$btr->sviat__meest__dict_queued|escape}{/if}</p>
        {/if}
        {if $meest_d.skipped}
            <div class="alert alert--warning mt-1" role="status"><div class="alert__content">
                <div class="alert__title">{$btr->sviat__meest__dict_skipped|escape}</div>
                <ul>
                    {foreach $meest_d.skipped as $meest_region}
                        <li>{$meest_region.name|escape}: {$btr->getTranslation('sviat__meest__dict_err_'|cat:$meest_region.reason)|escape}{if $meest_region.detail} <span class="text_grey">({$meest_region.detail|escape})</span>{/if}</li>
                    {/foreach}
                </ul>
            </div></div>
        {/if}
        {if $meest_d.empty_regions}
            <div class="alert alert--warning mt-1" role="status"><div class="alert__content">
                <div class="alert__title">{$btr->sviat__meest__dict_empty_regions|escape}</div>
                <p class="mb-0">{foreach $meest_d.empty_regions as $meest_region}{$meest_region|escape}{if !$meest_region@last}, {/if}{/foreach}</p>
            </div></div>
        {/if}
        {if $meest_d.unknown_types}
            <div class="alert alert--info mt-1" role="status"><div class="alert__content">
                <div class="alert__title">{$btr->sviat__meest__dict_unknown_types|escape}</div>
                <ul>
                    {foreach $meest_d.unknown_types as $meest_type}
                        <li>{$meest_type.type|escape}: {$meest_type.count|escape}</li>
                    {/foreach}
                </ul>
            </div></div>
        {/if}

        <div class="meest-set__buttons">
            <button type="submit" class="btn btn_small btn_blue" data-meest-busy="{$btr->sviat__meest__dict_requesting|escape}" {if !$meest_d.can_run}disabled{/if}><span>{$btr->sviat__meest__dict_run|escape}</span></button>
        </div>
    </div>
</form>

<form method="post" id="meest_parcel_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="meest_action" value="parcel_defaults">
    {$meest_p = $meest_parcel.values}
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__meest__parcel_box|escape}</div>
        <p class="text_grey">{$btr->sviat__meest__parcel_intro|escape}</p>
        {if $meest_parcel_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__meest__saved_message|escape}</p></div></div>
        {/if}
        {if $meest_parcel_errors}
            <div class="alert alert--error" role="alert"><div class="alert__content">
                {foreach $meest_parcel_errors as $meest_error}
                    <p>{$btr->getTranslation($meest_error)|escape}</p>
                {/foreach}
            </div></div>
        {/if}

        <div class="form-group">
            <label class="heading_label" for="meest_default_weight">{$btr->sviat__meest__parcel_weight|escape}</label>
            <input id="meest_default_weight" class="form-control" type="number" inputmode="decimal" name="default_weight" min="0.001" max="99999.999" step="0.001" required value="{$meest_p.weight|escape}">
        </div>
        <div class="form-group">
            <span class="heading_label">{$btr->sviat__meest__parcel_box_size|escape}</span>
            <div class="meest-set__box">
                <input class="form-control" type="number" inputmode="numeric" name="box_length" min="1" max="999" step="1" required aria-label="{$btr->sviat__meest__parcel_length|escape}" value="{$meest_p.length|escape}">
                <span>×</span>
                <input class="form-control" type="number" inputmode="numeric" name="box_width" min="1" max="999" step="1" required aria-label="{$btr->sviat__meest__parcel_width|escape}" value="{$meest_p.width|escape}">
                <span>×</span>
                <input class="form-control" type="number" inputmode="numeric" name="box_height" min="1" max="999" step="1" required aria-label="{$btr->sviat__meest__parcel_height|escape}" value="{$meest_p.height|escape}">
            </div>
            <p class="text_grey mb-0">{$btr->sviat__meest__parcel_box_hint|escape}</p>
        </div>

        <div class="form-group">
            <label class="meest-set__switch">
                <span class="switch switch-default">
                    <input class="switch-input" type="checkbox" name="receiver_pay" value="1" {if $meest_p.receiver_pay}checked{/if}>
                    <span class="switch-label"></span>
                    <span class="switch-handle"></span>
                </span>
                <span><b>{$btr->sviat__meest__parcel_receiver_pay|escape}</b></span>
            </label>
        </div>
        <div class="form-group">
            <label class="heading_label" for="meest_pay_type">{$btr->sviat__meest__parcel_pay_type|escape}</label>
            <select id="meest_pay_type" name="pay_type" class="selectpicker form-control">
                <option value="cash" {if $meest_p.pay_type == 'cash'}selected{/if} {if $meest_parcel.forbidden.cash}disabled{/if}>{$btr->sviat__meest__parcel_cash|escape}</option>
                <option value="noncash" {if $meest_p.pay_type == 'noncash'}selected{/if} {if $meest_parcel.forbidden.noncash}disabled{/if}>{$btr->sviat__meest__parcel_noncash|escape}</option>
            </select>
            {if $meest_parcel.forbidden.cash || $meest_parcel.forbidden.noncash}
                <p class="text_grey mb-0">{$btr->sviat__meest__parcel_pay_type_hint|escape}</p>
            {/if}
        </div>

        <div class="form-group">
            <label class="meest-set__switch">
                <span class="switch switch-default">
                    <input class="switch-input" type="checkbox" name="declare_total" value="1" {if $meest_p.declare_total}checked{/if}>
                    <span class="switch-label"></span>
                    <span class="switch-handle"></span>
                </span>
                <span><b>{$btr->sviat__meest__parcel_declare_total|escape}</b></span>
            </label>
        </div>
        <div class="form-group">
            <label class="heading_label" for="meest_declared_value">{$btr->sviat__meest__parcel_declared_value|escape}</label>
            <input id="meest_declared_value" class="form-control" type="number" inputmode="decimal" name="declared_value" min="0" max="9999999.99" step="0.01" value="{$meest_p.declared_value|escape}">
            <p class="text_grey mb-0">{$btr->sviat__meest__parcel_declared_value_hint|escape}</p>
        </div>
        <div class="form-group">
            <label class="heading_label" for="meest_description">{$btr->sviat__meest__parcel_description|escape}</label>
            <input id="meest_description" class="form-control" type="text" name="description" maxlength="250" value="{$meest_p.description|escape}">
        </div>
        <div class="form-group">
            <label class="heading_label" for="meest_notation">{$btr->sviat__meest__parcel_notation|escape}</label>
            <input id="meest_notation" class="form-control" type="text" name="notation" maxlength="100" value="{$meest_p.notation|escape}">
            <p class="text_grey mb-0">{$btr->sviat__meest__parcel_notation_hint|escape}</p>
        </div>
        <div class="form-group">
            <label class="heading_label" for="meest_cutoff">{$btr->sviat__meest__parcel_cutoff|escape}</label>
            <select id="meest_cutoff" name="cutoff" class="selectpicker form-control" data-size="8">
                {foreach $meest_parcel.cutoff_choices as $meest_slot}
                    <option value="{$meest_slot|escape}" {if $meest_slot == $meest_p.cutoff}selected{/if}>{$meest_slot|escape}</option>
                {/foreach}
            </select>
            <p class="text_grey mb-0">{$btr->sviat__meest__parcel_cutoff_hint|escape}</p>
        </div>

        <div class="meest-set__buttons">
            <button type="submit" class="btn btn_small btn_blue"><span>{$btr->sviat__meest__save|escape}</span></button>
        </div>
    </div>
</form>

</div>
<div class="col-xl-6 col-lg-12">

<form method="post" id="meest_groups_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="meest_action" value="groups">
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__meest__groups_box|escape}</div>
        <p class="text_grey">{$btr->sviat__meest__groups_intro|escape}</p>
        {if $meest_groups_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__meest__groups_saved|escape}</p></div></div>
        {/if}
        {if $meest_groups_errors}
            <div class="alert alert--error" role="alert"><div class="alert__content">
                {foreach $meest_groups_errors as $meest_error}
                    <p>{$btr->getTranslation($meest_error)|escape}</p>
                {/foreach}
            </div></div>
        {/if}

        <div class="heading_label">{$btr->sviat__meest__groups_list|escape}</div>
        <div id="meest_group_rows" class="sortable">
            {foreach $meest_groups.groups as $meest_group}
                <div class="meest-set__group">
                    <input type="hidden" data-field="id" value="{$meest_group.id|escape}">
                    <div class="meest-set__drag move_zone" title="{$btr->sviat__meest__drag|escape}">{include file='svg_icon.tpl' svgId='drag_vertical'}</div>
                    <input type="text" class="form-control meest-set__group-name" data-field="name" value="{$meest_group.name|escape}" maxlength="255" aria-label="{$btr->sviat__meest__groups_name|escape}">
                    <label class="meest-set__inline">
                        <span>{$btr->sviat__meest__groups_enabled|escape}</span>
                        <span class="switch switch-default">
                            <input class="switch-input" type="checkbox" data-field="enabled" value="1" {if $meest_group.enabled}checked{/if}>
                            <span class="switch-label"></span>
                            <span class="switch-handle"></span>
                        </span>
                    </label>
                    <input type="hidden" data-field="delete" value="">
                    <button type="button" class="btn_close meest-set__remove meest-set__group-remove" aria-label="{$btr->sviat__meest__groups_delete|escape}" title="{$btr->sviat__meest__groups_delete|escape}">{include file='svg_icon.tpl' svgId='trash'}</button>
                </div>
            {/foreach}
            <div class="meest-set__group meest-set__group--new" hidden>
                <input type="hidden" data-field="id" value="new">
                <div class="meest-set__drag move_zone" title="{$btr->sviat__meest__drag|escape}">{include file='svg_icon.tpl' svgId='drag_vertical'}</div>
                <input type="text" class="form-control meest-set__group-name" data-field="name" value="" maxlength="255" aria-label="{$btr->sviat__meest__groups_name|escape}">
                <label class="meest-set__inline">
                    <span>{$btr->sviat__meest__groups_enabled|escape}</span>
                    <span class="switch switch-default">
                        <input class="switch-input" type="checkbox" data-field="enabled" value="1" checked>
                        <span class="switch-label"></span>
                        <span class="switch-handle"></span>
                    </span>
                </label>
                <button type="button" class="btn_close meest-set__remove meest-set__group-remove" aria-label="{$btr->sviat__meest__groups_delete|escape}" title="{$btr->sviat__meest__groups_delete|escape}">{include file='svg_icon.tpl' svgId='trash'}</button>
            </div>
        </div>
        <button type="button" class="btn btn_mini btn-secondary btn_openSans mt-1 mb-2" id="meest_group_add">
            {include file='svg_icon.tpl' svgId='plus'}
            <span>{$btr->sviat__meest__groups_add|escape}</span>
        </button>

        <div class="heading_label">{$btr->sviat__meest__groups_kinds|escape}</div>
        {foreach $meest_groups.kinds as $meest_kind}
            <div class="meest-set__kind">
                <div>
                    <b>{$btr->getTranslation($meest_kind.label_key)|escape}</b>
                    <span class="text_grey">{$meest_kind.count|escape} {$btr->sviat__meest__groups_points|escape}</span>
                </div>
                <select name="kinds[{$meest_kind.key|escape}]" class="selectpicker form-control meest-set__kind-group" aria-label="{$btr->sviat__meest__groups_group|escape}">
                    <option value="0" {if !$meest_kind.group}selected{/if}>{$btr->sviat__meest__groups_none|escape}</option>
                    {foreach $meest_groups.groups as $meest_group}
                        <option value="{$meest_group.id|escape}" {if $meest_kind.group == $meest_group.id}selected{/if}>{$meest_group.name|escape}</option>
                    {/foreach}
                </select>
                {if $meest_kind.warning}
                    <div class="meest-set__warning">{$btr->getTranslation('sviat__meest__groups_warn_'|cat:$meest_kind.warning)|escape}</div>
                {/if}
            </div>
        {/foreach}

        <button type="submit" class="btn btn_small btn_blue mt-1"><span>{$btr->sviat__meest__save|escape}</span></button>
    </div>
</form>

<form method="post" id="meest_popular_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="meest_action" value="popular">
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__meest__popular_box|escape}</div>
        <p class="text_grey">{$btr->sviat__meest__popular_intro|escape}</p>
        {if $meest_popular_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__meest__popular_saved|escape}</p></div></div>
        {/if}
        <div id="meest_popular_rows" class="sortable">
            {foreach $meest_popular as $meest_city}
                <div class="meest-set__popular">
                    <input type="hidden" name="popular[]" value="{$meest_city.id|escape}">
                    <div class="meest-set__drag move_zone" title="{$btr->sviat__meest__drag|escape}">{include file='svg_icon.tpl' svgId='drag_vertical'}</div>
                    <div class="meest-set__popular-name"><b>{$meest_city.name|escape}</b> <span class="text_grey">{$meest_city.region|escape}</span></div>
                    <button type="button" class="btn_close meest-set__remove" aria-label="{$btr->sviat__meest__popular_remove|escape}" title="{$btr->sviat__meest__popular_remove|escape}">{include file='svg_icon.tpl' svgId='trash'}</button>
                </div>
            {/foreach}
        </div>
        <template id="meest_popular_template">
            <div class="meest-set__popular">
                <input type="hidden" name="popular[]" value="">
                <div class="meest-set__drag move_zone" title="{$btr->sviat__meest__drag|escape}">{include file='svg_icon.tpl' svgId='drag_vertical'}</div>
                <div class="meest-set__popular-name"><b></b> <span class="text_grey"></span></div>
                <button type="button" class="btn_close meest-set__remove" aria-label="{$btr->sviat__meest__popular_remove|escape}" title="{$btr->sviat__meest__popular_remove|escape}">{include file='svg_icon.tpl' svgId='trash'}</button>
            </div>
        </template>
        <p class="text_grey meest-set__popular-empty" {if $meest_popular}hidden{/if}>{$btr->sviat__meest__popular_empty|escape}</p>
        <div class="meest-set__search" id="meest_popular_search">
            <label class="heading_label" for="meest_popular_input">{$btr->sviat__meest__popular_add|escape}</label>
            <input id="meest_popular_input" class="form-control meest-set__city-input" type="text" autocomplete="off" placeholder="{$btr->sviat__meest__sender_city_placeholder|escape}">
            <ul class="meest-set__suggest" role="listbox" hidden></ul>
        </div>
        <button type="submit" class="btn btn_small btn_blue mt-1"><span>{$btr->sviat__meest__save|escape}</span></button>
    </div>
</form>


</div>
</div>
</div>
