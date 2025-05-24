<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20250429 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns \OCP\DB\ISchemaWrapper
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('transfer_quota_limits')) {
            $table = $schema->createTable('transfer_quota_limits');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('monthly_limit', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('current_usage', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('last_reset', 'datetime', [
                'notnull' => true,
            ]);
            $table->addColumn('warning_sent', 'smallint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('critical_warning_sent', 'smallint', [
                'notnull' => true,
                'default' => 0,
            ]);
            
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id'], 'transfer_quota_user_idx');
        }

        return $schema;
    }
}
