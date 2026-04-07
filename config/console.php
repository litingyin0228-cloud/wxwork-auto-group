<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'process:message'   => 'app\command\ProcessMessage',
        'rpc:test'         => 'app\command\RpcTest',
        'sync:old_contact' => 'app\command\SyncOldContactList',
    ],
];
