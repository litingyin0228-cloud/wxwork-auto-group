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
Route::get('wxwork/callback',  'WxWorkCallbackController@verify');
Route::post('wxwork/callback', 'WxWorkCallbackController@receive');

// ─── 管理后台路由 ───────────────────────────────────────────────
Route::get('admin/group-logs', 'AdminController@groupLogs');
Route::get('admin/health',     'AdminController@health');
