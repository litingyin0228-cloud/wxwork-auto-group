<?php
/**
 * InvoiceJob 队列测试脚本
 * 使用方法: php test_invoice_queue.php
 */

require __DIR__ . '/vendor/autoload.php';

// 引导 ThinkPHP
$app = new think\App();
$app->initialize();

use think\facade\Cache; 
use think\facade\Db;

echo "========== InvoiceJob 队列测试 ==========\n\n";

// 1. 检查 Redis
echo "[1/4] 检查 Redis 连接...\n";
try {
    $redis = Cache::store('redis')->handler();
    $pong = $redis->ping();
    echo "    Redis 状态: OK (PING={$pong})\n";
} catch (Throwable $e) {
    echo "    Redis 状态: FAIL - {$e->getMessage()}\n";
    echo "    → 检查 .env 中 REDIS.HOST/PORT/PASSWORD\n\n";
    exit(1);
}

// 2. 检查队列表
echo "\n[2/4] 检查数据库队列表...\n";
try {
    $prefix = config('database.connections.mysql.prefix', '');
    $jobsTable = $prefix . 'jobs';

    $exists = Db::query("SHOW TABLES LIKE '{$jobsTable}'");
    if (empty($exists)) {
        echo "    表 {$jobsTable}: MISSING\n";
        echo "    → 请执行迁移文件 database/migrations/20260419000002_create_queue_tables.php\n";
    } else {
        $total = Db::table($jobsTable)->count();
        echo "    表 {$jobsTable}: EXISTS ({$total} 条记录)\n";
    }
} catch (Throwable $e) {
    echo "    数据库查询失败: {$e->getMessage()}\n";
}

// 3. 检查 invoice_sessions 表
echo "\n[3/4] 检查 invoice_sessions 表...\n";
try {
    $prefix = config('database.connections.mysql.prefix', '');
    $sessionsTable = $prefix . 'invoice_sessions';

    $exists = Db::query("SHOW TABLES LIKE '{$sessionsTable}'");
    if (empty($exists)) {
        echo "    表 {$sessionsTable}: MISSING\n";
        echo "    → 请执行迁移文件 database/migrations/20260419000001_create_invoice_tables.php\n";
    } else {
        $total = Db::table($sessionsTable)->count();
        echo "    表 {$sessionsTable}: EXISTS ({$total} 条记录)\n";
    }
} catch (Throwable $e) {
    echo "    数据库查询失败: {$e->getMessage()}\n";
}

// 4. 投递测试任务
echo "\n[4/4] 投递 InvoiceJob 测试任务...\n";
$driver = config('queue.default');
echo "    当前队列驱动: {$driver}\n";

try {
    // 创建一个测试用的会话
    $testRoomId = 'test_room_queue_' . time();

    $sessionId = Db::table(config('database.connections.mysql.prefix', '') . 'invoice_sessions')
        ->insertGetId([
            'room_id'        => $testRoomId,
            'user_id'       => 'test_user',
            'user_name'     => '队列测试用户',
            'org_id'        => 'test_org',
            'org_name'      => '队列测试组织',
            'invoice_type'  => \app\model\InvoiceSession::INVOICE_TYPE_ELECTRONIC,
            'amount'        => 100.00,
            'status'        => \app\model\InvoiceSession::STATUS_PROCESSING,
            'step'          => \app\model\InvoiceSession::STEP_CONFIRM,
            'created_at'    => time(),
            'updated_at'    => time(),
        ]);

    echo "    已创建测试会话 ID: {$sessionId}\n";

    // 投递队列任务
    $jobId = \think\facade\Queue::push(\app\job\InvoiceJob::class, [
        'session_id' => $sessionId,
    ], 'invoice');

    if ($jobId === false || $jobId === null) {
        echo "    任务投递: FAIL - Queue::push() 返回 false/null\n";
    } else {
        echo "    任务投递: OK (job_id={$jobId})\n";
    }

    // 立即执行（sync 模式）或等待队列消费
    if ($driver === 'sync') {
        echo "\n    [sync 模式] 任务将在当前进程同步执行...\n";
        echo "    → 请查看上方日志确认执行结果\n";
    } else {
        echo "\n    [{$driver} 模式] 请启动队列消费进程:\n";
        echo "    → php think queue:work --queue=invoice\n";
        echo "    → 或: php think queue:listen --queue=invoice\n";
    }

} catch (Throwable $e) {
    echo "    投递失败: {$e->getMessage()}\n";
    echo "    堆栈: {$e->getTraceAsString()}\n";
}

echo "\n===========================================\n";
