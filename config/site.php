<?php

/*
|--------------------------------------------------------------------------
| Public site
|--------------------------------------------------------------------------
*/

return [

    /*
    | Show placeholder categories, products and brands on the home page until
    | the catalog exists. Keep it off in production: with it off, those home
    | sections are hidden.
    */
    'demo_content' => (bool) env('SITE_DEMO_CONTENT', false),

];
