<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Migration;

use OCP\Migration\IRepairStep;
use OCP\Migration\IOutput;
use OCP\IConfig;

class InstallStep implements IRepairStep {
    private $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    public function getName(): string {
        return 'Install transfer quota monitor app';
    }

    public function run(IOutput $output): void {
        // Set default warning thresholds
        $this->config->setAppValue('transfer_quota_monitor', 'warning_threshold', '80');
        $this->config->setAppValue('transfer_quota_monitor', 'critical_threshold', '90');
        
        $output->info('Transfer quota monitor installed successfully');
    }
}
