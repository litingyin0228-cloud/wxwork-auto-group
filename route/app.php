<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use think\facade\Route;


// ─── 企业微信回调路由 ───────────────────────────────────────────
// GET  /wxwork/callback  — 企业微信 URL 接入验证
// POST /wxwork/callback  — 接收事件推送（新增客户 → 自动建群）
Route::get('wxwork/callback',  'WxWorkCallbackController/verify');
Route::post('wxwork/callback', 'WxWorkCallbackController/receive');
Route::get('get_add_contact_way_er_code', 'Index/getAddContactWayErCode');

// ─── 管理后台路由 ───────────────────────────────────────────────
Route::get('admin/group-logs', 'AdminController/groupLogs');
Route::get('admin/health',     'AdminController/health');

// ─── 联系人列表路由 ────────────────────────────────────────────
Route::get('contact/index',        'ContactListController/index');
Route::get('contact/list',         'ContactListController/list');
Route::get('contact/pending-count','ContactListController/pendingCount');
Route::post('contact/agree',       'ContactListController/agree');
Route::post('contact/reject',      'ContactListController/reject');
Route::post('contact/batch-agree',  'ContactListController/batchAgree');
Route::post('contact/batch-reject', 'ContactListController/batchReject');

// ─── 消息列表路由 ──────────────────────────────────────────────
Route::get('message/index',                  'MessageListController/index');
Route::get('message/list',                   'MessageListController/list');
Route::get('message/detail',                 'MessageListController/detail');
Route::post('message/mark-processed',         'MessageListController/markProcessed');
Route::post('message/batch-mark-processed',  'MessageListController/batchMarkProcessed');

// ─── 默认路由 ───────────────────────────────────────────────────
Route::get('can_create_group', 'Index/isCreateGroup');

Route::post('create_group_complete', 'Index/createGroupComplete');
Route::post('wxworkMsgCallback', 'Index/wxworkMsgCallback');

// ─── JSON-RPC 路由 ─────────────────────────────────────────────
Route::post('rpc',       'RpcController/index');
Route::get('rpc/methods','RpcController/methods');
Route::get('rpc/health', 'RpcController/health');