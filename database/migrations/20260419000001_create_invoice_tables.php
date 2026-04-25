<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateInvoiceTables extends Migrator
{
    public function up()
    {
        // ─── 开票会话表 ──────────────────────────────────────────────
        $table = $this->table('invoice_session', [
            'engine'    => 'InnoDB',
            'encoding'  => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'comment'   => '开票会话表',
        ]);

        $table->addColumn('room_id',       'string',   ['limit' => 64,  'null' => false, 'comment' => '群ID'])
              ->addColumn('user_id',       'string',   ['limit' => 64,  'null' => false, 'comment' => '用户ID'])
              ->addColumn('org_id',        'string',   ['limit' => 64,  'null' => true,   'comment' => '企业ID（来自 bind_org_id）'])
              ->addColumn('org_name',      'string',   ['limit' => 255, 'null' => true,   'comment' => '企业名称'])
              ->addColumn('user_mobile',   'string',   ['limit' => 20,  'null' => true,   'comment' => '用户手机号'])
              ->addColumn('invoice_type',  'string',   ['limit' => 16,  'null' => true,   'comment' => '发票类型（电子/纸质）'])
              ->addColumn('tax_rate',      'decimal',  ['precision' => 5, 'scale' => 2, 'null' => true, 'comment' => '税率'])
              ->addColumn('amount',        'decimal',  ['precision' => 12, 'scale' => 2, 'null' => true, 'comment' => '开票金额'])
              ->addColumn('status',        'integer',  ['limit' => 8,  'null' => false, 'default' => 0, 'comment' => '会话状态：0=待确认类型 1=待输入金额 2=处理中 3=已完成 4=已取消 99=异常'])
              ->addColumn('step_data',     'json',     ['null' => true, 'comment' => '各步骤收集的字段（JSON）'])
              ->addColumn('invoice_file',   'string',   ['limit' => 512,'null' => true,   'comment' => '发票文件路径/URL'])
              ->addColumn('error_msg',     'string',   ['limit' => 512,'null' => true,   'comment' => '错误信息'])
              ->addColumn('step',          'integer',  ['limit' => 8,  'null' => false, 'default' => 1, 'comment' => '当前步骤：1=选择发票类型 2=确认金额 3=开票 4=发送文件'])
              ->addColumn('latest_msg_id', 'string',   ['limit' => 128,'null' => true,   'comment' => '最近处理的 msg_id（防重）'])
              ->addColumn('expires_at',    'datetime', ['null' => true, 'comment' => '会话过期时间'])
              ->addColumn('completed_at',  'datetime', ['null' => true, 'comment' => '完成时间'])
              ->addColumn('created_at',    'datetime', ['null' => false, 'comment' => '创建时间'])
              ->addColumn('updated_at',    'datetime', ['null' => false, 'comment' => '更新时间'])
              ->addIndex('room_id',        ['name' => 'idx_room_id'])
              ->addIndex('user_id',        ['name' => 'idx_user_id'])
              ->addIndex('status',         ['name' => 'idx_status'])
              ->addIndex(['room_id', 'status'], ['name' => 'idx_room_status'])
              ->create();

        // ─── 开票会话消息记录表 ──────────────────────────────────────
        $msgTable = $this->table('invoice_message', [
            'engine'    => 'InnoDB',
            'encoding'  => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'comment'   => '开票会话消息记录表',
        ]);

        $msgTable->addColumn('session_id',   'integer', ['null' => false, 'comment' => '所属会话ID'])
                 ->addColumn('msg_id',        'string',  ['limit' => 128,'null' => true,   'comment' => '消息ID（防重）'])
                 ->addColumn('sender',        'string',  ['limit' => 64, 'null' => true,  'comment' => '发送者ID'])
                 ->addColumn('sender_name',   'string',  ['limit' => 128,'null' => true,  'comment' => '发送者名称'])
                 ->addColumn('content',      'text',    ['null' => true,  'comment' => '消息内容'])
                 ->addColumn('msg_type',      'integer', ['limit' => 8, 'null' => false, 'default' => 1, 'comment' => '消息类型：1=用户发送 2=机器人发送'])
                 ->addColumn('action',        'string',  ['limit' => 32, 'null' => true,  'comment' => '解析出的动作（confirm/cancel/...）'])
                 ->addColumn('created_at',    'datetime',['null' => false, 'comment' => '创建时间'])
                 ->addIndex('session_id',  ['name' => 'idx_session_id'])
                 ->addIndex('msg_id',     ['name' => 'idx_msg_id'])
                 ->create();
    }

    public function down()
    {
        $this->dropTable('invoice_message');
        $this->dropTable('invoice_session');
    }
}
