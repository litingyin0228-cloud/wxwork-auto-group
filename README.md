# wxwork-auto-group

> 基于 **ThinkPHP 6** 的企业微信「新客户自动建群」服务。
> 当企业微信的员工添加了新客户（外部联系人），系统自动创建一个包含员工和客户服务成员的群聊，并发送欢迎语。

---

## 功能特性

- ✅ 接收企业微信「添加企业客户」事件回调
- ✅ 自动获取客户姓名，以 `{客户名}的专属服务群` 命名群聊
- ✅ 自动拉取跟进员工 + 配置的客服成员入群
- ✅ 建群后自动发送欢迎语
- ✅ 每次建群结果写入数据库日志
- ✅ 提供日志查询 API
- ✅ access_token 自动缓存（7000 秒）

---

## 目录结构

```
wxwork-auto-group/
├── app/
│   ├── controller/
│   │   ├── WxWorkCallbackController.php   # 企业微信回调入口
│   │   ├── AdminController.php             # 管理后台（日志查询）
│   │   └── RpcController.php               # JSON-RPC HTTP 入口
│   └── service/
│       ├── WxWorkService.php               # 企业微信 API 封装
│       ├── WxWorkCrypto.php                # 消息加解密
│       ├── AutoGroupService.php            # 自动建群业务逻辑
│       ├── JuhebotService.php             # 聚合机器人 API 封装
│       ├── JsonRpcServer.php               # JSON-RPC 2.0 服务端
│       └── JsonRpcClient.php               # JSON-RPC 2.0 客户端
├── config/
│   ├── wxwork.php                         # 企业微信配置
│   └── rpc.php                            # RPC 服务配置
├── public/
│   ├── index.php                          # HTTP 入口
│   └── rpc_server.php                      # RPC 独立服务入口（可选）
├── route/
│   └── app.php                            # 路由定义
├── .env                                   # 本地环境变量（需自行填写）
└── .env.example                           # 环境变量模板
```

---

## 快速上手

### 1. 安装依赖

```bash
composer install
```

### 2. 配置环境变量

复制并填写配置：
```bash
cp .env.example .env
```

编辑 `.env`，填入你的企业微信参数：

| 变量                      | 说明                                                  |
|---------------------------|-------------------------------------------------------|
| `WXWORK_CORP_ID`          | 企业ID（「我的企业」->「企业信息」）                  |
| `WXWORK_CORP_SECRET`      | 自建应用 Secret（「应用管理」-> 对应应用）            |
| `WXWORK_CONTACT_SECRET`   | 客户联系 Secret（「客户联系」->「API」）              |
| `WXWORK_CALLBACK_TOKEN`   | 回调验证 Token（「客户联系」->「API」->「指令回调」） |
| `WXWORK_CALLBACK_AES_KEY` | 消息加密 EncodingAESKey                               |
| `WXWORK_AGENT_ID`         | 自建应用 AgentID                                      |
| `WXWORK_GROUP_OWNER`      | 自动建群的群主 userid（企业内部成员）                 |

如需修改群名称模板、默认群成员、欢迎语，编辑 `config/wxwork.php` 中的 `auto_group` 部分：

```php
'auto_group' => [
    'name_tpl'    => '{name}的专属服务群',  // {name} 替换为客户昵称
    'owner'       => '',                     // 留空则使用跟进员工
    'members'     => ['kefu_userid'],        // 固定陪同成员
    'welcome_msg' => '欢迎加入专属服务群！', // 留空则不发送
],
```

### 3. 初始化数据库

创建 MySQL 数据库并执行建表 SQL：

```bash
mysql -u root -p < database/install.sql
```

或手动在 MySQL 客户端中执行 `database/install.sql` 文件。

### 4. 启动开发服务器

```bash
php think run --host=0.0.0.0 --port=8080
```

### 5. 配置企业微信回调

在企业微信管理后台：

