<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Sabre;

use Psr\Log\LoggerInterface;
use OCA\TransferQuotaMonitor\Service\TransferQuotaService;
use OCA\DAV\Connector\Sabre\File;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * WebDAV plugin to track downloads directly from the Sabre/DAV layer
 */
class DownloadPlugin extends ServerPlugin {
    /** @var TransferQuotaService */
    private $quotaService;
    
    /** @var LoggerInterface */
    private $logger;
    
    /** @var Server */
    private $server;
    
    /** @var array */
    private $processedRequests = [];
    
    /**
     * @param TransferQuotaService $quotaService
     * @param LoggerInterface $logger
     */
    public function __construct(
        TransferQuotaService $quotaService,
        LoggerInterface $logger
    ) {
        $this->quotaService = $quotaService;
        $this->logger = $logger;
    }
    
    /**
     * Initializes the plugin
     *
     * @param Server $server
     * @return void
     */
    public function initialize(Server $server) {
        $this->server = $server;
        
        // Register before-method event to capture the node being accessed
        $server->on('method:GET', [$this, 'beforeGet'], 95);
        
        // Register after-response event to track the download size
        $server->on('afterMethod:GET', [$this, 'afterGet'], 5);
        
        // Also register for PROPFIND to catch directory listings
        $server->on('afterMethod:PROPFIND', [$this, 'afterPropfind'], 5);
    }
    
    /**
     * Intercepts GET requests (downloads and file views)
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return boolean
     */
    public function beforeGet(RequestInterface $request, ResponseInterface $response) {
        // Store the node path for later use in afterGet
        $path = $request->getPath();
        $this->logger->debug('DownloadPlugin: Intercepted GET request for: ' . $path, [
            'app' => 'transfer_quota_monitor',
            'user-agent' => $request->getHeader('User-Agent'),
            'headers' => json_encode($request->getHeaders())
        ]);
        
        return true; // Continue with request
    }
    
    /**
     * After GET method, track the download size
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return boolean
     */
    public function afterGet(RequestInterface $request, ResponseInterface $response) {
        try {
            $path = $request->getPath();
            
            // Generate unique key for this request to avoid double-counting
            $requestKey = md5($path . $request->getHeader('User-Agent') . time());
            if (isset($this->processedRequests[$requestKey])) {
                return true;
            }
            $this->processedRequests[$requestKey] = true;
            
            // Only track successful GET requests
            if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
                return true;
            }
            
            // Get node
            $node = $this->server->tree->getNodeForPath($path);
            if (!$node || !($node instanceof File)) {
                return true; // Not a file
            }
            
            // Get file info
            $fileInfo = $node->getFileInfo();
            if (!$fileInfo) {
                return true;
            }
            
            $size = $fileInfo->getSize();
            $owner = $fileInfo->getOwner();
            
            if (!$owner) {
                return true;
            }
            
            // Track download
            $this->logger->info('WebDAV download tracked: ' . $size . ' bytes for user ' . $owner->getUID(), [
                'app' => 'transfer_quota_monitor',
                'path' => $path,
                'size' => $size
            ]);
            
            $this->quotaService->addUserTransfer($owner->getUID(), $size);
        } catch (\Exception $e) {
            $this->logger->error('Error tracking WebDAV download: ' . $e->getMessage(), [
                'app' => 'transfer_quota_monitor',
                'exception' => $e,
                'path' => $request->getPath()
            ]);
        }
        
        return true; // Continue with other afterMethod handlers
    }
    
    /**
     * After PROPFIND method, track directory listings as minimal usage
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return boolean
     */
    public function afterPropfind(RequestInterface $request, ResponseInterface $response) {
        try {
            // Only track directory listings for successful responses
            if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
                return true;
            }
            
            // Get user from path (find user directory in path)
            $path = $request->getPath();
            
            // Extract username from path
            if (preg_match('|^/remote\.php/dav/files/([^/]+)|', $path, $matches)) {
                $userId = $matches[1];
                
                // Generate unique key for this request to avoid double-counting
                $requestKey = md5($path . $request->getHeader('User-Agent') . time());
                if (isset($this->processedRequests[$requestKey])) {
                    return true;
                }
                $this->processedRequests[$requestKey] = true;
                
                // Track a minimal amount for directory browsing (1KB per listing)
                $this->logger->debug('Directory listing tracked: 1KB for user ' . $userId, [
                    'app' => 'transfer_quota_monitor',
                    'path' => $path
                ]);
                
                $this->quotaService->addUserTransfer($userId, 1024);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error tracking directory listing: ' . $e->getMessage(), [
                'app' => 'transfer_quota_monitor',
                'exception' => $e,
                'path' => $request->getPath()
            ]);
        }
        
        return true;
    }
}
