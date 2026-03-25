<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use app\model\JuhebotMessageCallback;
use app\model\ApplyContactList;
use app\service\JuhebotService;
use app\service\LogService;

/**
 * 处理 Juhebot 消息回调的命令
 */
class ProcessMessage extends Command
{
    protected function configure()
    {
        $this->setName('process:message')
            ->addOption('guid', null, Option::VALUE_OPTIONAL, '指定处理的GUID，不指定则处理所有')
            ->addOption('limit', 'l', Option::VALUE_OPTIONAL, '每次处理的消息数量', 100)
            ->addOption('type', 't', Option::VALUE_OPTIONAL, '消息类型：all=全部 message=消息 apply=申请', 'all')
            ->addOption('loop', null, Option::VALUE_NONE, '循环模式，持续处理消息')
            ->addOption('interval', 'i', Option::VALUE_OPTIONAL, '循环间隔时间（秒）', 5)
            ->setDescription('处理 Juhebot 消息回调数据');
    }

    protected function execute(Input $input, Output $output)
    {
        $guid = $input->getOption('guid');
        $limit = (int)$input->getOption('limit');
        $type = $input->getOption('type');
        $loop = $input->getOption('loop');
        $interval = (int)$input->getOption('interval');

        $output->writeln('<info>开始处理 Juhebot 消息...</info>');
        $output->writeln('<comment>GUID: ' . ($guid ?: '全部') . '</comment>');
        $output->writeln('<comment>限制数量: ' . $limit . '</comment>');
        $output->writeln('<comment>类型: ' . $type . '</comment>');
        $output->writeln('<comment>循环模式: ' . ($loop ? '是' : '否') . '</comment>');
        $output->writeln('');

        do {
            try {
                $this->processMessages($output, $guid, $limit, $type);
            } catch (\Throwable $e) {
                $output->writeln('<error>处理消息时发生错误: ' . $e->getMessage() . '</error>');
                LogService::error([
                    'tag'     => 'ProcessMessage',
                    'message' => '处理消息异常',
                    'data'    => [
                        'error'   => $e->getMessage(),
                        'file'    => $e->getFile(),
                        'line'    => $e->getLine(),
                        'trace'    => $e->getTraceAsString(),
                    ],
                ]);
            }

            if ($loop) {
                $output->writeln('<comment>等待 ' . $interval . ' 秒后继续处理...</comment>');
                sleep($interval);
            }
        } while ($loop);

        $output->writeln('<info>消息处理完成</info>');
    }

    /**
     * 处理消息
     *
     * @param Output $output
     * @param string|null $guid
     * @param int $limit
     * @param string $type
     */
    protected function processMessages(Output $output, ?string $guid, int $limit, string $type)
    {
        $this->processMessageCallbacks($output, $guid, $limit, $type);
        $this->processApplyContacts($output, $guid, $limit, $type);
    }

    /**
     * 处理消息回调
     *
     * @param Output $output
     * @param string|null $guid
     * @param int $limit
     * @param string $type
     */
    protected function processMessageCallbacks(Output $output, ?string $guid, int $limit, string $type)
    {
        if ($type !== 'all' && $type !== 'message') {
            return;
        }

        $output->writeln('<info>正在处理消息回调...</info>');

        $messages = !empty($guid)
            ? JuhebotMessageCallback::getPendingMessagesByGuid($guid, $limit)
            : JuhebotMessageCallback::getPendingMessages($limit);

        if (empty($messages)) {
            $output->writeln('<comment>没有待处理的消息</comment>');
            return;
        }

        $output->writeln('<info>找到 ' . count($messages) . ' 条待处理消息</info>');

        $successCount = 0;
        $failCount = 0;

        foreach ($messages as $message) {
            
            try {
                $this->handleMessage($message);
                JuhebotMessageCallback::markAsProcessed($message['id']);
                $successCount++;

                $output->writeln('<info>✓ 消息 ID: ' . $message['id'] . ' 处理成功</info>');

            } catch (\Throwable $e) {
                $failCount++;

                LogService::error([
                    'tag'     => 'ProcessMessage',
                    'message' => '处理单条消息失败',
                    'data'    => [
                        'message_id' => $message['id'],
                        'error'     => $e->getMessage(),
                    ],
                ]);

                $output->writeln('<error>✗ 消息 ID: ' . $message['id'] . ' 处理失败: ' . $e->getMessage() . '</error>');
            }
        }

        $output->writeln('<info>消息回调处理完成: 成功 ' . $successCount . ' 条，失败 ' . $failCount . ' 条</info>');
        $output->writeln('');
    }

