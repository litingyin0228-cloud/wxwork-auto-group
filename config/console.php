<?php
// +----------------------------------------------------------------------
// | 控制台配置 
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'process:message'   => 'app\command\ProcessMessage',
        'rpc:test'         => 'app\command\RpcTest',
        'sync:label'       => 'app\command\SyncLabelList',
        'refresh:label'    => 'app\command\RefreshLabel',
        'sync:old_contact' => 'app\command\SyncOldContactList',
    ],
];
