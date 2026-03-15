<?php
// 企业微信配置
return [
    // 企业ID（在企业微信管理后台「我的企业」->「企业信息」中查看）
    'corp_id'     => env('WXWORK.WXWORK_CORP_ID', 'your_corp_id'),

    // 自建应用的 Secret（在企业微信管理后台「应用管理」->「自建」-> 对应应用中查看）
    'corp_secret' => env('WXWORK.WXWORK_CORP_SECRET', 'your_corp_secret'),

    // 客户联系 Secret（在企业微信管理后台「客户联系」->「API」中查看）
    'contact_secret' => env('WXWORK.WXWORK_CONTACT_SECRET', 'your_contact_secret'),

    // 企业微信回调消息加密 Token（在「客户联系」->「API」->「指令回调URL」中填写）
    'callback_token'  => env('WXWORK.WXWORK_CALLBACK_TOKEN', 'your_callback_token'),

    // 企业微信回调消息加密 EncodingAESKey
    'callback_aes_key' => env('WXWORK.WXWORK_CALLBACK_AES_KEY', 'your_encoding_aes_key'),

    // 应用 AgentID（创建群聊所用的自建应用）
    'agent_id'    => env('WXWORK.WXWORK_AGENT_ID', 'your_agent_id'),

    // 自动建群配置
    'auto_group' => [
        // 群名称模板，{name} 会被替换为客户名
        'name_tpl'   => '{name}的专属服务群',

        // 群主（企业成员 userid，由该成员作为群主）
        'owner'      => env('WXWORK.WXWORK_GROUP_OWNER', 'XiaoKe'),

        // 默认陪同成员列表（userid 数组），群主会自动加入，无需重复填写
        'members'    => ["LiTingYin"],

        // 欢迎语（创建群后由群主发送，留空则不发送）
        'welcome_msg' => '您好！欢迎加入专属服务群，有任何问题请随时告知，我们会尽快为您解答 😊',
    ],

    // access_token 缓存键名
    'token_cache_key' => 'wxwork_access_token',
];
