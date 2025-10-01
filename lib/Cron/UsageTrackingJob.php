<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Cron;

use OCA\TransferQuotaMonitor\Listener\UsageListener;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IContainer;
use OCP\Server;

class UsageTrackingJob extends TimedJob {
    /** @var UsageListener */
    protected $usageListener;

    public function __construct(?ITimeFactory $time = null, ?UsageListener $usageListener = null) {
        // When called from background job system, instantiate dependencies
        if ($time === null) {
            $time = Server::get(ITimeFactory::class);
        }

        parent::__construct($time);

        // When called from background job system, get UsageListener using the server container
        if ($usageListener === null) {
            $this->usageListener = Server::get(UsageListener::class);
        } else {
            $this->usageListener = $usageListener;
        }

        // Run every 5 minutes
        $this->setInterval(5 * 60);
    }

    protected function run($argument): void {
        try {
            if ($this->usageListener) {
                $this->usageListener->processFileOperations();
            }
        } catch (\Exception $e) {
            // Log error but don't fail the job
            $logger = Server::get(\Psr\Log\LoggerInterface::class);
            $logger->error('Error in UsageTrackingJob: ' . $e->getMessage(), [
                'app' => 'transfer_quota_monitor',
                'exception' => $e
            ]);
        }
    }
}
