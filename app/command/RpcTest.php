<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use app\service\JsonRpcClient;

/**
 * RPC 服务测试命令
 *
 * 用法：
 *   php think rpc:test                                      # 运行全部测试
 *   php think rpc:test --method=wxwork.addContactWay       # 测试指定方法
 *   php think rpc:test --service=juhebot                     # 测试指定服务
 *   php think rpc:test --list                                # 仅列出可用方法
 */
class RpcTest extends Command
{
    private JsonRpcClient $client;

    protected function configure()
    {
        $this->setName('rpc:test')
            ->addOption('method', 'm', Option::VALUE_OPTIONAL, '指定测试方法，如 wxwork.createGroupChat')
            ->addOption('service', 's', Option::VALUE_OPTIONAL, '测试指定服务：wxwork | juhebot | autogroup')
            ->addOption('list', 'l', Option::VALUE_NONE, '仅列出所有可用 RPC 方法')
            ->addOption('host', 'H', Option::VALUE_OPTIONAL, '指定 RPC 服务地址（默认 http://127.0.0.1:8090）')
            ->setDescription('RPC 服务测试工具');
    }

    protected function execute(Input $input, Output $output)
    {
        $host = rtrim($input->getOption('host') ?: 'http://127.0.0.1:8090', '/');
        $list = $input->getOption('list');
        $method = $input->getOption('method');
        $service = $input->getOption('service');

        $output->writeln("<info>RPC 测试工具</info>");
        $output->writeln("<comment>服务端: {$host}</comment>");
        $output->writeln('');

        try {
            $this->client = new JsonRpcClient(['host' => $host]);

            if ($list) {
                $this->listMethods($output);
                return;
            }

            if ($method) {
                $this->testMethod($output, $method);
                return;
            }

            if ($service) {
                $this->testService($output, $service);
                return;
            }

            $this->runAllTests($output);

        } catch (\Throwable $e) {
            $output->writeln("<error>连接失败: {$e->getMessage()}</error>");
            $output->writeln('');
            $output->writeln("<comment>请确认 RPC 服务已启动：</comment>");
            $output->writeln("<comment>  php -S 127.0.0.1:8090 -t public public/rpc_server.php</comment>");
        }
    }

    /**
     * 列出所有可用方法
     */
    private function listMethods(Output $output)
    {
        $output->writeln("<info>获取可用方法列表...</info>");

        $methods = $this->client->listMethods();

        if (empty($methods)) {
            $output->writeln("<error>  未获取到方法列表（服务端可能返回了空数据）</error>");
            return;
        }

        $total = is_array($methods) ? count($methods) : 0;
        $output->writeln("<info>共 {$total} 个方法：</info>");
        $output->writeln('');

        // 按服务名分组
        $grouped = [];
        foreach ($methods as $item) {
            $svc = $item['service'] ?? 'unknown';
            if (!isset($grouped[$svc])) {
                $grouped[$svc] = [];
            }
            $grouped[$svc][] = $item;
        }

        foreach ($grouped as $svc => $items) {
            $output->writeln("<comment>■ {$svc}</comment>");
            foreach ($items as $item) {
                $output->writeln("  <info>{$item['method']}</info>");
            }
            $output->writeln('');
        }
    }

    /**
     * 测试指定方法
     */
    private function testMethod(Output $output, string $method)
    {
        $output->writeln("<info>测试方法: {$method}</info>");

        $params = $this->getTestParams($method);

        if (empty($params)) {
            $output->writeln("<comment>  无参调用...</comment>");
            $result = $this->client->call($method);
        } else {
            $output->writeln("<comment>  参数: " . json_encode($params, JSON_UNESCAPED_UNICODE) . "</comment>");
            $result = $this->client->call($method, $params);
        }

        $this->printResult($output, $result);
    }

