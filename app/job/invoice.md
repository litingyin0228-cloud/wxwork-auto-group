# Invoice 开票对话系统操作文档

## 一、系统概述

本系统基于 **状态机 + 消息队列** 实现开票自动化对话流程。用户通过 @机器人 发送开票请求，机器人引导用户确认信息，最终自动完成开票。

### 核心组件

| 组件 | 职责 |
|---|---|
| `InvoiceSessionService` | 接收群消息，路由对话，解析用户指令（确认/修改/取消） |
| `InvoiceJob` | 队列任务，按 `next_action` 驱动流程前进（发消息、解析、开票、轮询、通知） |
| `InvoiceSession` | 会话模型，持久化所有开票数据和流程状态 |
| `InvoiceMessage` | 消息日志，防止消息重复消费 |

### 数据库会话表 `wxwork_invoice_session` 关键字段

| 字段 | 类型 | 说明 |
|---|---|---|
| `next_action` | varchar | 当前队列动作（见下节），null 表示流程结束 |
| `confirm_round` | int unsigned | 用户确认轮次，首次确认为 1，修改后递增 |
| `status` | int | 会话状态：0=解析中 1=等待确认 2=开票中 3=完成 4=取消 99=异常 |
| `step` | int | 当前步骤：1=收到 2=确认 3=开票 4=发送文件 |
| `step_data` | json | 流程数据（原始消息、开票ID、查询结果等） |
| `expires_at` | datetime | 会话过期时间，超时自动结束 |

---

## 二、队列动作（next_action）一览

| next_action | 处理者 | 说明 |
|---|---|---|
| `send_ack` | `InvoiceJob::queueActionSendAck` | Bot 发"收到，请稍等" |
| `parse_confirm` | `InvoiceJob::queueActionParseConfirm` | 解析用户消息，发确认摘要 |
| `receive_confirm` | `InvoiceSessionService::handleUserReply` | **Service 同步处理**，无需入队 |
| `submit_invoice` | `InvoiceJob::queueActionSubmitInvoice` | 提交开票申请 |
| `wait_result` | `InvoiceJob::queueActionWaitResult` | 轮询开票结果（最多 10 次，间隔 10 秒） |
| `notify_result` | `InvoiceJob::queueActionNotifyResult` | 通知用户开票结果 |

> **`receive_confirm` 特殊说明**：该步骤由 `InvoiceSessionService` 在接收到用户消息时**同步处理**（不入队），根据用户回复内容决定后续流程。只有在用户回复"确认"后，才会通过 `InvoiceJob::drive()` 入队推进。

---

## 三、消息投递时机详解

### 3.1 触发入口

```
用户 @机器人 发送"我要开票"
        │
        ▼
InvoiceSessionService::handleMessage()
        │
        ├─ isTriggerKeyword("我要开票") → true
        ├─ startSession() 创建会话
        │    next_action = 'send_ack'
        │    confirm_round = 1
        │    step_data = { raw_content: "我要开票..." }
        │
        └─ InvoiceJob::drive(sessionId)
             Queue::push(InvoiceJob::class, { session_id: 123 }, 'invoice')
```

### 3.2 各步骤 Job 投递关系

```
[Service] startSession()
   └─ InvoiceJob::drive(sessionId)
           │
           ▼ Queue::push
      ┌─────────────────────────────────────────────────────┐
      │  Job 1: fire() → queueActionSendAck()               │
      │  ├─ Bot 发"收到！这就为您安排开票..."                │
      │  ├─ next_action = 'parse_confirm'                   │
      │  └─ dispatch(id, 'parse_confirm')                   │
      │          Queue::push(..., delay=0)                  │
      └─────────────────────────────────────────────────────┘
           │
           ▼ Queue::push
      ┌─────────────────────────────────────────────────────┐
      │  Job 2: fire() → queueActionParseConfirm()         │
      │  ├─ parseInvoiceContent(用户原始消息)               │
      │  ├─ markAwaitConfirm(parsed)                        │
      │  │    status = 1, confirm_round = 1                │
      │  │    next_action = 'receive_confirm'               │
      │  ├─ Bot 发确认摘要（第 1 轮确认）                   │
      │  └─ delete()（等待用户回复）                        │
      └─────────────────────────────────────────────────────┘
           │
           ▼ 用户回复（Service 同步处理，不入队）
      ┌─────────────────────────────────────────────────────┐
      │  Service::handleUserReply()                         │
      │  ├─ isCancelWord() → 取消                           │
      │  ├─ isConfirmWord() → 确认（见 3.3）               │
      │  └─ applyUserModification() → 修改（见 3.4）        │
      └─────────────────────────────────────────────────────┘
```

