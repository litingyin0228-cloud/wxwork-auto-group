<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use app\service\LogService;
use app\service\MessageCallbackService;

/**
 * 处理 Juhebot 消息回调的命令
 */
class ProcessMessage extends Command
{
    private static ?MessageCallbackService $callbackService = null;

    private function getCallbackService(): MessageCallbackService
    {
        if (self::$callbackService === null) {
            self::$callbackService = new MessageCallbackService();
        }
        return self::$callbackService;
    }

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
                        'trace'   => $e->getTraceAsString(), 
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
        $svc = $this->getCallbackService();

        if ($type === 'all' || $type === 'message') {
            $output->writeln('<info>正在处理消息回调...</info>');
            $result = $svc->processMessageCallbacks($guid, $limit);
            $output->writeln('<info>消息回调处理完成: 成功 ' . $result['success'] . ' 条，失败 ' . $result['fail'] . ' 条</info>');
            $output->writeln('');
        }

        if ($type === 'all' || $type === 'apply') {
            $output->writeln('<info>正在处理好友申请...</info>');
            $result = $svc->processApplyContacts($guid, $limit);
            $output->writeln('<info>好友申请处理完成: 成功 ' . $result['success'] . ' 条，失败 ' . $result['fail'] . ' 条</info>');
            $output->writeln('');
        }
    }
}
