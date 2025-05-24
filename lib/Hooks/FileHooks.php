<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Hooks;

use OC\Files\Filesystem;
use OC_Hook;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCA\TransferQuotaMonitor\Service\TransferQuotaService;
use OCP\ILogger;

class FileHooks {
    /** @var TransferQuotaService */
    private $quotaService;
    
    /** @var IUserSession */
    private $userSession;
    
    /** @var IRootFolder */
    private $rootFolder;
    
    /** @var ILogger */
    private $logger;
    
    /** @var array */
    private $processedNodes = [];
    
    /**
     * @param TransferQuotaService $quotaService
     * @param IUserSession $userSession
     * @param IRootFolder $rootFolder
     * @param ILogger $logger
     */
    public function __construct(
        TransferQuotaService $quotaService,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        ILogger $logger
    ) {
        $this->quotaService = $quotaService;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
    }
    
    /**
     * Register the hooks
     */
    public function register() {
        // Register hooks for file operations
        OC_Hook::connect(\OC\Files\Filesystem::CLASSNAME, \OC\Files\Filesystem::signal_read, $this, 'fileRead');
        OC_Hook::connect(\OC\Files\Filesystem::CLASSNAME, \OC\Files\Filesystem::signal_download, $this, 'fileDownloaded');
        OC_Hook::connect('OC_Webdav', 'download', $this, 'fileWebdavDownloaded');
        OC_Hook::connect('OCA\\Files_Sharing\\Controllers\\ShareController', 'file_download_post', $this, 'fileSharedDownloaded');
        OC_Hook::connect('OCA\\Files_Sharing\\Controller\\ShareController', 'file_download_post', $this, 'fileSharedDownloaded');
        OC_Hook::connect('OCA\\Files_Sharing\\Controller\\PublicPreviewController', 'download', $this, 'fileSharedDownloaded');
        OC_Hook::connect('OCA\\Files\\Controller\\ViewController', 'download', $this, 'fileViewerDownloaded');
        OC_Hook::connect('OCA\\DAV\\Connector\\Sabre\\File', 'get', $this, 'fileWebdavGet');
        OC_Hook::connect('OCA\\DAV\\Connector\\Sabre\\File', 'get_file', $this, 'fileWebdavGetFile');
        
        // Log that we registered the hooks
        $this->logger->debug('TransferQuotaMonitor FileHooks registered', ['app' => 'transfer_quota_monitor']);
    }
    
    /**
     * Hook handler for file read operations
     * 
     * @param array $parameters The hook parameters
     */
    public function fileRead(array $parameters) {
        $this->logger->debug('File read hook triggered', ['app' => 'transfer_quota_monitor', 'parameters' => json_encode($parameters)]);
        $this->trackFileAccess($parameters);
    }
    
    /**
     * Hook handler for file download operations
     * 
     * @param array $parameters The hook parameters
     */
    public function fileDownloaded(array $parameters) {
        $this->logger->debug('File download hook triggered', ['app' => 'transfer_quota_monitor', 'parameters' => json_encode($parameters)]);
        $this->trackFileAccess($parameters);
    }
    
    /**
     * Hook handler for WebDAV file downloads
     * 
     * @param array $parameters The hook parameters
     */
    public function fileWebdavDownloaded(array $parameters) {
        $this->logger->debug('WebDAV download hook triggered', ['app' => 'transfer_quota_monitor', 'parameters' => json_encode($parameters)]);
        $this->trackFileAccess($parameters);
    }
    
    /**
     * Hook handler for WebDAV GET operations
     * 
     * @param array $parameters The hook parameters
     */
    public function fileWebdavGet(array $parameters) {
        $this->logger->debug('WebDAV GET hook triggered', ['app' => 'transfer_quota_monitor', 'parameters' => json_encode($parameters)]);
        $this->trackFileAccess($parameters);
    }
    
    /**
     * Hook handler for WebDAV get_file operations 
     * 
     * @param array $parameters The hook parameters
     */
    public function fileWebdavGetFile(array $parameters) {
        $this->logger->debug('WebDAV get_file hook triggered', ['app' => 'transfer_quota_monitor', 'parameters' => json_encode($parameters)]);
        $this->trackFileAccess($parameters);
    }
    
