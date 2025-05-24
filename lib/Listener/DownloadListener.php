<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Listener;

use OCA\TransferQuotaMonitor\Service\TransferQuotaService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeReadEvent;
use OCP\Files\File;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Preview\BeforePreviewFetchedEvent;
use Psr\Log\LoggerInterface;

/**
 * Event listener to track file downloads for transfer quota monitoring
 *
 * @template-implements IEventListener<Event|BeforeNodeReadEvent|BeforePreviewFetchedEvent>
 */
class DownloadListener implements IEventListener {
    /** @var IUserSession */
    protected $userSession;

    /** @var IDBConnection */
    protected $connection;
    
    /** @var TransferQuotaService */
    protected $quotaService;
    
    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param IUserSession $userSession
     * @param IDBConnection $connection
     * @param TransferQuotaService $quotaService
     * @param LoggerInterface $logger
     */
    public function __construct(
        IUserSession $userSession,
        IDBConnection $connection,
        TransferQuotaService $quotaService,
        LoggerInterface $logger
    ) {
        $this->userSession = $userSession;
        $this->connection = $connection;
        $this->quotaService = $quotaService;
        $this->logger = $logger;
    }

    /**
     * Event handler
     * 
     * @param Event $event
     */
    public function handle(Event $event): void {
        // Track file downloads
        if ($event instanceof BeforeNodeReadEvent) {
            if ($event->getNode() instanceof File) {
                $this->processFileDownload($event->getNode());
            }
        }

        // Also track large preview generations as downloads
        if ($event instanceof BeforePreviewFetchedEvent && ($event->getHeight() > 256 || $event->getWidth() > 256)) {
            $this->logDownload();
        }
    }

    /**
     * Process a file download and account for the download size
     *
     * @param File $file The downloaded file
     */
    protected function processFileDownload(File $file): void {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return; // Skip if no user is logged in
            }
            
            $userId = $user->getUID();
            $fileSize = $file->getSize();
            $filePath = $file->getPath();
            
            // Log the download event
            $this->logger->info('File download detected: ' . $filePath . ' (' . $fileSize . ' bytes) by user ' . $userId, [
                'app' => 'transfer_quota_monitor'
            ]);
            
            // Add the file size to the user's transfer quota
            $this->quotaService->addUserTransfer($userId, $fileSize);
            
            // Also increment our internal counter
            $this->logDownload();
            
        } catch (\Exception $e) {
            $this->logger->error('Error processing file download: ' . $e->getMessage(), [
                'app' => 'transfer_quota_monitor',
                'exception' => $e
            ]);
        }
    }
    
    /**
     * Log a download in our own counter
     */
    protected function logDownload(): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            return; // Skip if no user is logged in
        }
        
        try {
            $userId = $user->getUID();
            
            // First try to update existing record
            $query = $this->connection->getQueryBuilder();
            
            // First get the current value to properly handle the type conversion
            $selectQuery = $this->connection->getQueryBuilder();
            $selectQuery->select('configvalue')
                ->from('preferences')
                ->where($selectQuery->expr()->eq('userid', $selectQuery->createParameter('user')))
                ->andWhere($selectQuery->expr()->eq('configkey', $selectQuery->createParameter('action')))
                ->andWhere($selectQuery->expr()->eq('appid', $selectQuery->createParameter('appid')))
                ->setParameter('user', $userId)
                ->setParameter('action', 'read')
                ->setParameter('appid', 'transfer_quota_monitor');
                
            $result = $selectQuery->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            
            if ($row) {
                // Calculate new value and update
                $newValue = (int)$row['configvalue'] + 1;
                
                $query = $this->connection->getQueryBuilder();
                $query->update('preferences')
                    ->set('configvalue', $query->createNamedParameter((string)$newValue))
                    ->where($query->expr()->eq('userid', $query->createParameter('user')))
                    ->andWhere($query->expr()->eq('configkey', $query->createParameter('action')))
                    ->andWhere($query->expr()->eq('appid', $query->createParameter('appid')))
                    ->setParameter('user', $userId)
                    ->setParameter('action', 'read')
                    ->setParameter('appid', 'transfer_quota_monitor');
                
                $query->executeStatement();
            } else {
                // Insert new entry
                $query = $this->connection->getQueryBuilder();
                $query->insert('preferences')
                    ->values([
                        'userid' => $query->createParameter('user'),
                        'appid' => $query->createParameter('appid'),
                        'configkey' => $query->createParameter('action'),
                        'configvalue' => $query->createParameter('configvalue'),
                    ])
                    ->setParameter('user', $userId)
                    ->setParameter('appid', 'transfer_quota_monitor')
                    ->setParameter('action', 'read')
                    ->setParameter('configvalue', '1');
                
                $query->executeStatement();
            }
            
        } catch (\Exception $e) {
            // Log the error but don't fail
            $this->logger->error('Error logging download in preferences: ' . $e->getMessage(), [
                'app' => 'transfer_quota_monitor',
                'exception' => $e
            ]);
        }
    }
}