1. 进入「客户联系」->「API」->「指令回调」
2. 填写回调 URL：`https://你的域名/wxwork/callback`
3. 填写 Token 和 EncodingAESKey（与 `.env` 保持一致）
4. 点击「保存」—— 企业微信会发送 GET 请求验证 URL

> ⚠️ **注意**：企业微信回调必须使用 **HTTPS** 且公网可访问。
> 本地开发可使用 [ngrok](https://ngrok.com/) 或内网穿透工具。

---

## API 接口

| 方法 | 路径                  | 说明                        |
|------|-----------------------|-----------------------------|
| GET  | `/wxwork/callback`    | 企业微信 URL 接入验证        |
| POST | `/wxwork/callback`    | 接收企业微信事件推送         |
| GET  | `/admin/group-logs`   | 查询建群日志（分页）         |
| GET  | `/admin/health`       | 服务健康检查                 |

查询建群日志示例：

```
GET /admin/group-logs?page=1
```

返回：
```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "total": 10,
    "page": 1,
    "limit": 20,
    "list": [
      {
        "id": 1,
        "external_userid": "wmXXXXX",
        "staff_userid": "zhangsan",
        "customer_name": "李四",
        "chat_id": "wrXXXXX",
        "group_name": "李四的专属服务群",
        "status": 1,
        "error_msg": "",
        "created_at": "2026-03-14 10:00:00"
      }
    ]
  }
}
```

---

## 完整流程图

```
员工在企业微信中添加新客户
         │
         ▼
企业微信推送「add_external_contact」事件至回调 URL
         │
         ▼
WxWorkCallbackController::receive()
  └─ 验签 & AES 解密
         │
         ▼
AutoGroupService::handleNewCustomer()
  ├─ 获取客户昵称
  ├─ 生成群名称
  ├─ 组装成员列表
  ├─ 调用 /appchat/create 创建群聊
  ├─ 发送欢迎语（/appchat/send）
  └─ 写入数据库日志
```

---

## 常见问题

**Q: 创建群聊报错 `errcode: 60020`（应用可见范围不足）**  
A: 在企业微信管理后台，将自建应用的可见范围设置为「全部员工」（根部门）。

**Q: 签名验证失败**  
A: 检查 `.env` 中的 `WXWORK_CALLBACK_TOKEN` 和 `WXWORK_CALLBACK_AES_KEY` 是否与管理后台填写的一致。

**Q: 获取客户详情 403 / 权限不足**  
A: 确认「客户联系」Secret 填写正确，且已在管理后台开启「客户联系」功能。

**Q: 群成员不足 2 人**  
A: 企业微信要求建群成员（含群主）至少 2 人。在 `config/wxwork.php` 的 `members` 中添加至少一名客服成员。

enum NotifyType {
    NotifyTypeUnknown = 0;               // 未知类型
    NotifyTypeManagerSendTask = 573;     // 管理员发送任务通知
    NotifyTypeReady = 11001;             // 机器人就绪（初始化完成，可正常收发消息）
    NotifyTypeLoginQRCodeChange = 11002; // 登录二维码变更（需重新扫码）
    NotifyTypeUserLogin = 11003;         // 用户登录成功
    NotifyTypeUserLogout = 11004;         // 用户登出
    NotifyTypeInitFinish = 11005;        // 初始化完成
    NotifyTypeHeartBeatError = 11006;    // 心跳错误（连接异常，需重连）
    NotifyTypeSessionTimeout = 11007;     // 会话超时
    NotifyTypeLoginFailed = 11008;       // 登录失败
    NotifyTypeContactSyncFinish = 11009; // 联系人同步完成
    NotifyTypeNewMsg = 11010;             // 收到新消息
    NotifyTypeLoginOtherDevice = 11011;  // 账号在其他设备登录
    NotifyTypeLoginSafeVerify = 11012;   // 安全验证（需在后台扫码或调用二维码接口重新扫码，过期会退登）
    NotifyTypeBatchNewMsg = 11013;        // 批量新消息

    NotifyTypeFriendChange = 2131;       // 好友变更（调用同步联系人接口，传入 seq 获取增量数据）
    NotifyTypeFriendApply = 2132;        // 好友申请（调用同步申请好友列表接口，传入 seq 获取增量数据）

    NotifyTypeRoomNameChange = 1001;     // 群名称变更
    NotifyTypeRoomDismiss = 1023;        // 群解散
    NotifyTypeSystemTips = 1037;         // 系统提示消息
    NotifyTypeRoomInfoChange = 2118;     // 群信息变更（调用群增量同步接口，传入 version 获取增量数据）
    NotifyTypeRoomMemberAdd = 1002;      // 群成员增加
    NotifyTypeRoomMemberDel = 1003;      // 群成员减少
    NotifyTypeRoomKickMember = 1004;     // 群踢出成员
    NotifyTypeRoomExit = 1005;           // 主动退出群
    NotifyTypeRoomCreate = 1006;         // 群创建成功
    NotifyTypeRoomConfirmAddMemberNotify = 1029; // 确认添加群成员通知
    NotifyTypeMutaInfoChange = 2115;     // 会话设置信息变更
    NotifyTypeVoipNotify = 2166;          // 语音通话通知
    NotifyTypeWeWorkVoipNotify = 2120;   // 企业微信通话通知
    NotifyTypeSnsChangeNotify = 2215;    // 朋友圈变更通知
    NotifyTypeSnsNotify = 529;            // 朋友圈通知
    NotifyTypeAdminTipsNotify = 573;     // 管理员发消息通知
}

---

## RPC 接口（JSON-RPC 2.0）

本项目支持 **JSON-RPC 2.0** 协议，允许其他系统通过 RPC 方式调用业务服务，无需直接暴露内部接口。

### 1. 快速开始

#### 1.1 启用 RPC

在 `.env` 中添加配置：

```env
[RPC]
RPC_ENABLE        = true
RPC_AUTH_KEY      = your_secret_key_here
RPC_AUTH_ENABLE   = true
RPC_SERVER_HOST   = 0.0.0.0
RPC_SERVER_PORT   = 8090
RPC_TIMEOUT       = 30
```

然后启动开发服务器：

```bash
php think run --host=0.0.0.0 --port=8080
```

RPC 路由自动注册，无需额外配置。

#### 1.2 认证

所有 RPC 请求需在 HTTP 请求头中携带认证密钥：

```
X-RPC-Key: your_secret_key_here
```

或使用 Bearer Token 格式：

```
Authorization: Bearer your_secret_key_here
```

> 关闭认证：`RPC_AUTH_ENABLE = false`（**仅建议在开发环境使用**）

---

### 2. 端点说明

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/rpc` | 处理 JSON-RPC 2.0 请求 |
| GET | `/rpc/methods` | 获取所有可用方法列表 |
| GET | `/rpc/health` | 健康检查 |

---

### 3. 协议格式

#### 3.1 单请求

**请求格式：**

```json
POST /rpc
Content-Type: application/json
X-RPC-Key: your_secret_key_here

{
  "jsonrpc": "2.0",
  "method": "service.method",
  "params": {},
  "id": 1
}
```

**响应格式：**

```json
{
  "jsonrpc": "2.0",
  "result": {},
  "id": 1
}
```

**错误响应：**

```json
{
  "jsonrpc": "2.0",
  "error": {
    "code": -32601,
    "message": "Method not found: xxx"
  },
  "id": 1
}
```

#### 3.2 批量请求

发送多个请求一次，减少网络往返：

```json
POST /rpc
Content-Type: application/json
X-RPC-Key: your_secret_key_here

[
  { "jsonrpc": "2.0", "method": "wxwork.getAccessToken", "params": ["app"], "id": 1 },
  { "jsonrpc": "2.0", "method": "juhebot.getChatList", "params": [1, 20], "id": 2 }
]
```

**批量响应：** 返回数组，顺序与请求一致。

#### 3.3 参数格式

支持**位置参数**和**命名参数**两种格式：

```json
// 位置参数（按方法签名顺序）
{ "jsonrpc": "2.0", "method": "wxwork.createGroupChat", "params": ["群名", "zhangsan", ["zhangsan","lisi"]], "id": 1 }

// 命名参数（推荐，可任意顺序）
{ "jsonrpc": "2.0", "method": "wxwork.createGroupChat", "params": {"chatName": "群名", "owner": "zhangsan", "userList": ["zhangsan","lisi"]}, "id": 1 }
```

---

### 4. 可用服务

#### 4.1 wxwork — 企业微信服务

暴露类：`app\service\WxWorkService`

| 方法名 | 说明 | 参数 |
|--------|------|------|
| `getAccessToken` | 获取 access_token | `$type: string`（`app`\|`contact`）|
| `createGroupChat` | 创建群聊会话 | `$chatName, $owner, $userList, $chatId?` |
| `sendGroupMessage` | 发送群文本消息 | `$chatId, $content` |
| `getExternalContact` | 获取客户详情 | `$externalUserId` |
| `getExternalContactList` | 获取客户列表 | `$limit?` |

**调用示例：**

```json
POST /rpc
{ "jsonrpc": "2.0", "method": "wxwork.createGroupChat", "params": {"chatName": "测试群", "owner": "LiTingYin", "userList": ["LiTingYin", "XiaoKe"]}, "id": 1 }
```

**响应：**

```json
{ "jsonrpc": "2.0", "result": { "chatid": "wrXXXXX" }, "id": 1 }
```

#### 4.2 juhebot — 聚合机器人服务

暴露类：`app\service\JuhebotService`

| 方法名 | 说明 | 参数 |
|--------|------|------|
| `getChatList` | 获取会话列表 | `$page, $pageSize` |
| `getChatDetail` | 获取会话详情 | `$chatId` |
| `getMessages` | 获取消息列表 | `$chatId, $limit` |
| `createChat` | 创建会话 | `$title, $prompt?` |
| `sendMessage` | 发送消息 | `$chatId, $content, $role?` |
| `createExternalGroup` | 创建外部群 | 见方法签名 |
| `updateExternalGroupName` | 更新外部群名称 | `$roomId, $name` |
| `syncApplyContact` | 同步好友申请 | `$applyList` |
| `syncContact` | 同步联系人 | `$contactList` |
| `updateContact` | 更新联系人 | `$guid, $userId?, $desc?, $remark?, ...` |

**调用示例：**

```json
POST /rpc
{ "jsonrpc": "2.0", "method": "juhebot.sendMessage", "params": {"chatId": "abc123", "content": "你好！"}, "id": 1 }
```

#### 4.3 autogroup — 自动建群服务

暴露类：`app\service\AutoGroupService`

| 方法名 | 说明 | 参数 |
|--------|------|------|
| `handleNewCustomer` | 处理新客户事件，自动建群 | `$event`（企业微信回调事件数组） |

**调用示例：**

```json
POST /rpc
{ "jsonrpc": "2.0", "method": "autogroup.handleNewCustomer", "params": {"Event": "change_external_contact", "ChangeType": "add_external_contact", "UserID": "LiTingYin", "ExternalUserID": "wmXXXXX"}, "id": 1 }
```

---

### 5. 错误码说明

| 错误码 | 常量 | 说明 |
|--------|------|------|
| -32700 | `ERR_PARSE_ERROR` | 解析错误（无效 JSON）|
| -32600 | `ERR_INVALID_REQUEST` | 无效请求（版本错误或格式不对）|
| -32601 | `ERR_METHOD_NOT_FOUND` | 方法不存在 |
| -32602 | `ERR_INVALID_PARAMS` | 参数错误（类型不匹配或缺少必需参数）|
| -32603 | `ERR_INTERNAL_ERROR` | 内部错误（服务器异常）|
| -32001 | — | 未授权（认证密钥错误）|

---

### 6. 客户端调用示例

#### PHP（使用内置 JsonRpcClient）

```php
use app\service\JsonRpcClient;

$client = new JsonRpcClient([
    'host' => 'http://127.0.0.1:8080',
    'key'  => 'your_secret_key_here',
]);

// 调用企业微信创建群聊
$result = $client->call('wxwork.createGroupChat', [
    'chatName' => '客户专属服务群',
    'owner'    => 'LiTingYin',
    'userList' => ['LiTingYin', 'XiaoKe'],
]);

// 调用聚合机器人发送消息
$client->call('juhebot.sendMessage', [
    'chatId'  => 'abc123',
    'content' => '您好，欢迎加入！',
]);

// 批量调用
$results = $client->batch([
    ['method' => 'wxwork.getAccessToken', 'params' => ['app']],
    ['method' => 'juhebot.getChatList', 'params' => [1, 20]],
]);

// 查询可用方法
$methods = $client->listMethods();
```

#### Python

```python
import requests

url = "http://127.0.0.1:8080/rpc"
headers = {
    "Content-Type": "application/json",
    "X-RPC-Key": "your_secret_key_here"
}

payload = {
    "jsonrpc": "2.0",
    "method": "wxwork.createGroupChat",
    "params": {
        "chatName": "测试群",
        "owner": "LiTingYin",
        "userList": ["LiTingYin", "XiaoKe"]
    },
    "id": 1
}

resp = requests.post(url, json=payload, headers=headers)
print(resp.json())
```

#### Go

```go
package main

import (
    "bytes"
    "encoding/json"
    "fmt"
    "net/http"
)

func main() {
    payload := map[string]interface{}{
        "jsonrpc": "2.0",
        "method":  "wxwork.createGroupChat",
        "params": map[string]interface{}{
            "chatName": "测试群",
            "owner":    "LiTingYin",
            "userList": []string{"LiTingYin", "XiaoKe"},
        },
        "id": 1,
    }

    body, _ := json.Marshal(payload)
    req, _ := http.NewRequest("POST", "http://127.0.0.1:8080/rpc", bytes.NewReader(body))
    req.Header.Set("Content-Type", "application/json")
    req.Header.Set("X-RPC-Key", "your_secret_key_here")

    client := &http.Client{}
    resp, _ := client.Do(req)
    defer resp.Body.Close()

    var result map[string]interface{}
    json.NewDecoder(resp.Body).Decode(&result)
    fmt.Println(result)
}
```

#### Node.js

```javascript
const axios = require('axios');

const rpcClient = axios.create({
  baseURL: 'http://127.0.0.1:8080',
  headers: { 'X-RPC-Key': 'your_secret_key_here' }
});

async function call(method, params) {
  const { data } = await rpcClient.post('/rpc', {
    jsonrpc: '2.0',
    method,
    params,
    id: Date.now()
  });
  if (data.error) throw new Error(`RPC Error: ${data.error.message}`);
  return data.result;
}

(async () => {
  const result = await call('wxwork.createGroupChat', {
    chatName: '测试群',
    owner: 'LiTingYin',
    userList: ['LiTingYin', 'XiaoKe']
  });
  console.log(result);
})();
```

---

### 7. 安全建议

1. **始终开启认证**：生产环境务必设置 `RPC_AUTH_ENABLE = true` 并使用强密钥
2. **网络隔离**：RPC 服务只暴露给内网或通过 VPN 访问，不公网暴露
3. **密钥管理**：将密钥存储在环境变量或密钥管理服务中，不要硬编码
4. **限流保护**：在网关层对 `/rpc` 路径配置接口限流，防止滥用
5. **日志审计**：RPC 调用日志记录在 `runtime/log/` 中，可通过 `LogService` 查看
