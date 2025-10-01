<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Cron;

use OCA\TransferQuotaMonitor\Service\TransferQuotaService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Reset all user transfer quotas on the first day of each month
 */
class MonthlyReset extends TimedJob {
    /** @var TransferQuotaService */
    private $quotaService;
    
    /** @var LoggerInterface */
    private $logger;

    /**
     * @param TransferQuotaService $quotaService
     * @param LoggerInterface $logger
     */
    public function __construct(TransferQuotaService $quotaService = null, LoggerInterface $logger = null) {
        // We need a time factory to pass to the parent constructor
        $timeFactory = Server::get(ITimeFactory::class);
        parent::__construct($timeFactory);
        
        // Run once per day at midnight
        $this->setInterval(24 * 60 * 60);
        
        // Store service reference or get from server if not provided
        if ($quotaService === null) {
            $this->quotaService = Server::get(TransferQuotaService::class);
        } else {
            $this->quotaService = $quotaService;
        }
        
        if ($logger === null) {
            $this->logger = Server::get(LoggerInterface::class);
        } else {
            $this->logger = $logger;
        }
    }

    /**
     * Execute the job, run once per day but only actually reset on the 1st day of the month
     *
     * @param array $argument
     */
    protected function run($argument) {
        // Check if it's the first day of the month
        $currentDay = (int)date('j');
        
        if ($currentDay === 1) {
            try {
                if ($this->logger) {
                    $this->logger->info('Running monthly transfer quota reset');
                }
                
                if ($this->quotaService) {
                    $success = $this->quotaService->resetAllUsage();
                    
                    if ($this->logger) {
                        $this->logger->info('Monthly transfer quota reset ' . ($success ? 'completed successfully' : 'failed'));
                    }
                }
            } catch (\Exception $e) {
                if ($this->logger) {
                    $this->logger->error('Error during monthly transfer quota reset: ' . $e->getMessage(), [
                        'app' => 'transfer_quota_monitor',
                        'exception' => $e
                    ]);
                }
            }
        }
    }
}