    /**
     * 测试指定服务
     */
    private function testService(Output $output, string $service)
    {
        $testMap = [
            'juhebot'  => [
                ['method' => 'juhebot.getChatList',   'params' => ['page' => 1, 'page_size' => 3]],
                ['method' => 'juhebot.getRoomList',   'params' => ['start_index' => 0, 'limit' => 3]],
                ['method' => 'juhebot.syncContact',   'params' => ['seq' => '', 'limit' => 3]],
            ],
            'wxwork'   => [
                ['method' => 'wxwork.getAccessToken', 'params' => []],
            ],
            'autogroup' => [
                ['method' => 'autogroup.handleNewCustomer', 'params' => [
                    'event' => [
                        'UserID'         => '',
                        'ExternalUserID' => '',
                    ],
                ]],
            ],
        ];

        if (!isset($testMap[$service])) {
            $available = implode(', ', array_keys($testMap));
            $output->writeln("<error>未知服务: {$service}，可用: {$available}</error>");
            return;
        }

        $output->writeln("<info>测试服务: {$service}</info>");
        $output->writeln('');

        foreach ($testMap[$service] as $item) {
            $this->testMethod($output, $item['method']);
            $output->writeln('');
        }
    }

    /**
     * 运行全部测试（默认入口）
     */
    private function runAllTests(Output $output)
    {
        // 1. 列出所有方法
        $output->writeln("<info>① 获取可用方法...</info>");
        $this->listMethods($output);

        // 2. 批量调用测试
        $this->batchTest($output);
    }

    /**
     * 批量调用测试
     */
    private function batchTest(Output $output)
    {
        $output->writeln("<info>② 批量调用测试...</info>");

        $calls = [
            // 位置参数：数组格式 [arg1, arg2]
            ['method' => 'juhebot.getChatList',  'params' => [1, 3]],
            ['method' => 'juhebot.getRoomList',  'params' => [0, 2]],
        ];

        try {
            $results = $this->client->batch($calls);
            foreach ($results as $i => $r) {
                $method = $calls[$i]['method'];
                if (($r['success'] ?? false)) {
                    $output->writeln("<info>  ✓ {$method}</info>");
                } else {
                    $errMsg = $r['error']['message'] ?? '未知错误';
                    $output->writeln("<error>  ✗ {$method}: {$errMsg}</error>");
                }
            }
        } catch (\Throwable $e) {
            $output->writeln("<error>  批量调用失败: {$e->getMessage()}</error>");
        }
    }

    /**
     * 根据方法名生成测试参数（命名参数格式）
     */
    private function getTestParams(string $method): array
    {
        $paramsMap = [
            // Juhebot
            'juhebot.getChatList'        => ['page' => 1, 'page_size' => 3],
            'juhebot.getRoomList'        => ['start_index' => 0, 'limit' => 5],
            'juhebot.syncContact'         => ['seq' => '', 'limit' => 5],
            'juhebot.syncApplyContact'    => ['seq' => '', 'limit' => 5],
            'juhebot.batchGetRoomDetail' => ['room_list' => []],
            'juhebot.sendText'            => [
                'conversation_id' => 'S:test_user',
                'content'         => 'RPC测试 ' . date('Y-m-d H:i:s'),
            ],
            // WxWork
            'wxwork.createGroupChat'      => [
                'chatName' => 'RPC测试群-' . date('His'),
                'owner'    => 'LiTingYin',
                'userList' => [],
            ],
            // AutoGroup
            'autogroup.handleNewCustomer' => [
                'event' => ['UserID' => '', 'ExternalUserID' => ''],
            ],
        ];

        return $paramsMap[$method] ?? [];
    }

    /**
     * 格式化输出结果
     */
    private function printResult(Output $output, mixed $result)
    {
        if ($result === null) {
            $output->writeln("    <comment>(null)</comment>");
            return;
        }
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        foreach (explode("\n", $json) as $line) {
            $output->writeln("    {$line}");
        }
    }
}
