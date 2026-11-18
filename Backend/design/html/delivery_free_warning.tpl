{foreach $meest_delivery_warnings as $meest_warning}
    {* Червоний — лише для зламаного: без перевірки доступу спосіб ще працює, а безкоштовний для будь-якого кошика вже ховає ціну. *}
    <div class="alert alert--icon {if $meest_warning == 'not_checked_live'}alert--warning{else}alert--error{/if} mt-1">
        <div class="alert__content">
            <div class="alert__title">{$btr->getTranslation('sviat__meest__warn_'|cat:$meest_warning|cat:'_title')|escape}</div>
            <p>{$btr->getTranslation('sviat__meest__warn_'|cat:$meest_warning|cat:'_text')|escape}</p>
        </div>
    </div>
{/foreach}
