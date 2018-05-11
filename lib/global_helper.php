<?php

use AspenDigital\Resizer\Classes\ResizeService;

if (!function_exists('smartResize')) {
    function smartResize($image, $width, $height, $options = [], $priority = null)
    {
        return App::make(ResizeService::class)->thumb($image, $width, $height, $options, $priority);
    }
}