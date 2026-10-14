<?php

return [
    'stun_urls' => array_values(array_filter(explode(',', env('CALL_STUN_URLS', 'stun:stun.l.google.com:19302')))),
    'turn_urls' => array_values(array_filter(explode(',', env('CALL_TURN_URLS', '')))),
    'turn_secret' => env('CALL_TURN_SECRET'),
    'relay_only' => env('CALL_RELAY_ONLY', false),
];
