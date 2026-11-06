<?php

return [

    'admin' => [
        // Panel path. Should match the panel's ->path().
        'path' => 'admin',

        // Optional panel version metadata exposed through mekaya()->version().
        'version' => 'v1',

        // Optional brand logo path from the host application's /public directory.
        // A logo configured directly on the Filament panel takes precedence.
        'brand' => null,

        // Optional compact brand icon path from the host application's /public directory.
        // When neither a panel logo nor this icon exists, the application name is used.
        'brand_icon' => 'images/icon.svg',

        // Optional logo height. Null preserves the value configured on the panel.
        'brand_logo_height' => null,

        // Optional favicon path from the host application's /public directory.
        // Null preserves the favicon configured directly on the Filament panel.
        'favicon' => 'images/icon.svg',
    ],

];
