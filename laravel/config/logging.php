<?php

return [

    'channels' => [
        'additional_log' => [
            'driver' => 'daily',
            'path' => storage_path('logs/additional_log.log'),
            'level' => 'info',
            'days' => 365,
        ],
    ],

];
