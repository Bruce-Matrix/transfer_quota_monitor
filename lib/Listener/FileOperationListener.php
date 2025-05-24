<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Listener;

use OCA\TransferQuotaMonitor\Service\TransferQuotaService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDownloadedEvent;
use Psr\Log\LoggerInterface;

class FileOperationListener implements IEventListener {
    /** @var TransferQuotaService */
    private $quotaService;
    
    /** @var LoggerInterface */
    private $logger;
    
    // To prevent duplicate notifications from the same file operation
    private static $processedNodes = [];
    
    public function __construct(TransferQuotaService $quotaService, LoggerInterface $logger) {
        $this->quotaService = $quotaService;
        $this->logger = $logger;
    }
    
    public function handle(Event $event): void {
        if ($event instanceof NodeDownloadedEvent) {
            // Track download
            $node = $event->getNode();
            $owner = $node->getOwner();
            if ($owner) {
                $fileId = $node->getId();
                $userId = $owner->getUID();
                
                // Skip if we've already processed this node recently
                $key = $fileId . '-' . $userId;
                if (isset(self::$processedNodes[$key])) {
                    return;
                }
                
                // Mark as processed to prevent duplicates
                self::$processedNodes[$key] = true;
                
                // Track the download
                $size = $node->getSize();
                $this->logger->debug('Download tracked: ' . $size . ' bytes for user ' . $userId);
                $this->quotaService->addUserTransfer($userId, $size);
            }
        } elseif ($event instanceof NodeCreatedEvent) {
            // Track upload
            $node = $event->getNode();
            $owner = $node->getOwner();
            if ($owner) {
                $fileId = $node->getId();
                $userId = $owner->getUID();
                
                // Skip if we've already processed this node recently
                $key = $fileId . '-' . $userId;
                if (isset(self::$processedNodes[$key])) {
                    return;
                }
                
                // Mark as processed to prevent duplicates
                self::$processedNodes[$key] = true;
                
                // Track the upload
                $size = $node->getSize();
                $this->logger->debug('Upload tracked: ' . $size . ' bytes for user ' . $userId);
                $this->quotaService->addUserTransfer($userId, $size);
            }
        }
        
        // Limit the size of the processed nodes cache
        if (count(self::$processedNodes) > 100) {
            // Only keep the most recent 50 entries
            self::$processedNodes = array_slice(self::$processedNodes, -50, null, true);
        }
    }
}