---

## 四、完整消息投递示例

### 场景 A：一句话触发 → 确认 → 完成（最小交互）

```
用户:  @机器人 我要开票
          ↓ handleMessage()
机器人: 收到！这就为您安排开票，我先确认下您的开票信息，您可持续补充，请稍等~
          ↓ Job 2 解析后
机器人: 好的，请确认我收集到的信息是否准确（第 1 轮确认）：
        抬头：（未填写）
        是否为自然人：否
        纳税识别号：（未填写）
        票种类型：普票
        服务项目明细：
        开票金额：0.0

        如信息无误，请告知我为您开票；如有问题，请告诉我需要修改的内容。

用户:  @机器人 确认
          ↓ handleMessage()
          ↓ handleConfirm()
          ↓ next_action = 'submit_invoice'
          ↓ InvoiceJob::drive(sessionId)
机器人: 好的，正在为您开票中，请稍等~
          ↓ Job 提交后轮询
          ↓ Job 结果通知
机器人: 发票已经开好
        发票号码：FP202604190001
        [附件] 电子发票.pdf
```

**投递序列：**

| 顺序 | 触发时机 | `next_action` | Queue::push / later | 延迟 |
|---|---|---|---|---|
| 1 | `startSession()` | `send_ack` | `push` | 0s |
| 2 | Job 1 结束 | `parse_confirm` | `push` | 0s |
| 3 | 用户回复"确认" | `submit_invoice` | `push` | 0s |
| 4 | Job 3 结束 | `wait_result` | `push` | 0s |
| 5~14 | Job 4 轮询 | `wait_result` | `later` | 10s × 最多10次 |
| 15 | Job 4 查到结果 | `notify_result` | `push` | 0s |

---

### 场景 B：触发 → 修改金额 → 确认 → 完成（用户修改一次）

```
用户:  @机器人 开电子发票
          ↓ startSession()
机器人: 收到！这就为您安排开票...
          ↓ Job 2 解析
机器人: 好的，请确认我收集到的信息是否准确（第 1 轮确认）：
        抬头：（未填写）
        ...
        票种类型：普票（← 解析了"电子"，但默认普票）
        开票金额：0.0

用户:  @机器人 金额改成1000元
          ↓ handleUserReply()
          ↓ applyUserModification() 检测到金额
          ↓ confirm_round = 1 + 1 = 2
          ↓ session.save() × 2
机器人: 已更新信息（第 2 轮确认）。
        好的，请确认我收集到的信息是否准确（第 2 轮确认）：
        ...
        开票金额：1000.0

用户:  @机器人 确认
          ↓ handleConfirm()
          ↓ next_action = 'submit_invoice'
          ↓ InvoiceJob::drive(sessionId)
机器人: 好的，正在为您开票中，请稍等~
          ...
```

**投递序列：**

| 顺序 | 触发时机 | `next_action` | 特殊说明 |
|---|---|---|---|
| 1 | `startSession()` | `send_ack` | |
| 2 | Job 1 结束 | `parse_confirm` | |
| 3 | Job 2 解析完毕 | `receive_confirm` | **不入队，Service 同步等待** |
| 4 | 用户回复"金额改成1000元" | `receive_confirm` | confirm_round=2，**不入队** |
| 5 | 用户回复"确认" | `submit_invoice` | **drive() 入队** |
| 6~后续 | 同场景 A | | |

---

### 场景 C：触发 → 修改公司名 → 修改税号 → 确认 → 完成（用户修改多次）

