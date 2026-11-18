{* Посилка, коли вона вже є: під товарами в основній колонці, як картка накладної NP. *}
{if $meest_parcel && $meest_parcel.mode == 'card'}{include file=$smarty.current_dir|cat:'/parcel.tpl'}{/if}
