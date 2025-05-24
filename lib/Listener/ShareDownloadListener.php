<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Listener;

use OCA\TransferQuotaMonitor\Service\TransferQuotaService;
use OCP\AppFramework\Http\Response;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\BeforeFileDownloadedEvent;
use OCP\Share\Events\BeforeShareDownloadedEvent;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Listener for share downloads and direct file downloads
 * 
 * This listener tracks downloads for all registered users, including guest accounts,
 * by listening to BeforeFileDownloadedEvent and BeforeShareDownloadedEvent events.
 * It adds the downloaded file size to the user's transfer quota.
 */
class ShareDownloadListener implements IEventListener {
    /** @var IUserSession */
    private $userSession;
    
    /** @var TransferQuotaService */
    private $quotaService;
    
    /** @var IRootFolder */
    private $rootFolder;
    
    /** @var LoggerInterface */
    private $logger;
    
    public function __construct(
        IUserSession $userSession,
        TransferQuotaService $quotaService,
        IRootFolder $rootFolder,
        LoggerInterface $logger
    ) {
        $this->userSession = $userSession;
        $this->quotaService = $quotaService;
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
    }
    
    public function handle(Event $event): void {
        $this->logger->debug('ShareDownloadListener received event: ' . get_class($event), [
            'app' => 'transfer_quota_monitor'
        ]);
        
        // Handle direct file downloads
        if ($event instanceof BeforeFileDownloadedEvent) {
            try {
                $file = $event->getFile();
                $owner = $file->getOwner();
                
                if (!$owner) {
                    $this->logger->debug('No owner found for downloaded file', [
                        'app' => 'transfer_quota_monitor'
                    ]);
                    return;
                }
                
                $size = $file->getSize();
                $userId = $owner->getUID();
                
                $this->logger->info('Direct download tracked: ' . $size . ' bytes for user ' . $userId, [
                    'app' => 'transfer_quota_monitor',
                    'userId' => $userId,
                    'fileSize' => $size
                ]);
                $this->quotaService->addUserTransfer($userId, $size);
                
            } catch (\Exception $e) {
                $this->logger->error('Error tracking file download: ' . $e->getMessage(), [
                    'app' => 'transfer_quota_monitor',
                    'exception' => $e
                ]);
            }
        }
        
        // Handle public share downloads
        if ($event instanceof BeforeShareDownloadedEvent) {
            try {
                $share = $event->getShare();
                $node = $share->getNode();
                
                if ($node->isDirectory()) {
                    $this->logger->debug('Shared directory download not tracked', [
                        'app' => 'transfer_quota_monitor'
                    ]);
                    return;
                }
                
                $owner = $node->getOwner();
                if (!$owner) {
                    $this->logger->debug('No owner found for shared file', [
                        'app' => 'transfer_quota_monitor'
                    ]);
                    return;
                }
                
                $size = $node->getSize();
                $userId = $owner->getUID();
                
                $this->logger->info('Share download tracked: ' . $size . ' bytes for user ' . $userId, [
                    'app' => 'transfer_quota_monitor',
                    'userId' => $userId,
                    'fileSize' => $size
                ]);
                $this->quotaService->addUserTransfer($userId, $size);
                
            } catch (\Exception $e) {
                $this->logger->error('Error tracking share download: ' . $e->getMessage(), [
                    'app' => 'transfer_quota_monitor',
                    'exception' => $e
                ]);
            }
        }
    }
}
