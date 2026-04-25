<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateQueueTables extends Migrator
{
    public function up()
    {
        // ─── 队列任务表 ─────────────────────────────────────────────
        $table = $this->table('jobs', [
            'engine'    => 'InnoDB',
            'encoding'  => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'comment'   => '队列任务表',
        ]);

        $table->addColumn('queue',      'string', ['limit' => 64, 'null' => false, 'default' => 'default', 'comment' => '队列名'])
              ->addColumn('payload',   'longtext', ['null' => false, 'comment' => '任务数据'])
              ->addColumn('attempts',  'integer', ['limit' => 8,  'null' => false, 'default' => 0,  'comment' => '尝试次数'])
              ->addColumn('reserved',  'integer', ['limit' => 8,  'null' => false, 'default' => 0,  'comment' => '是否占用'])
              ->addColumn('reserved_at','integer', ['limit' => 10, 'null' => false, 'default' => 0,  'comment' => '占用时间戳'])
              ->addColumn('available_at','integer',['limit' => 10, 'null' => false, 'default' => 0,  'comment' => '可执行时间戳'])
              ->addColumn('created_at', 'integer', ['limit' => 10, 'null' => false, 'default' => 0,  'comment' => '创建时间戳'])
              ->addIndex('queue', ['name' => 'idx_queue'])
              ->addIndex(['queue', 'reserved'], ['name' => 'idx_queue_reserved'])
              ->create();

        // ─── 失败任务表 ────────────────────────────────────────────
        $failedTable = $this->table('failed_jobs', [
            'engine'    => 'InnoDB',
            'encoding'  => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'comment'   => '失败任务表',
        ]);

        $failedTable->addColumn('connection', 'string', ['limit' => 64, 'null' => false, 'comment' => '连接类型'])
                    ->addColumn('queue',      'string', ['limit' => 64, 'null' => false, 'comment' => '队列名'])
                    ->addColumn('payload',   'longtext', ['null' => false, 'comment' => '任务数据'])
                    ->addColumn('exception',  'text',   ['null' => false, 'comment' => '异常信息'])
                    ->addColumn('failed_at',  'datetime', ['null' => false, 'comment' => '失败时间'])
                    ->create();
    }

    public function down()
    {
        $this->dropTable('failed_jobs');
        $this->dropTable('jobs');
    }
}
