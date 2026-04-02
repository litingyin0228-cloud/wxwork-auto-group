<?php
declare(strict_types=1);

namespace app\service;

use app\model\ApplyContactList;
use app\service\LogService;
use think\facade\Cache;
use think\facade\Db;

/**
 * 新客户自动建群业务逻辑
 */
class AutoGroupService
{
    private WxWorkService $wxWork;
    private array $config;
    private static ?JuhebotService $juhebotService = null;

    public function __construct(WxWorkService $wxWork)
    {
        $this->wxWork = $wxWork;
        $this->config = config('wxwork.auto_group') ?? [];
    }

    /**
     * 获取 JuhebotService 单例
     *
     * @return JuhebotService
     */
    private function getJuhebot(): JuhebotService
    {
        if (self::$juhebotService === null) {
            self::$juhebotService = new JuhebotService();
        }
        return self::$juhebotService;
    }

    // // 处理新客户事件- 同步好友申请
    // public function handleNewCustomerV2()
    // {
       
    // }

    /**
     * 处理"添加企业客户"事件，自动创建群聊
     *
     * 注意：企业微信限制，创建群聊（/appchat/create）只能添加内部员工成员。
     * 外部联系人（客户）需要通过扫码入群等方式加入，无法直接由 API 拉入。
     * 当前实现：创建包含相关员工的内部服务群，用于协调客户服务。
     *
     * 事件结构示例：
     * {
     *   "Event": "change_external_contact",
     *   "ChangeType": "add_external_contact",
     *   "UserID": "zhangsan",              // 跟进成员 userid
     *   "ExternalUserID": "wmXXXXX",       // 新客户的 external_userid
     * }
     *
     * @param array $event 解密后的事件数组
     * @return array 操作结果
     */
    public function handleNewCustomer(array $event): array
    {
        $staffUserId    = $event['UserID']         ?? '';
        $externalUserId = $event['ExternalUserID'] ?? '';
        $mobile = $event['State'] ?? '';

        if (empty($staffUserId) || empty($externalUserId)) {
            LogService::warning([
                'tag'     => 'AutoGroup',
                'message' => '事件缺少必要字段',
                'data'    => $event,
            ]);
            return ['success' => false, 'msg' => '事件字段不完整'];
        }

        LogService::info([
            'tag'     => 'AutoGroup',
            'message' => '收到新客户事件',
            'data'    => [
                'staff'    => $staffUserId,
                'customer' => $externalUserId,
            ],
        ]);
        try {
            // 1. 获取客户详情（昵称等）
            $customerName = $this->resolveCustomerName($externalUserId);

            $this->saveLog([
                'external_userid' => $externalUserId,
                'staff_userid'    => $staffUserId,
                'customer_name'   => $customerName,
                'chat_id'         => '',
                'group_name'      => '暂无群聊',
                'status'          => 1,
                'error_msg'       => '',
                'mobile'          => $mobile ?? '',
            ]);
        

        // // 2. 生成群名称
        // $groupName = str_replace('{name}', $customerName, $this->config['name_tpl']);

        // // 3. 构建群成员列表：群主 + 跟进员工 + 客服成员
        // $owner   = $this->config['owner'] ?: $staffUserId;
        // $members = array_values(array_unique(array_merge(
        //     [$owner, $staffUserId],
        //     $this->config['members']
        // )));

        // // 企业微信规定：成员数至少 2 人（含群主）
        // if (count($members) < 2) {
        //     $members[] = $staffUserId; // 兜底：加入跟进员工
        //     $members   = array_values(array_unique($members));
        // }

        // // 把外部联系人加入到 members
        // $members[] = $externalUserId;

        // // 4. 创建群聊
        // try {
        //     $result  = $this->wxWork->createGroupChat($groupName, $owner, $members);
        //     $chatId  = $result['chatid'];

        //     // 5. 发送欢迎语（可选）
        //     $welcomeMsg = $this->config['welcome_msg'];
        //     if (!empty($welcomeMsg)) {
        //         $this->wxWork->sendGroupMessage($chatId, $welcomeMsg);
        //     }

        //     LogService::info([
        //         'tag'     => 'AutoGroup',
        //         'message' => '建群完成',
        //         'data'    => [
        //             'chatid'   => $chatId,
        //             'name'     => $groupName,
        //             'customer' => $externalUserId,
        //         ],
        //     ]);

        //     // 6. 记录日志到数据库
        //     $this->saveLog([
        //         'external_userid' => $externalUserId,
        //         'staff_userid'    => $staffUserId,
        //         'customer_name'   => $customerName,
        //         'chat_id'         => $chatId,
        //         'group_name'      => $groupName,
        //         'status'          => 1,
        //         'error_msg'       => '',
        //     ]);

            return [
                'success'   => true,
                'msg'       => '事件处理成功',
            ];
        } catch (\Throwable $e) {
            LogService::error([
                'tag'     => 'AutoGroup',
                'message' => '建群失败',
                'data'    => [
                    'error'    => $e->getMessage(),
                    'customer' => $externalUserId,
                    'staff'    => $staffUserId,
                ],
            ]);

            $this->saveLog([
                'external_userid' => $externalUserId,
                'staff_userid'    => $staffUserId,
                'customer_name'   => $customerName ?? '',
                'chat_id'         => '',
                'group_name'      => '',
                'status'          => 0,
                'error_msg'       => $e->getMessage(),
            ]);

            return ['success' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * 写入建群日志
     */
    private function saveLog(array $data): void
    {
        try {
            Db::table('wxwork_group_chat_log')->insert($data);
        } catch (\Throwable $e) {
            LogService::warning([
                'tag'     => 'AutoGroup',
                'message' => '日志写入失败',
                'data'    => ['error' => $e->getMessage()],
            ]);
        }
    }

    /**
     * 尝试获取客户名称，失败时返回默认值
     */
    private function resolveCustomerName(string $externalUserId): string
    {
        try {
            $contact = $this->wxWork->getExternalContact($externalUserId);
            return $contact['name'] ?? '新客户';
        } catch (\Throwable $e) {
            LogService::warning([
                'tag'     => 'AutoGroup',
                'message' => '获取客户名称失败，使用默认值',
                'data'    => [
                    'external_userid' => $externalUserId,
                    'error'           => $e->getMessage(),
                ],
            ]);
            return '新客户';
        }
    }
}
