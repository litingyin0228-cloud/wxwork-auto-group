<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use app\service\JuhebotService;
use app\service\LogService;
use app\model\LabelLog;

/**
 * 刷新已成交用户的企业标签
 *
 * 逻辑：
 * 1. 从 wxwork_apply_contact_list 查出 bind_org_id 非空的记录
 * 2. 用 bind_org_id 查 tax_org_vip，vip_end_date > 当天 = 已成交
 * 3. 已成交用户调用 JuhebotService::updateContact 打上企业标签 
 *
 * 用法：
 *   php think refresh:label
 *   php think refresh:label --limit=50
 *   php think refresh:label --dry-run
 */
class RefreshLabel extends Command
{
    private const CORP_ID = "1970324956094061";
    /**
     * 标签配置（按业务类型组织）
     * 扩展时只需在此二维数组中追加新的业务类型和对应标签即可
     *
     * @var array [business_type => [
     *     'label_id'       => string,
     *     'label_groupid'  => string,
     *     'name'           => string 描述，用于日志输出,
     * 
     * ]]
     */
    private const LABEL_CONFIGS = [
        // 已成交
        1 => [
            'label_id'      => '14073750253938239',
            'label_groupid' => '14073750563560224',
            'name'          => '已成交',
        ],
        // 示例：潜在客户（后续扩展时在此追加新业务类型即可）
        // 2 => [
        //     'label_id'      => 'xxxxxxxxxx',
        //     'label_groupid' => 'yyyyyyyyyy',
        //     'name'          => '潜在客户',
        // ],
    ];

    private JuhebotService $juhebot;

    protected function configure()
    {
        $this->setName('refresh:label')
            ->addOption('limit', 'l', Option::VALUE_OPTIONAL, '每批处理数量（默认 100）', 100)
            ->addOption('dry-run', null, Option::VALUE_NONE, '仅打印，不实际打标签')
            ->addOption('since', 's', Option::VALUE_OPTIONAL, '仅处理 updated_at >= 指定日期（格式 Y-m-d）', '')
            ->setDescription('刷新已成交用户的企业标签');
    }

    protected function execute(Input $input, Output $output)
    {
        $limit   = max(1, (int)$input->getOption('limit'));
        $dryRun  = (bool)$input->getOption('dry-run');
        $since   = trim($input->getOption('since'));

        $output->writeln('<info>===== 刷新企业标签开始 =====</info>');
        $output->writeln('<comment>每批数量 : ' . $limit . '</comment>');
        $output->writeln('<comment>仅预览模式 : ' . ($dryRun ? '是' : '否') . '</comment>');
        if ($since !== '') {
            $output->writeln('<comment>仅处理更新时间 >= ' . $since . ' 的记录</comment>');
        }
        $output->writeln('');

        $this->juhebot = new JuhebotService();
        $today = date('Y-m-d');
        $totalProcessed = 0;
        $totalTagged    = 0;
        $totalSkipped   = 0;
        $totalError     = 0;

        try {
            do {
                // 1. 查出 bind_org_id 非空的用户，排除 label_log 中已成功或重试超限的记录
                $query = Db::table('wxwork_apply_contact_list')
                    ->where('bind_org_id', '<>', '')
                    ->where('bind_org_id', '<>', '0')
                    ->where('status', '<>', 4) // 排除已删除
                    ->where('is_refresh', '=', 0) // 排除已删除
                    // ->where('user_id',"in", ["7881300231337572", "7881300271944846"]) // DEBUG:测试使用
                    ->whereRaw("NOT EXISTS (SELECT 1 FROM wxwork_label_log WHERE wxwork_label_log.user_id = wxwork_apply_contact_list.user_id AND wxwork_label_log.org_id = wxwork_apply_contact_list.bind_org_id AND (wxwork_label_log.is_success = 1 OR wxwork_label_log.retry_count > 3))"); 
                
                
                if ($since !== '') {
                    $query->whereTime('updated_at', '>=', $since);
                }

                $applies = $query->limit($limit)->select()->toArray();
                
                if (empty($applies)) {
                    break;
                }
                // 更新为已处理状态
                Db::table('wxwork_apply_contact_list')->where("id","in", array_column($applies, 'id'))->update(["is_refresh" => 1]);

                // 2. 收集所有 org_id
                $orgIds = array_unique(array_filter(array_column($applies, 'bind_org_id')));
                
                // 3. 批量查询 tax_org_vip（vip_end_date > today）
                $vipRows = Db::table('tax_org_vip')
                    ->whereIn('org_tax_id', $orgIds)
                    ->select()
                    ->toArray();
                // key: org_id => value: row（含 vip_end_date）
                $vipMap = [];
                foreach ($vipRows as $row) {
                    $vipMap[(string)$row['org_tax_id']] = $row;
                }
                $this->processBatch(
                    $output,
                    $applies,
                    $vipMap,
                    $dryRun,
                    $totalProcessed,
                    $totalTagged,
                    $totalSkipped,
                    $totalError
                );

                // 无数据或已全部处理完则退出
                if (count($applies) < $limit) {
                    break;
                }

            } while (true);
        } catch (\Throwable $e) {
            $output->writeln('<error>执行异常: ' . $e->getMessage() . '</error>');
            LogService::error([
                'tag'     => 'RefreshLabel',
                'message' => '刷新标签命令异常',
                'data'    => [
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                ],
            ]);
        }

        $output->writeln('');
        $output->writeln('<info>===== 刷新完成 =====</info>');
        $output->writeln('<info>共处理 ' . $totalProcessed . ' 条，已打标签 ' . $totalTagged . ' 条，跳过 ' . $totalSkipped . ' 条，异常 ' . $totalError . ' 条</info>');
    }

