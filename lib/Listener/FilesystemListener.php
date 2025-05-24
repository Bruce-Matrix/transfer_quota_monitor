<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Listener;

use OC\Files\Filesystem;
use OCA\TransferQuotaMonitor\Service\TransferQuotaService;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Listener that tracks file reads (downloads) via filesystem hooks
 */
class FilesystemListener {
    /** @var IUserSession */
    protected $userSession;
    
    /** @var TransferQuotaService */
    protected $quotaService;
    
    /** @var IRootFolder */
    protected $rootFolder;
    
    /** @var IRequest */
    protected $request;
    
    /** @var LoggerInterface */
    protected $logger;
    
    /** @var array */
    protected $processedPaths = [];
    
    /**
     * @param IUserSession $userSession
     * @param TransferQuotaService $quotaService
     * @param IRootFolder $rootFolder
     * @param IRequest $request
     * @param LoggerInterface $logger
     */
    public function __construct(
        IUserSession $userSession,
        TransferQuotaService $quotaService,
        IRootFolder $rootFolder,
        IRequest $request,
        LoggerInterface $logger
    ) {
        $this->userSession = $userSession;
        $this->quotaService = $quotaService;
        $this->rootFolder = $rootFolder;
        $this->request = $request;
        $this->logger = $logger;
    }
    
    /**
     * Handler for the read hook from OC_Filesystem
     * This is called when any file is read, including during downloads
     *
     * @param array $params Parameters from the hook
     */
    public function readFile(array $params): void {
        try {
            // Get the path that was read
            $path = $params['path'] ?? '';
            if (empty($path)) {
                return;
            }
            
            // Skip part files and thumbnails
            if (substr($path, -5) === '.part' || strpos($path, 'thumbnails') !== false) {
                return;
            }
            
            // Generate a cache key to prevent duplicate counting
            $user = $this->userSession->getUser();
            if (!$user) {
                $this->logger->debug('No user found for filesystem read: ' . $path);
                return;
            }
            
            $userId = $user->getUID();
            $cacheKey = md5($userId . ':' . $path . ':' . time());
            
            if (isset($this->processedPaths[$cacheKey])) {
                return;
            }
            
            $this->processedPaths[$cacheKey] = true;

            // Check if this is a likely download by looking at the request URI
            $uri = $this->request->getRequestUri();
            $isDownload = false;
            
            // Common download patterns
            $downloadPatterns = [
                '/apps/files/ajax/download.php',
                '/remote.php/dav/files/',
                '/s/',
                '/public.php'
            ];
            
            foreach ($downloadPatterns as $pattern) {
                if (strpos($uri, $pattern) !== false) {
                    $isDownload = true;
                    break;
                }
            }
            
            // Skip if not likely to be a download
            if (!$isDownload) {
                return;
            }
            
            // Get file details to track the download
            list($filePath, $owner, $fileId, $size) = $this->getFileDetails($path);
            
            if ($owner && $size > 0) {
                $this->logger->info(
                    'Download detected via filesystem read hook: ' . $filePath . 
                    ' (' . $size . ' bytes) by ' . $userId . ' owned by ' . $owner, 
                    ['app' => 'transfer_quota_monitor']
                );
                
                // Add the transfer to the quota tracking
                $this->quotaService->addUserTransfer($owner, $size);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error in filesystem read hook: ' . $e->getMessage(), [
                'app' => 'transfer_quota_monitor',
                'exception' => $e
            ]);
        }
    }
    
    /**
     * Gets file details for the specified path
     *
     * @param string $path File path relative to user's files directory
     * @return array File details: [path, owner UID, file ID, size]
     * @throws NotFoundException If file not found
     * @throws InvalidPathException If path is invalid
     */
    protected function getFileDetails(string $path): array {
        // Get current user
        $currentUserId = $this->userSession->getUser()->getUID();
        $userFolder = $this->rootFolder->getUserFolder($currentUserId);
        
        try {
            $node = $userFolder->get($path);
            $owner = $node->getOwner()->getUID();
            
            // Handle shared files
            if ($owner !== $currentUserId) {
                $storage = $node->getStorage();
                if (!$storage->instanceOfStorage('OCA\Files_Sharing\External\Storage')) {
                    Filesystem::initMountPoints($owner);
                } else {
                    // External share, use current user as owner
                    $owner = $currentUserId;
                }
                
                // Get the file from owner's perspective to get accurate path
                $ownerFolder = $this->rootFolder->getUserFolder($owner);
                $nodes = $ownerFolder->getById($node->getId());
                
                if (empty($nodes)) {
                    throw new NotFoundException($node->getPath());
                }
                
                $node = $nodes[0];
                $path = substr($node->getPath(), strlen($ownerFolder->getPath()));
            }
            
            $size = ($node instanceof Folder) ? 0 : $node->getSize();
            
            return [
                $path,
                $owner,
                $node->getId(),
                $size
            ];
        } catch (NotFoundException | InvalidPathException $e) {
            $this->logger->debug('File not found or invalid path: ' . $path . ', ' . $e->getMessage());
            throw $e;
        }
    }
}
