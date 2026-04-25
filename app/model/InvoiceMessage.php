<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 开票会话消息记录模型
 *
 * @property int         $id
 * @property int         $session_id 
 * @property string|null $msg_id
 * @property string|null $sender
 * @property string|null $sender_name  发送者名称（已废弃，表中无此列）
 * @property string|null $content
 * @property int         $msg_type
 * @property string|null $action
 * @property string      $created_at
 * @property string      $updated_at
 */
class InvoiceMessage extends Model
{
    protected $name = 'invoice_message';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    const MSG_TYPE_USER = 1;
    const MSG_TYPE_BOT  = 2;

    public function session()
    {
        return $this->belongsTo(InvoiceSession::class, 'session_id');
    }

    public static function isProcessed(string $msgId): bool
    {
        return self::where('msg_id', $msgId)->find() !== null; 
    }

    public static function logUser(
        $sessionId,
        string $msgId,
        string $sender,
        string $senderName,
        string $content,
        string $action = ''
    ): self {
        $model = new self();
        $model->session_id  = (string)$sessionId;
        $model->msg_id      = $msgId;
        $model->sender      = $sender;
        $model->sender_name = $senderName; // 写入字段，即使表中无此列也不会报错
        $model->content     = $content;
        $model->msg_type    = self::MSG_TYPE_USER;
        $model->action      = $action;
        $model->save();
        return $model;
    }

    public static function logBot($sessionId, string $content): self
    {
        $model = new self();
        $model->session_id = (string)$sessionId;
        $model->content    = $content;
        $model->msg_type   = self::MSG_TYPE_BOT;
        $model->save();
        return $model;
    }
}