    /**
     * 逐条处理批次数据
     *
     * @param Output $output
     * @param array  $applies       本批 apply_contact_list 记录
     * @param array  $vipMap        [org_id => tax_org_vip row] 已VIP映射
     * @param bool   $dryRun
     * @param int    &$totalProcessed
     * @param int    &$totalTagged
     * @param int    &$totalSkipped
     * @param int    &$totalError
     */
    private function processBatch(
        Output $output,
        array $applies,
        array $vipMap,
        bool $dryRun,
        int &$totalProcessed,
        int &$totalTagged,
        int &$totalSkipped,
        int &$totalError
    ): void {
        foreach ($applies as $apply) {
            $applyId = $apply['id']           ?? 0;
            $userId  = $apply['user_id']       ?? '';
            $orgId   = (string)($apply['bind_org_id'] ?? '');
            $mobile  = $apply['mobile']        ?? '';
            $totalProcessed++;
          
            if ($userId === '') {
                $output->writeln('<warn>跳过（user_id 为空）: apply_id=' . $applyId . ', mobile=' . $mobile . '</warn>');
                $totalSkipped++;
                continue;
            }

            // 检查是否已达到最大重试次数（超过3次则不再处理）
            if (LabelLog::hasExceededMaxRetries($userId, $orgId, 3)) {
                $retryCount = LabelLog::getRetryCount($userId, $orgId);
                $output->writeln('<comment>跳过（已达最大重试次数）: user_id=' . $userId . ', org_id=' . $orgId . ', 重试=' . $retryCount . '次</comment>');
                $totalSkipped++;
                continue;
            }

            $vipRow  = $vipMap[$orgId] ?? null;
            $isVip   = $vipRow !== null;

            if (!$isVip) {
                $totalSkipped++;
                continue;
            }

            $vipEndDate = $vipRow['vip_end_date'] ?? null;

            if ($dryRun) {
                $labelNames = [];
                foreach (self::LABEL_CONFIGS as $cfg) {
                    $labelNames[] = $cfg['name'] ?? '';
                }
                $output->writeln('<info>【预览】将为用户打标签: user_id=' . $userId . ', org_id=' . $orgId . ', vip_end_date=' . $vipEndDate . '，标签: ' . implode(' / ', $labelNames) . '</info>');
                $totalTagged++;
                continue;
            }

            $labelConfigs = self::LABEL_CONFIGS;
            $labelSuccessCount = 0;
            $allLabelIds = array_column($labelConfigs, 'label_id');
            
            foreach ($labelConfigs as $businessType => $cfg) {
                $cfgLabelId = $cfg['label_id'];

                if (LabelLog::hasCurrentLabel($userId, $orgId, $cfgLabelId)) {
                    $output->writeln('<comment>跳过 [' . ($cfg['name'] ?? $businessType) . ']（已打该标签）: user_id=' . $userId . ', label_id=' . $cfgLabelId . '</comment>');
                    continue;
                }

                try {
                    $this->applyLabel($userId, $orgId, $businessType, $vipEndDate, $applyId, $allLabelIds);
                    $labelSuccessCount++;
                } catch (\Throwable $e) {
                    $retryCount = LabelLog::getRetryCount($userId, $orgId);

                    if ($retryCount >= 2) {
                        // 已达到最大重试次数（总共3次：1次成功+2次失败，或3次失败）
                        $output->writeln('<error>已达最大重试次数，标记不再处理: user_id=' . $userId . ', org_id=' . $orgId . ', 累计重试=' . ($retryCount + 1) . '次</error>');
                        LabelLog::logFail(
                            $userId, $orgId, $cfgLabelId, $cfg['label_groupid'],
                            $businessType, $e->getMessage(), $vipEndDate, $applyId,
                            $allLabelIds, $retryCount + 1
                        );
                        break;
                    }

                    LabelLog::logFail(
                        $userId, $orgId, $cfgLabelId, $cfg['label_groupid'],
                        $businessType, $e->getMessage(), $vipEndDate, $applyId,
                        $allLabelIds, $retryCount + 1
                    );
                }
            }
            // 如果所有标签都跳过（已存在），不计入错误也不算成功
            $allSkipped = ($labelSuccessCount === 0 && !empty($labelConfigs));
            if ($labelSuccessCount > 0) {
                $output->writeln('<info>打标签成功: user_id=' . $userId . ', org_id=' . $orgId . ', 本次 ' . $labelSuccessCount . ' 个标签</info>');
                $totalTagged++;

                // 重命名群名称（在原名称前加 ✅）
                $this->renameRoom($userId, $orgId, $output);
            } elseif ($allSkipped) {
                // 所有标签都已存在，属于正常跳过
                $totalSkipped++;
            } else {
                // 确实有标签要打但全部失败了
                $totalError++;
            }
        }
    }

