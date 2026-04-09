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
use app\model\LabelList;

/**
 * 同步标签列表命令
 *
 * 支持翻页同步，通过 JuhebotService::syncLabelList() 获取标签，
 * 按 sync_type（1=企业标签 2=个人标签）分别存储到 wxwork_label_list 表中。
 */
class SyncLabelList extends Command
{
    private ?JuhebotService $juhebot = null;

    private string $seq = '';

    private int $totalHandled = 0;

    private int $totalInserted = 0;

    private int $totalUpdated = 0;

    protected function configure()
    {
        $this->setName('sync:label')
            ->addArgument('sync_type', Argument::OPTIONAL, '标签类型：1=企业标签 2=个人标签（默认）', '2')
            ->addArgument('seq', Argument::OPTIONAL, '起始 seq（为空则从 0 开始）', '')
            ->addOption('limit', 'l', Option::VALUE_OPTIONAL, '每批处理数量', 100)
            ->addOption('max', 'm', Option::VALUE_OPTIONAL, '最大处理条数（0=不限制）', 0)
            ->setDescription('同步标签列表到 wxwork_label_list 表');
    }

    protected function execute(Input $input, Output $output)
    {
        $syncType = (int)$input->getArgument('sync_type');
        if (!in_array($syncType, [1, 2], true)) {
            $output->writeln('<error>sync_type 必须是 1（企业标签）或 2（个人标签）</error>');
            return;
        }

        $this->seq = trim($input->getArgument('seq'));
        $limit = (int)$input->getOption('limit');
        $max = (int)$input->getOption('max');

        if ($limit <= 0) {
            $limit = 100;
        }

        $typeLabel = $syncType === 1 ? '企业标签' : '个人标签';

        $output->writeln('<info>===== 标签同步开始 =====</info>');
        $output->writeln('<comment>标签类型 : ' . $typeLabel . '</comment>');
        $output->writeln('<comment>起始 seq : ' . ($this->seq ?: '（从头开始）') . '</comment>');
        $output->writeln('<comment>每批数量 : ' . $limit . '</comment>');
        $output->writeln('<comment>最大条数 : ' . ($max > 0 ? $max : '不限制') . '</comment>');
        $output->writeln('');

        try {
            $this->runLoop($output, $limit, $max, $syncType);
        } catch (\Throwable $e) {
            $output->writeln('<error>同步异常: ' . $e->getMessage() . '</error>');
            LogService::error([
                'tag'     => 'SyncLabel',
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
        $output->writeln('<info>本次运行：处理 ' . $this->totalHandled . ' 条，新增 ' . $this->totalInserted . ' 条，更新 ' . $this->totalUpdated . ' 条</info>');
        $output->writeln('<info>最终 seq : ' . $this->seq . '</info>');
    }

    /**
     * 主循环：分批调用 syncLabelList，存储到数据库
     */
    private function runLoop(Output $output, int $limit, int $max, int $syncType): void
    {
        $hasMore = true;

        while ($hasMore) {
            if ($max > 0 && $this->totalHandled >= $max) {
                $output->writeln('<comment>已达到最大处理条数限制，提前退出</comment>');
                break;
            }

            $res = $this->callSyncLabelList($this->seq, $limit, $syncType);

            if (empty($res) || empty($res['data'])) {
                $output->writeln('<warn>接口返回数据为空，停止同步</warn>');
                break;
            }

            $labelList = $res['data']['label_list'] ?? [];
            $lastSeq = (string)($res['data']['last_seq'] ?? '');

            if (empty($labelList)) {
                $output->writeln('<info>本批无数据，停止同步（last_seq: ' . $lastSeq . '）</info>');
                $this->seq = $lastSeq;
                break;
            }

            $result = $this->saveBatch($labelList, $syncType);

            $this->totalHandled  += count($labelList);
            $this->totalInserted += $result['inserted'];
            $this->totalUpdated  += $result['updated'];
            $this->seq = $lastSeq;

            $output->writeln(sprintf(
                '<info>批次完成：新增 %d 条，更新 %d 条，last_seq=%s</info>',
                $result['inserted'],
                $result['updated'],
                $lastSeq
            ));
        }
    }

    /**
     * 调用 JuhebotService::syncLabelList
     */
    private function callSyncLabelList(string $seq, int $limit, int $syncType): array
    {
        if ($this->juhebot === null) {
            $this->juhebot = new JuhebotService();
        }

        return $this->juhebot->syncLabelList($seq, $syncType);
    }

    /**
     * 批量写入/更新数据库（upsert），按主键 id 判断
     *
     * @return array ['inserted' => int, 'updated' => int]
     */
    private function saveBatch(array $labelList, int $syncType): array
    {
        if (empty($labelList)) {
            return ['inserted' => 0, 'updated' => 0];
        }

        $inserted = 0;
        $updated  = 0;

        foreach ($labelList as $item) {
            $id = (string)($item['id'] ?? $item['label_id'] ?? '');

            if ($id === '') {
                continue;
            }

            if (LabelList::existsById($id)) {
                $updateData = LabelList::diffLabelUpdate($item);
                try {
                    LabelList::updateById($id, $updateData);
                    $updated++;
                } catch (\Throwable $e) {
                    LogService::warning([
                        'tag'     => 'SyncLabel',
                        'message' => '更新记录失败，已忽略',
                        'data'    => [
                            'id'    => $id,
                            'error' => $e->getMessage(),
                        ],
                    ]);
                }
            } else {
                $data = LabelList::mapLabelToRow($item, $syncType);
                try {
                    LabelList::insert($data);
                    $inserted++;
                } catch (\Throwable $e) {
                    LogService::warning([
                        'tag'     => 'SyncLabel',
                        'message' => '写入记录失败，已忽略',
                        'data'    => [
                            'id'    => $id,
                            'error' => $e->getMessage(),
                        ],
                    ]);
                }
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }
}