    /**
     * Hook handler for shared file download operations
     * 
     * @param array $parameters The hook parameters
     */
    public function fileSharedDownloaded(array $parameters) {
        $this->logger->debug('Shared file download hook triggered', ['app' => 'transfer_quota_monitor', 'parameters' => json_encode($parameters)]);
        $this->trackFileAccess($parameters);
    }
    
    /**
     * Hook handler for file viewer downloads
     * 
     * @param array $parameters The hook parameters
     */
    public function fileViewerDownloaded(array $parameters) {
        $this->logger->debug('File viewer download hook triggered', ['app' => 'transfer_quota_monitor', 'parameters' => json_encode($parameters)]);
        $this->trackFileAccess($parameters);
    }
    
    /**
     * Track file access and update quota
     * 
     * @param array $parameters The hook parameters
     */
    protected function trackFileAccess(array $parameters) {
        try {
            // Check if we have a path
            if (!isset($parameters['path'])) {
                return;
            }
            
            $path = $parameters['path'];
            
            // Get the node ID to avoid duplicates
            $nodeId = $this->getNodeId($path);
            
            if (!$nodeId) {
                return;
            }
            
            // Check if we've already processed this node recently
            $key = $nodeId . '-download';
            if (isset($this->processedNodes[$key])) {
                $this->logger->debug('Already tracked this download', ['path' => $path]);
                return;
            }
            
            // Mark as processed to prevent duplicates
            $this->processedNodes[$key] = time();
            
            // Clean up old processed nodes
            $this->cleanupProcessedNodes();
            
            // Get file information
            $fileInfo = $this->getFileInfo($path);
            if (!$fileInfo) {
                return;
            }
            
            $size = $fileInfo->getSize();
            $owner = $fileInfo->getOwner();
            
            if (!$owner || $size <= 0) {
                return;
            }
            
            $userId = $owner->getUID();
            
            // Track the download
            $this->logger->info('Download tracked via hooks: ' . $size . ' bytes for user ' . $userId, [
                'app' => 'transfer_quota_monitor',
                'path' => $path,
                'size' => $size
            ]);
            
            $this->quotaService->addUserTransfer($userId, $size);
            
        } catch (\Exception $e) {
            $this->logger->error('Error tracking file access: ' . $e->getMessage(), [
                'app' => 'transfer_quota_monitor',
                'exception' => $e,
                'parameters' => json_encode($parameters)
            ]);
        }
    }
    
    /**
     * Get file info for a path
     * 
     * @param string $path File path
     * @return \OCP\Files\FileInfo|null
     */
    private function getFileInfo(string $path) {
        try {
            // Check if we have a valid user session
            $user = $this->userSession->getUser();
            if ($user) {
                // Try to get the file from the user's storage
                $userFolder = $this->rootFolder->getUserFolder($user->getUID());
                if ($userFolder->nodeExists($path)) {
                    $node = $userFolder->get($path);
                    if (!$node->isFolder()) {
                        return $node;
                    }
                }
            }
            
            // If we can't find it in the user's storage or no user session,
            // try to get it directly from the filesystem
            if (Filesystem::isValidPath($path)) {
                return Filesystem::getFileInfo($path);
            }
        } catch (\Exception $e) {
            $this->logger->debug('Error getting file info: ' . $e->getMessage(), [
                'app' => 'transfer_quota_monitor',
                'exception' => $e,
                'path' => $path
            ]);
        }
        
        return null;
    }
    
    /**
     * Get node ID for a path
     * 
     * @param string $path File path
     * @return string|null Node ID
     */
    private function getNodeId(string $path) {
        try {
            $info = $this->getFileInfo($path);
            if ($info) {
                return (string)$info->getId();
            }
        } catch (\Exception $e) {
            $this->logger->debug('Error getting node ID: ' . $e->getMessage(), [
                'app' => 'transfer_quota_monitor',
                'exception' => $e,
                'path' => $path
            ]);
        }
        
        return null;
    }
    
    /**
     * Clean up processed nodes
     */
    private function cleanupProcessedNodes() {
        $now = time();
        foreach ($this->processedNodes as $key => $timestamp) {
            if ($now - $timestamp > 300) { // 5 minutes
                unset($this->processedNodes[$key]);
            }
        }
    }
}
