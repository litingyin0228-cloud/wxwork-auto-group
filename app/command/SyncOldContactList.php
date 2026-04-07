<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output; 
use app\service\JuhebotService;
use app\service\LogService;
use app\model\ApplyContactListOld;

/**
 * 同步历史联系人命令
 *
 * 每批处理 100 条，利用 JuhebotService::syncContact() 返回的 last_seq
 * 进行翻页，存储到 wxwork_apply_contact_list_old 表中，
 * 遇到 user_id 已存在的记录则跳过。
 */
class SyncOldContactList extends Command
{
    private ?JuhebotService $juhebot = null;

    private string $seq = '';

    private int $totalHandled = 0;

    private int $totalInserted = 0;

    private int $totalSkipped = 0;

    protected function configure()
    {
        $this->setName('sync:old_contact')
            ->addArgument('seq', Argument::OPTIONAL, '起始 seq（为空则从 0 开始）', '')
            ->addOption('limit', 'l', Option::VALUE_OPTIONAL, '每批处理数量', 100)
            ->addOption('max', 'm', Option::VALUE_OPTIONAL, '最大处理条数（0=不限制）', 0)
            ->setDescription('同步历史联系人到 wxwork_apply_contact_list_old 表');
    }

    protected function execute(Input $input, Output $output)
    {
        $this->seq = trim($input->getArgument('seq'));
        $limit = (int)$input->getOption('limit');
        $max = (int)$input->getOption('max');

        if ($limit <= 0) {
            $limit = 100;
        }

        $output->writeln('<info>===== 历史联系人同步开始 =====</info>');
        $output->writeln('<comment>起始 seq : ' . ($this->seq ?: '（从头开始）') . '</comment>');
        $output->writeln('<comment>每批数量 : ' . $limit . '</comment>');
        $output->writeln('<comment>最大条数 : ' . ($max > 0 ? $max : '不限制') . '</comment>');
        $output->writeln('');

        try {
            $this->runLoop($output, $limit, $max);
        } catch (\Throwable $e) {
            $output->writeln('<error>同步异常: ' . $e->getMessage() . '</error>');
            LogService::error([
                'tag'     => 'SyncOldContact',
                'message' => '同步异常',
                'data'    => [
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                ],
            ]);
        }

        $output->writeln('');
        $output->writeln('<info>===== 同步完成 =====</info>');
        $output->writeln('<info>本次运行：处理 ' . $this->totalHandled . ' 条，新增 ' . $this->totalInserted . ' 条，跳过 ' . $this->totalSkipped . ' 条</info>');
        $output->writeln('<info>最终 seq : ' . $this->seq . '</info>');
    }

    /**
     * 主循环：分批调用 syncContact，存储到数据库
     */
    private function runLoop(Output $output, int $limit, int $max): void
    {
        $hasMore = true;

        while ($hasMore) {
            if ($max > 0 && $this->totalHandled >= $max) {
                $output->writeln('<comment>已达到最大处理条数限制，提前退出</comment>');
                break;
            }

            $res = $this->callSyncContact($limit);

            if (empty($res) || empty($res['data'])) {
                $output->writeln('<warn>接口返回数据为空，停止同步</warn>');
                break;
            }

            $contactList = $res['data']['contact_list'] ?? [];
            $lastSeq = (string)($res['data']['last_seq'] ?? '');
            $isEmpty = empty($contactList);

            if ($isEmpty) {
                $output->writeln('<info>本批无数据，停止同步（last_seq: ' . $lastSeq . '）</info>');
                $this->seq = $lastSeq;
                break;
            }

            $batchInserted = $this->saveBatch($contactList);
            $batchSkipped = count($contactList) - $batchInserted;

            $this->totalHandled += count($contactList);
            $this->totalInserted += $batchInserted;
            $this->totalSkipped += $batchSkipped;
            $this->seq = $lastSeq;

            $output->writeln(sprintf(
                '<info>批次完成：新增 %d 条，跳过 %d 条，last_seq=%s</info>',
                $batchInserted,
                $batchSkipped,
                $lastSeq
            ));
        }
    }

    /**
     * 调用 JuhebotService::syncContact
     */
    private function callSyncContact(int $limit): array
    {
        if ($this->juhebot === null) {
            $this->juhebot = new JuhebotService();
        }

        return $this->juhebot->syncContact($this->seq, $limit);
    }

    /**
     * 批量存储到数据库，已存在 user_id 的记录跳过
     *
     * @return int 实际新增的记录数
     */
    private function saveBatch(array $contactList): int
    {
        if (empty($contactList)) {
            return 0;
        }

        $inserted = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($contactList as $item) {
            $userId = $item['user_id'] ?? '';

            if ($userId === '') {
                continue;
            }

            if (ApplyContactListOld::existsByUserId($userId)) {
                continue;
            }

            $data = ApplyContactListOld::mapContactToRow($item, $now);

            try {
                ApplyContactListOld::insert($data);
                $inserted++;
            } catch (\Throwable $e) {
                LogService::warning([
                    'tag'     => 'SyncOldContact',
                    'message' => '写入单条记录失败，已忽略',
                    'data'    => [
                        'user_id' => $userId,
                        'error'   => $e->getMessage(),
                    ],
                ]);
            }
        }

        return $inserted;
    }
}
