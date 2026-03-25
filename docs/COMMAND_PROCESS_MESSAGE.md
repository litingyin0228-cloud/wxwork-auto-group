# 消息处理命令 (ProcessMessage)

用于处理 Juhebot 消息回调数据的命令行工具。

## 功能特性

- 处理待处理的 Juhebot 消息回调
- 处理待处理的好友申请
- 支持指定 GUID 过滤
- 支持循环模式持续处理
- 详细的日志输出

## 使用方法

### 基本用法

```bash
# 处理所有待处理消息
php think process:message

# 处理指定 GUID 的消息
php think process:message --guid=your-guid-here

# 限制每次处理的消息数量
php think process:message --limit=50
```

### 高级用法

```bash
# 只处理消息回调（不处理好友申请）
php think process:message --type=message

# 只处理好友申请（不处理消息）
php think process:message --type=apply

# 循环模式，持续处理消息
php think process:message --loop

# 循环模式，自定义间隔时间（秒）
php think process:message --loop --interval=10

# 组合使用
php think process:message --guid=your-guid --limit=20 --loop --interval=5
```

## 命令参数

| 参数 | 简写 | 必填 | 默认值 | 说明 |
|------|--------|------|---------|------|
| `--guid` | - | 否 | 空 | 指定处理的 GUID，不指定则处理所有 |
| `--limit` | `-l` | 否 | 100 | 每次处理的消息数量 |
| `--type` | `-t` | 否 | `all` | 消息类型：`all`=全部、`message`=消息、`apply`=申请 |
| `--loop` | - | 否 | false | 循环模式，持续处理消息 |
| `--interval` | `-i` | 否 | 5 | 循环间隔时间（秒） |

## 使用场景

### 1. 一次性处理所有待处理消息

```bash
php think process:message
```

### 2. 持续监控并处理消息（推荐用于生产环境）

```bash
php think process:message --loop --interval=10
```

可以使用 Supervisor 或 Systemd 将其设置为后台服务。

### 3. 处理特定实例的消息

```bash
php think process:message --guid=0e950e07-0b01-3019-8269-31cee91ee6bf
```

### 4. 只处理消息回调

```bash
php think process:message --type=message
```

### 5. 只处理好友申请

```bash
php think process:message --type=apply
```

## 输出示例

```
开始处理 Juhebot 消息...
GUID: 全部
限制数量: 100
类型: all
循环模式: 否

正在处理消息回调...
找到 10 条待处理消息
✓ 消息 ID: 1 处理成功
✓ 消息 ID: 2 处理成功
✓ 消息 ID: 3 处理成功
...
消息回调处理完成: 成功 10 条，失败 0 条

正在处理好友申请...
找到 5 条待处理好友申请
✓ 申请 ID: 1 处理成功
✓ 申请 ID: 2 处理成功
✓ 申请 ID: 3 处理成功
...
好友申请处理完成: 成功 5 条，失败 0 条

消息处理完成
```

## 扩展开发

### 自定义消息处理逻辑

在 `app/command/ProcessMessage.php` 中的 `handleMessage` 和 `handleNormalMessage` 方法中添加自定义逻辑：

```php
protected function handleNormalMessage(array $message)
{
    $content = $message['content'] ?? '';
    $sender = $message['sender'] ?? '';

    // 你的自定义逻辑
    if (strpos($content, '关键词') !== false) {
        // 自动回复
        $juhebot = new JuhebotService();
        $juhebot->sendTextMessage('收到关键词消息', '', $message['guid']);
    }
}
```

### 自定义好友申请处理逻辑

在 `handleApplyContact` 方法中添加自定义逻辑：

```php
protected function handleApplyContact(array $apply)
{
    $sender = $apply['sender'] ?? '';
    $senderName = $apply['sender_name'] ?? '';

    // 自动同意好友申请
    $juhebot = new JuhebotService();
    $juhebot->agreeContact($apply['guid'], $sender, '');
}
```

## 使用 Supervisor 部署（推荐）

创建配置文件 `/etc/supervisor/conf.d/wxwork-process-message.conf`：

```ini
[program:wxwork-process-message]
command=php /path/to/wxwork-auto-group/think process:message --loop --interval=5
directory=/path/to/wxwork-auto-group
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/wxwork-process-message.log
```

启动服务：

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start wxwork-process-message
```

## 注意事项

1. **数据库连接**：确保 `.env` 文件中的数据库配置正确
2. **环境变量**：确保 `JUHEBOT.GUID` 等环境变量已配置
3. **日志监控**：使用 `tail -f runtime/log/` 查看日志
4. **性能优化**：根据服务器性能调整 `--limit` 参数
5. **错误处理**：命令会自动记录错误日志，不会因单条消息处理失败而中断

## 相关文件

- 命令文件：`app/command/ProcessMessage.php`
- 配置文件：`config/console.php`
- 模型文件：
  - `app/model/JuhebotMessageCallback.php`
  - `app/model/ApplyContactList.php`
- 服务文件：
  - `app/service/JuhebotService.php`
  - `app/service/LogService.php`