```
用户:  @机器人 开票
机器人: 收到！这就为您安排开票...
机器人: 好的，请确认我收集到的信息是否准确（第 1 轮确认）：
        抬头：（未填写）
        ...

用户:  @机器人 公司改成北京科技有限公司
          ↓ applyUserModification()
          ↓ confirm_round = 2
机器人: 已更新信息（第 2 轮确认）。
        抬头：北京科技有限公司
        ...

用户:  @机器人 税号改成91110000XXXXXXXX
          ↓ applyUserModification()
          ↓ confirm_round = 3
机器人: 已更新信息（第 3 轮确认）。
        抬头：北京科技有限公司
        纳税识别号：91110000XXXXXXXX
        ...

用户:  @机器人 确认
          ↓ handleConfirm()
          ↓ InvoiceJob::drive(sessionId)
机器人: 好的，正在为您开票中，请稍等~
          ...
```

**confirm_round 递增规则：**

- 用户每次修改任意字段，`confirm_round` 加 1
- 轮次在摘要消息标题中展示：`（第 N 轮确认）`
- 最终确认开票时，`confirm_round` 保持不变，不再递增

---

### 场景 D：触发 → 用户发无效消息 → 提示重试 → 确认

```
用户:  @机器人 开票
机器人: 收到！...
机器人: 好的，请确认我收集到的信息是否准确（第 1 轮确认）：
        ...

用户:  @机器人 好的谢谢
          ↓ handleUserReply()
          ↓ isConfirmWord() → false
          ↓ applyUserModification() → false
机器人: 未识别到有效指令，请直接回复【确认】开票，或告知需要修改的内容（如：把公司名改成xxx）。

用户:  @机器人 那确认吧
          ↓ isConfirmWord() → true
          ↓ handleConfirm()
机器人: 好的，正在为您开票中，请稍等~
          ...
```

---

### 场景 E：触发 → 用户取消

```
用户:  @机器人 开票
机器人: 收到！...
机器人: 好的，请确认我收集到的信息是否准确（第 1 轮确认）：
        ...

用户:  @机器人 算了不要了
          ↓ isCancelWord() 检测到"算了"/"不要了"
          ↓ handleCancel()
          ↓ markCancelled()
          ↓ next_action = null（流程结束）
机器人: 已取消开票，有需要随时@我。
```

**投递序列：**

| 顺序 | 触发时机 | 结果 |
|---|---|---|
| 1 | `startSession()` | 入队 |
| 2 | Job 1 结束 | 入队 |
| 3 | Job 2 解析完毕 | **不入队** |
| 4 | 用户回复"算了不要了" | **handleCancel()，无队列投递** |

> 注意：取消发生在任意阶段（Job 1 / Job 2 / Job 3 执行过程中），系统均会通过 `handleException` 或 `failed` 捕获并清理状态，不会残留异常会话。

---

### 场景 F：触发 → 确认 → 开票接口失败

```
用户:  @机器人 开票
机器人: 收到！...
机器人: 好的，请确认我收集到的信息是否准确（第 1 轮确认）：
        ...

用户:  @机器人 确认
          ↓ handleConfirm()
          ↓ InvoiceJob::drive(sessionId)
机器人: 好的，正在为您开票中，请稍等~

          ↓ Job 3 执行 submitToInvoiceApi()
          ↓ API 返回 { success: false, message: "企业税号校验不通过" }
          ↓ catch Throwable
          ↓ session.markError("企业税号校验不通过")
          ↓ notifyError(session, "企业税号校验不通过")
机器人: 开票处理出现异常：企业税号校验不通过
        请稍后重试或联系客服。

          ↓ Job 重试（attempts < 3 → release 30s）
          ↓ 3 次重试仍失败
          ↓ failed() 回调
          ↓ markError("开票任务失败，已超过最大重试次数")
机器人: 开票失败，请联系客服处理。
```

**失败时状态变化：**

| 时机 | `status` | `next_action` | `error_msg` |
|---|---|---|---|
| 异常捕获 | `STATUS_ERROR = 99` | `null` | 具体错误信息 |
| 3 次重试耗尽 | `STATUS_ERROR = 99` | `null` | "开票任务失败，已超过最大重试次数" |

---

### 场景 G：触发 → 确认 → 开票成功 → 轮询超时

