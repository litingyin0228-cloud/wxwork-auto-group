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
│   │   └── AdminController.php            # 管理后台（日志查询）
│   └── service/
│       ├── WxWorkService.php              # 企业微信 API 封装
│       ├── WxWorkCrypto.php               # 消息加解密
│       └── AutoGroupService.php           # 自动建群业务逻辑
├── config/
│   └── wxwork.php                         # 企业微信配置
├── database/
│   └── init.php                           # 数据库初始化脚本
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