    /**
     * 处理好友申请
     *
     * @param Output $output
     * @param string|null $guid
     * @param int $limit
     * @param string $type
     */
    protected function processApplyContacts(Output $output, ?string $guid, int $limit, string $type)
    {
        if ($type !== 'all' && $type !== 'apply') {
            return;
        }

        $output->writeln('<info>正在处理好友申请...</info>');

        $applies = ApplyContactList::getPendingList($guid, $limit);

        if (empty($applies)) {
            $output->writeln('<comment>没有待处理的好友申请</comment>');
            return;
        }

        $output->writeln('<info>找到 ' . count($applies) . ' 条待处理好友申请</info>');

        $successCount = 0;
        $failCount = 0;

        foreach ($applies as $apply) {
            try {
                $this->handleApplyContact($apply);
                ApplyContactList::markAsProcessed($apply['id'], ApplyContactList::STATUS_AGREED);
                $successCount++;

                $output->writeln('<info>✓ 申请 ID: ' . $apply['id'] . ' 处理成功</info>');

            } catch (\Throwable $e) {
                $failCount++;

                LogService::error([
                    'tag'     => 'ProcessMessage',
                    'message' => '处理好友申请失败',
                    'data'    => [
                        'apply_id' => $apply['id'],
                        'error'    => $e->getMessage(),
                    ],
                ]);

                $output->writeln('<error>✗ 申请 ID: ' . $apply['id'] . ' 处理失败: ' . $e->getMessage() . '</error>');
            }
        }

        $output->writeln('<info>好友申请处理完成: 成功 ' . $successCount . ' 条，失败 ' . $failCount . ' 条</info>');
        $output->writeln('');
    }

    /**
     * 处理单条消息
     *
     * @param array $message
     * @return void
     */
    protected function handleMessage(array $message)
    {
        $notifyType = $message['notify_type'] ?? 0;
        $content = $message['content'] ?? '';
        $sender = $message['sender'] ?? '';
        $senderName = $message['sender_name'] ?? '';

        LogService::info([
            'tag'     => 'ProcessMessage',
            'message' => '开始处理消息',
            'data'    => [
                'notify_type' => $notifyType,
                'sender'      => $sender,
                'sender_name' => $senderName,
                'content'     => $content,
            ],
        ]);

        // 根据通知类型处理不同的消息
        switch ($notifyType) {
            case 11010:
                // 普通消息
                $this->handleNormalMessage($message);
                break;

            default:
                LogService::warning([
                    'tag'     => 'ProcessMessage',
                    'message' => '未知的消息类型',
                    'data'    => [
                        'notify_type' => $notifyType,
                        'message_id'  => $message['id'],
                        'content'     => $message['content']
                    ],
                ]);
                break;
        }
    }

    /**
     * 处理普通消息
     *
     * @param array $message
     * @return void
     */
    protected function handleNormalMessage(array $message)
    {
        // TODO: 实现普通消息处理逻辑
        // 例如：自动回复、转发、保存等 

        $content = $message['content'] ?? '';// 处理好友请求 “请求添加你为联系人”
        $senderName = $message['sender_name'] ?? '';

        

        // 1、同意好友请求
        $juhebot = new JuhebotService();
        $result = $juhebot->agreeContact($message['sender']);

        // 2、创建外部群 -- 1688853366477816 企业的客服人员
        $roomResult = $juhebot->createOuterRoom([$message['sender'],"1688853366477816"]);

        // 3、发送欢迎语
        $room_id = $roomResult['data']['roomid'];// 得到房间号
        $juhebot->sendText("R:".$room_id, $senderName . ' 欢迎加入群聊，我们将为您提供专业的一对一服务。');

    }

    /**
     * 处理好友申请
     *
     * @param array $apply
     * @return void
     */
    protected function handleApplyContact(array $apply)
    {
        $sender = $apply['sender'] ?? '';
        $senderName = $apply['sender_name'] ?? '';
        $content = $apply['content'] ?? '';

        LogService::info([
            'tag'     => 'ProcessMessage',
            'message' => '处理好友申请',
            'data'    => [
                'sender'      => $sender,
                'sender_name' => $senderName,
                'content'     => $content,
            ],
        ]);

        // TODO: 实现好友申请处理逻辑
        // 例如：自动同意、通知管理员等

        // 示例：自动同意好友申请
        // $juhebot = new JuhebotService();
        // $juhebot->agreeContact($apply['guid'], $sender, '');
    }
}
