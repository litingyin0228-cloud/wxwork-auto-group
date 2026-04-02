<?php
// 事件定义文件
return [
    'bind'      => [
    ],

    'listen'    => [
        'AppInit'  => [],
        'HttpRun'  => [],
        'HttpEnd'  => [],
        'LogLevel' => [],
        'LogWrite' => [],
        'RoomCreated' => [\app\listener\RoomCreated::class],
    ],

    'subscribe' => [
    ],
];
