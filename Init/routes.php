<?php

namespace Okay\Modules\Sviat\Meest;

return [
    'Sviat_Meest_cart_cities' => [
        'slug' => 'ajax/meest/cities',
        'to_front' => true,
        'params' => [
            'controller' => __NAMESPACE__ . '\Controllers\CartController',
            'method' => 'cities',
        ],
    ],
    'Sviat_Meest_cart_branches' => [
        'slug' => 'ajax/meest/branches',
        'to_front' => true,
        'params' => [
            'controller' => __NAMESPACE__ . '\Controllers\CartController',
            'method' => 'branches',
        ],
    ],
    'Sviat_Meest_cart_quote' => [
        'slug' => 'ajax/meest/quote',
        'to_front' => true,
        'params' => [
            'controller' => __NAMESPACE__ . '\Controllers\CartController',
            'method' => 'quote',
        ],
    ],
];