```
用户:  @机器人 开票
...
用户:  @机器人 确认

          ↓ Job 3 提交成功，dispatch 'wait_result'

          ↓ Job 4 轮询（第 1 次）
          ↓ queryInvoiceResult() → done=false
          ↓ retry_count = 1
          ↓ later(10s) 重试

          ↓ Job 4 轮询（第 2 次 ~ 第 9 次）同上

          ↓ Job 4 轮询（第 10 次）
          ↓ retry_count >= 10
          ↓ throw RuntimeException("开票查询超时，请稍后重试")
          ↓ session.markError("开票查询超时，请稍后重试")
          ↓ notifyError(session)
机器人: 开票处理出现异常：开票查询超时，请稍后重试
        请稍后重试或联系客服。
```

---

## 五、消息防重机制

### 5.1 入库消息去重

```php
// InvoiceMessage::isProcessed() 基于 msg_id 去重
if ($msgId !== '' && InvoiceMessage::isProcessed($msgId)) {
    return false; // 已处理过的消息直接忽略
}
```

### 5.2 最新消息标记

```php
// handleConfirm() 中标记最新消息 ID，防止同一条消息被重复消费
$session->updateLatestMsgId($msgId);
```

### 5.3 Job 状态安全

```php
// Job 执行前三重检查
if ($sessionId <= 0) { $job->delete(); return; }          // 无效 ID
if ($session === null) { $job->delete(); return; }          // 会话不存在
if ($session->next_action === null) { $job->delete(); return; } // 流程已结束
```

### 5.4 先写库再入队

```php
// dispatch() 中先更新 session.next_action，再入队
$session->next_action = $nextAction;
$session->save();
Queue::push(static::class, ['session_id' => $sessionId], 'invoice');
```

保证即使 Job 延迟执行，拿到的 `next_action` 也与数据库一致。

---

## 六、消费者部署

```bash
# 开发环境监听
php think queue:work --queue=invoice

# 生产环境守护模式
php think queue:work --queue=invoice --daemon

# 同时监听多个队列
php think queue:work --queue=invoice,invoice_simulate

# 查看队列积压（Redis 直连）
redis-cli -n 6 LLEN think_queue_invoice
```

---

## 七、状态对照表

### 会话状态（status）

| 值 | 常量 | 含义 | 说明 |
|---|---|---|---|
| 0 | `STATUS_PARSING` | 信息解析中 | `createSession()` 初始值 |
| 1 | `STATUS_AWAIT_CONFIRM` | 等待用户确认 | `markAwaitConfirm()` 设置 |
| 2 | `STATUS_PROCESSING` | 开票处理中 | `markProcessing()` 设置 |
| 3 | `STATUS_COMPLETED` | 已完成 | `markCompleted()` 设置 |
| 4 | `STATUS_CANCELLED` | 已取消 | `handleCancel()` / `markCancelled()` |
| 99 | `STATUS_ERROR` | 异常 | `markError()` / `handleException()` |

### 步骤（step）

| 值 | 常量 | 含义 |
|---|---|---|
| 1 | `STEP_RECEIVE` | 收到开票请求 |
| 2 | `STEP_CONFIRM` | 等待确认/修改 |
| 3 | `STEP_INVOICING` | 开票处理中 |
| 4 | `STEP_SEND_FILE` | 发票文件已发送 |

### confirm_round（确认轮次）

| 值 | 含义 |
|---|---|
| 1 | 首次确认摘要 |
| 2~N | 用户修改后第 N 轮确认摘要 |

---

## 八、关键词对照

### 触发关键字

`开票` `开发票` `要发票` `开电子发票` `开纸质发票` `发票`

### 确认关键词

`确认` `确定` `好的` `是` `ok` `yes` `开` `开票`

### 取消关键词

`取消` `算了` `不要了` `no` `cancel`

### 修改识别格式

| 字段 | 示例格式 |
|---|---|
| 公司名 | `公司：xxx` `公司改成xxx` `抬头xxx` `企业xxx` |
| 税号 | `税号：xxx` `税号改成xxx` 或直接发 15~24 位数字字母 |
| 金额 | `金额：1000` `金额改成500元` `500元` |
| 票种 | `普票` `专票` `电子` `纸质` |
| 自然人 | `自然人：是` `个人：否` `企业` |
| 项目 | `项目：咨询服务` `服务项目改成xxx` |