    /**
     * 调用 JuhebotService 为用户打单个标签，并写入标签日志
     *
     * @param string      $userId
     * @param string      $orgId
     * @param int         $businessType  业务类型（对应 LABEL_CONFIGS 的 key）
     * @param string|null $vipEndDate
     * @param int         $applyId
     * @param array       $allLabelIds   本次全部标签ID列表
     * @throws \RuntimeException 打标签失败时抛出
     */
    private function applyLabel(string $userId, string $orgId, int $businessType, ?string $vipEndDate, int $applyId = 0, array $allLabelIds = []): void
    {
        $cfg = self::LABEL_CONFIGS[$businessType] ?? null;
        if ($cfg === null) {
            return;
        }
        $res = $this->juhebot->contactAddLabel([$userId], $cfg['label_id'], self::CORP_ID, $cfg['label_groupid']);        
        LabelLog::logSuccess(
            $userId, $orgId,
            $cfg['label_id'], $cfg['label_groupid'], $businessType,
            $vipEndDate, $applyId, $allLabelIds
        );

        LogService::info([
            'tag'     => 'RefreshLabel',
            'message' => '标签更新成功 [' . ($cfg['name'] ?? $businessType) . ']',
            'data'    => [
                'user_id'  => $userId,
                'org_id'   => $orgId,
                'biz_type' => $businessType,
                'label_id' => $cfg['label_id'],
                'label_groupid' => $cfg['label_groupid'],
            ],
        ]);
    }

    /**
     * 重命名群名称（在原名称前加 VIP）
     *
     * @param string $userId 用户ID
     * @param Output $output 输出对象
     * @param int $orgId 企业ID
     */
    private function renameRoom(string $userId, int $orgId,  Output $output): void
    {
        // 根据 org_id 查找 tax_org 中的企业名称
        $orgName = Db::table('tax_org')
            ->where('tax_id', $orgId)
            ->value('name');
        if (empty($orgName)) {
            $output->writeln('<comment>未找到企业名称，跳过重命名: org_id=' . $orgId . '</comment>');
            return;
        }
        // $orgName 最多8个字，超过的用...代替
        if (mb_strlen($orgName) > 8) {
            $orgName = mb_substr($orgName, 0, 8) . '...';
        }

        // 通过 user_id 查找 contact_room 中的群信息和群名称
        $contactRoom = Db::table('wxwork_contact_room')
            ->where('user_id', $userId)
            ->find();

        if (empty($contactRoom)) {
            $output->writeln('<comment>未找到用户关联的群，跳过重命名: user_id=' . $userId . '</comment>');
            return;
        }

        $roomId = $contactRoom['room_id'] ?? '';
        $currentName = $contactRoom['room_name'] ?? '';
        // 一键零申报&ganganlee（钟玲-2026年04月11日）
        // 当前名称中不包含企业名称的话，把企业名称替换 &"企业名称"（ 之前的多余部分
        if (strpos($currentName, $orgName) === false) {
            $currentName = preg_replace('/&.+?（/', "&" . $orgName . "（", $currentName, 1);
        }
        if (empty($currentName)) {
            $output->writeln('<comment>群名称为空，跳过重命名: room_id=' . $roomId . '</comment>');
            return;
        }

        // 如果已经带有 VIP 前缀，则不处理
        if (strpos($currentName, 'VIP') === 0) {
            $output->writeln('<comment>群名称已带 VIP 前缀，跳过: room_id=' . $roomId . ', name=' . $currentName . '</comment>');
            return;
        }

        $newName = 'VIP' . $currentName;
        
        try {
            // 调用 JuhebotService 修改群名称
            $this->juhebot->modifyRoomName($roomId, $newName);

            // 更新 contact_room 表中的群名称
            Db::table('wxwork_contact_room')
                ->where('user_id', $userId)
                ->update(['room_name' => $newName, 'update_at' => date('Y-m-d H:i:s')]);

            $output->writeln('<info>群名称重命名成功: room_id=' . $roomId . ', ' . $currentName . ' -> ' . $newName . '</info>');

            LogService::info([
                'tag'     => 'RefreshLabel',
                'message' => '群名称重命名成功',
                'data'    => [
                    'user_id'  => $userId,
                    'room_id'  => $roomId,
                    'old_name' => $currentName,
                    'new_name' => $newName,
                ],
            ]);
        } catch (\Throwable $e) {
            $output->writeln('<error>群名称重命名失败: room_id=' . $roomId . ', error=' . $e->getMessage() . '</error>');

            LogService::error([
                'tag'     => 'RefreshLabel',
                'message' => '群名称重命名失败',
                'data'    => [
                    'user_id' => $userId,
                    'room_id' => $roomId,
                    'error'   => $e->getMessage(),
                ], 
            ]);
        }
    }
}
