<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Migration;

use OCP\Migration\IRepairStep;
use OCP\Migration\IOutput;
use OCP\IDBConnection;

class UninstallStep implements IRepairStep {
    private $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    public function getName(): string {
        return 'Uninstall transfer quota monitor app';
    }

    public function run(IOutput $output): void {
        // Remove tables
        if ($this->connection->tableExists('transfer_quota_limits')) {
            $this->connection->dropTable('transfer_quota_limits');
        }
        
        $output->info('Transfer quota monitor uninstalled successfully');
    }
}
