<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\AppInfo;

use OCA\TransferQuotaMonitor\Cron\ActivityDownloadTrackerJob;
use OCA\TransferQuotaMonitor\Cron\MonthlyReset;
use OCA\TransferQuotaMonitor\Cron\UsageTrackingJob;
use OCA\TransferQuotaMonitor\Listener\DownloadListener;
use OCA\TransferQuotaMonitor\Listener\FileOperationListener;
use OCA\TransferQuotaMonitor\Listener\PublicShareDownloadListener;
use OCA\TransferQuotaMonitor\Listener\PublicShareHookListener;
use OCA\TransferQuotaMonitor\Listener\ShareDownloadListener;
use OCA\TransferQuotaMonitor\Middleware\DownloadTrackingMiddleware;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Events\BeforeFileDownloadedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Preview\BeforePreviewFetchedEvent;
use OCP\Share\Events\BeforeShareDownloadedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'transfer_quota_monitor';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

    /**
     * Register services
     *
     * @throws \Exception
     */
    private function registerListeners(): void {
        // PUBLIC SHARE HOOK LISTENER IS REGISTERED VIA CONSTRUCTOR 
        // DO NOT CALL register() METHOD HERE
        // It registers itself with OC_Hook and will fire on all filesystem operations
    }

	public function register(IRegistrationContext $context): void {
        // Register file operation event handlers for uploads
        $context->registerEventListener(NodeCreatedEvent::class, FileOperationListener::class);
        $context->registerEventListener(NodeWrittenEvent::class, FileOperationListener::class);
        
        // Register DIRECT download tracking event listeners (critical for direct download tracking)
        $context->registerEventListener(\OCP\Files\Events\Node\BeforeNodeReadEvent::class, DownloadListener::class);
        $context->registerEventListener(\OCP\Preview\BeforePreviewFetchedEvent::class, DownloadListener::class);
        
        // Register download event handlers
        $context->registerEventListener(BeforeFileDownloadedEvent::class, DownloadListener::class);
        $context->registerEventListener(BeforeFileDownloadedEvent::class, PublicShareDownloadListener::class);
        $context->registerEventListener(BeforeFileDownloadedEvent::class, ShareDownloadListener::class);
        $context->registerEventListener(BeforeShareDownloadedEvent::class, ShareDownloadListener::class);
        
        // Register notification handler
        $context->registerNotifierService(\OCA\TransferQuotaMonitor\Notification\Notifier::class);
        
        // Register middleware (critical for download tracking via stream endpoint)
        $context->registerMiddleware(DownloadTrackingMiddleware::class);
    }

	public function boot(IBootContext $context): void {
        // Register event listeners
        $this->registerListeners();
        
        // Register background jobs
        $context->injectFn(function(IJobList $jobList) {
            $jobList->add(UsageTrackingJob::class);
            $jobList->add(MonthlyReset::class);
            $jobList->add(ActivityDownloadTrackerJob::class);
        });
    }
}
