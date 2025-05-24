<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Middleware;

use OCA\TransferQuotaMonitor\Service\TransferQuotaService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\BinaryFileResponse;
use OCP\AppFramework\Http\Headers;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Middleware;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use Psr\Log\LoggerInterface;

/**
 * Middleware to track file downloads by intercepting both:
 * 1. Regular download controllers/methods
 * 2. X-Accel-Redirect headers before nginx takes over
 */
class DownloadTrackingMiddleware extends Middleware {
    /** @var IRequest */
    private $request;
    
    /** @var IUserSession */
    private $userSession;
    
    /** @var TransferQuotaService */
    private $quotaService;
    
    /** @var IRootFolder */
    private $rootFolder;
    
    /** @var LoggerInterface */
    private $logger;

    /** @var array */
    private $processedRequests = [];
    
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        TransferQuotaService $quotaService,
        IRootFolder $rootFolder,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->userSession = $userSession;
        $this->quotaService = $quotaService;
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
    }
    
    /**
     * Run before the controller method execution
     * This is where we'll detect download requests early
     * 
     * @param object $controller The controller
     * @param string $methodName The name of the method
     * @return void
     */
    public function beforeController($controller, $methodName) {
        try {
            // Generate a unique key for this request to prevent double-counting
            $uri = $this->request->getRequestUri();
            $requestKey = md5($uri . time());
            
            if (isset($this->processedRequests[$requestKey])) {
                return;
            }
            
            $this->processedRequests[$requestKey] = true;
            
            // Log all controller calls for debugging
            $controllerClass = get_class($controller);
            $this->logger->debug('DownloadTrackingMiddleware: Controller ' . $controllerClass . '::' . $methodName . ', URI: ' . $uri, [
                'app' => 'transfer_quota_monitor'
            ]);
            
            // First, check if this is a download request from the Files app (context menu download)
            if (strpos($uri, '/apps/files/ajax/download.php') !== false) {
                $this->logger->debug('Detected Files app download request: ' . $uri);
                $this->trackFilesAppDownload();
                return;
            }
            
            // Check if this is a WebDAV download
            if (strpos($uri, '/remote.php/dav/files/') !== false && $this->request->getMethod() === 'GET') {
                $this->logger->debug('Detected WebDAV download request: ' . $uri);
                $this->trackWebDavDownload($uri);
                return;
            }
            
            // Check if this is a public share download
            if ((strpos($uri, '/s/') !== false || strpos($uri, '/apps/files_sharing/') !== false) && 
                (strpos($methodName, 'download') !== false || $this->request->getParam('download') !== null)) {
                $this->logger->debug('Detected public share download request: ' . $uri);
                $this->trackPublicShareDownload();
                return;
            }
            
            // Check for download methods in controllers
            if (strpos($controllerClass, 'DownloadController') !== false || 
                strpos($methodName, 'download') !== false ||
                strpos($methodName, 'getFile') !== false) {
                $this->logger->debug('Detected download controller/method: ' . $controllerClass . '::' . $methodName);
                $this->trackGenericDownload();
                return;
            }
        } catch (\Exception $e) {
            $this->logger->error('Error in beforeController download tracking: ' . $e->getMessage(), [
                'app' => 'transfer_quota_monitor',
                'exception' => $e
            ]);
        }
    }
    
    /**
     * After controller execution, check if this was a download request
     * and track it if needed
     *
     * @param object $controller The controller that was executed
     * @param string $methodName The name of the method that was executed
     * @param Response $response The response
     * @return Response
     */
    public function afterController($controller, $methodName, Response $response): Response {
        try {
            // Skip if not a file download or if tracking disabled
            if ($response->getStatus() !== Http::STATUS_OK) {
                return $response;
            }
            
            $path = $this->request->getPathInfo();
            
            // Log all download attempts for debugging
            $this->logger->debug('Download request detected in middleware: ' . $path . ' (' . get_class($response) . ')', [
                'app' => 'transfer_quota_monitor',
                'controller' => get_class($controller),
                'method' => $methodName
            ]);
            
            // Track file downloads via the /index.php/apps/files/download path
            if (strpos($path, '/apps/files/download') !== false) {
                $this->trackDirectFileDownload($path);
            }
            // Track public share downloads
            else if (strpos($path, '/s/') === 0 || strpos($path, '/index.php/s/') !== false) {
                $this->trackFileBySharePath($path);
            }
            // Track streaming downloads (via the endpoint or webdav)
            else if ($response instanceof StreamResponse || $response instanceof BinaryFileResponse) {
                $this->trackStreamDownload($response, $path);
            }
            
            // Check for X-Accel-Redirect header which indicates nginx will take over
            $headers = $response->getHeaders();
            
            // Debug log the headers
            $this->logger->debug('Response headers: ' . json_encode($headers), [
                'app' => 'transfer_quota_monitor'
            ]);
            
            // Check for the X-Accel-Redirect header which nginx uses
            if (isset($headers['X-Accel-Redirect'])) {
                $accelPath = $headers['X-Accel-Redirect'];
                $this->logger->debug('Detected X-Accel-Redirect: ' . $accelPath, [
                    'app' => 'transfer_quota_monitor'
                ]);
                
                // Try to determine the file from the X-Accel-Redirect path
                $this->trackXAccelRedirect($accelPath);
            }
            
            // Check for download response types
            if ($response instanceof StreamResponse || 
                $response instanceof BinaryFileResponse || 
                $response instanceof DownloadResponse) {
                
                $this->logger->debug('Detected download response type: ' . get_class($response), [
                    'app' => 'transfer_quota_monitor'
                ]);
                
                // Try to get file info from the content disposition header if available
                if (isset($headers['Content-Disposition'])) {
                    $disposition = $headers['Content-Disposition'];
                    $this->logger->debug('Content-Disposition: ' . $disposition, [
                        'app' => 'transfer_quota_monitor'
                    ]);
                    
                    // Extract filename from the Content-Disposition header
                    if (preg_match('/filename="([^"]+)"/', $disposition, $matches)) {
                        $filename = $matches[1];
                        $this->logger->debug('Detected filename from Content-Disposition: ' . $filename, [
                            'app' => 'transfer_quota_monitor'
                        ]);
                        
                        // Try to track the file by its name
                        $this->trackFileByName($filename);
                    }
                }
            }
            
            // Return the unaltered response
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Error in afterController download tracking: ' . $e->getMessage(), [
                'app' => 'transfer_quota_monitor',
                'exception' => $e
            ]);
            
            // Return the unaltered response
            return $response;
        }
    }
    
    /**
     * Track a download from the Files app
     */
    private function trackFilesAppDownload() {
        // Get the file name and directory from the request parameters
        $dir = $this->request->getParam('dir', '/');
        $files = $this->request->getParam('files', '');
        
        // Files may be a string or array
        if (is_string($files)) {
            $files = [$files];
        } else if (is_array($files)) {
            // Already an array, good to go
        } else {
            // Not a valid format
            $this->logger->debug('Invalid files parameter format');
            return;
        }
        
        $user = $this->userSession->getUser();
        if (!$user) {
            $this->logger->debug('No user found for download tracking');
            return;
        }
        
        // Process each file in the download request
        foreach ($files as $filename) {
            // Construct the full path
            $path = trim($dir, '/') . '/' . trim($filename, '/');
            $path = '/' . ltrim($path, '/');
            
            $this->trackFileByPath($path, $user->getUID());
        }
    }
    
    /**
     * Track a WebDAV download
     * 
     * @param string $uri The request URI
     */
    private function trackWebDavDownload($uri) {
        // Extract the path from the WebDAV URI
        $matches = [];
        if (preg_match('|/remote\.php/dav/files/([^/]+)(/.+)|', $uri, $matches)) {
            $userId = $matches[1];
            $path = $matches[2];
            
            $this->trackFileByPath($path, $userId);
        }
    }
    
    /**
     * Track a public share download
     */
    private function trackPublicShareDownload() {
        // For public shares, we need to get the node ID or path if available
        $fileId = $this->request->getParam('fileId', null);
        $path = $this->request->getParam('path', '');
        $file = $this->request->getParam('files', '');
        
        // If we have a file ID, track by ID
        if ($fileId) {
            $this->trackFileById($fileId);
            return;
        }
        
        // If we have a path or file name, try to track
        if (!empty($path)) {
            $this->trackFileBySharePath($path);
        } else if (!empty($file)) {
            if (is_string($file)) {
                $this->trackFileBySharePath($file);
            }
        }
    }
    
    /**
     * Track a generic download
     */
    private function trackGenericDownload() {
        // Try all possible ways to identify the file
        $fileId = $this->request->getParam('fileId', null);
        $path = $this->request->getParam('path', '');
        $filename = $this->request->getParam('file', '');
        $files = $this->request->getParam('files', '');
        
        // Track by file ID if available
        if ($fileId) {
            $this->trackFileById($fileId);
            return;
        }
        
        // Track by path if available
        if (!empty($path)) {
            $user = $this->userSession->getUser();
            if ($user) {
                $this->trackFileByPath($path, $user->getUID());
            }
            return;
        }
        
        // Track by filename if available
        if (!empty($filename)) {
            $user = $this->userSession->getUser();
            if ($user) {
                $this->trackFileByPath('/' . $filename, $user->getUID());
            }
            return;
        }
        
        // Track by files parameter if available
        if (is_string($files)) {
            $user = $this->userSession->getUser();
            if ($user) {
                $this->trackFileByPath('/' . $files, $user->getUID());
            }
        }
    }
    
    /**
     * Track a download by file path
     * 
     * @param string $path The file path
     * @param string $userId The user ID
     */
    private function trackFileByPath($path, $userId) {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            if ($userFolder->nodeExists($path)) {
                $node = $userFolder->get($path);
                if (!$node->isDirectory()) {
                    // Get file info
                    $size = $node->getSize();
                    $owner = $node->getOwner();
                    
                    if ($owner && $size > 0) {
                        $ownerUid = $owner->getUID();
                        
                        $this->logger->info('Download tracked by path: ' . $path . ' - ' . $size . ' bytes for user ' . $ownerUid);
                        $this->quotaService->addUserTransfer($ownerUid, $size);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug('Error tracking file by path: ' . $e->getMessage());
        }
    }
    
    /**
     * Track a download by file ID
     * 
     * @param int|string $fileId The file ID
     */
    private function trackFileById($fileId) {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return;
            }
            
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $files = $userFolder->getById($fileId);
            
            if (!empty($files) && !$files[0]->isDirectory()) {
                $node = $files[0];
                $size = $node->getSize();
                $owner = $node->getOwner();
                
                if ($owner && $size > 0) {
                    $ownerUid = $owner->getUID();
                    
                    $this->logger->info('Download tracked by ID: ' . $fileId . ' - ' . $size . ' bytes for user ' . $ownerUid);
                    $this->quotaService->addUserTransfer($ownerUid, $size);
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug('Error tracking file by ID: ' . $e->getMessage());
        }
    }
    
    /**
     * Track a file download from a public share link
     *
     * @param string $path The URL path of the request
     */
    private function trackFileBySharePath($path) {
        try {
            // Extract share token from path (public shares have paths like /s/TOKEN)
            $token = null;
            $pathParts = explode('/', trim($path, '/'));
            
            // Normal share path: /s/TOKEN or /index.php/s/TOKEN
            if (count($pathParts) >= 2 && $pathParts[0] === 's') {
                $token = $pathParts[1];
            }
            // Alternative path: /index.php/s/TOKEN
            else if (count($pathParts) >= 3 && $pathParts[0] === 'index.php' && $pathParts[1] === 's') {
                $token = $pathParts[2];
            }
            // Public download endpoint: /publicdownload/TOKEN
            else if (count($pathParts) >= 2 && $pathParts[0] === 'publicdownload') {
                $token = $pathParts[1];
            }
            // Public WebDAV endpoint: /public-files/TOKEN
            else if (count($pathParts) >= 2 && $pathParts[0] === 'public-files') {
                $token = $pathParts[1];
            }
            
            if ($token) {
                $this->logger->warning('Public share token extracted from URL: ' . $token, [
                    'app' => 'transfer_quota_monitor',
                    'path' => $path
                ]);
                
                // Get the Nextcloud server container for share manager
                $shareManager = \OC::$server->get(\OCP\Share\IManager::class);
                
                // Try to get the share by token
                try {
                    $share = $shareManager->getShareByToken($token);
                    if ($share) {
                        $node = $share->getNode();
                        if ($node) {
                            // For directories, we need the specific file that was downloaded
                            if ($node->isFolder()) {
                                // If this is a folder, try to determine the specific file
                                // from the download request parameters
                                $fileName = $this->request->getParam('files');
                                if ($fileName) {
                                    // For multiple files, take the first one or track each
                                    if (is_array($fileName)) {
                                        if (count($fileName) > 0) {
                                            $fileName = $fileName[0];
                                        } else {
                                            return;
                                        }
                                    }
                                    
                                    try {
                                        $node = $node->get($fileName);
                                    } catch (\OCP\Files\NotFoundException $e) {
                                        $this->logger->debug('File not found in shared folder: ' . $fileName, [
                                            'app' => 'transfer_quota_monitor'
                                        ]);
                                        return;
                                    }
                                } else {
                                    // If no specific file is requested, check in URL path
                                    // In some cases the file path might be in the URL after the token
                                    if (count($pathParts) > 2) {
                                        $filePath = implode('/', array_slice($pathParts, 2));
                                        try {
                                            $node = $node->get($filePath);
                                            $this->logger->debug('Found file from URL path: ' . $filePath, [
                                                'app' => 'transfer_quota_monitor'
                                            ]);
                                        } catch (\OCP\Files\NotFoundException $e) {
                                            // Might be a folder download or a directory listing
                                            $this->logger->debug('Could not find file from URL path: ' . $filePath, [
                                                'app' => 'transfer_quota_monitor'
                                            ]);
                                            return;
                                        }
                                    } else {
                                        // If no specific file is requested, it might be a folder download
                                        // We'll just log this for now
                                        $this->logger->debug('Shared folder download, no specific file detected', [
                                            'app' => 'transfer_quota_monitor'
                                        ]);
                                        return;
                                    }
                                }
                            }
                            
                            // Skip directories (only track file downloads)
                            if ($node->isFolder()) {
                                return;
                            }
                            
                            // Get the file size and owner
                            $size = $node->getSize();
                            $owner = $node->getOwner();
                            
                            if ($owner && $size > 0) {
                                $ownerUid = $owner->getUID();
                                
                                $this->logger->warning('PUBLIC SHARE DOWNLOAD TRACKED (middleware): ' . $node->getName() . ' (' . $size . ' bytes) owned by ' . $ownerUid, [
                                    'app' => 'transfer_quota_monitor'
                                ]);
                                
                                // Add the file size to the user's transfer quota
                                $this->quotaService->addUserTransfer($ownerUid, $size);
                                return;
                            }
                        }
                    }
                } catch (\OCP\Share\Exceptions\ShareNotFound $e) {
                    $this->logger->debug('Share not found for token: ' . $token, [
                        'app' => 'transfer_quota_monitor',
                        'exception' => $e
                    ]);
                }
            }
            
            // If we got here, we couldn't properly track the share
            $this->logger->debug('Could not properly track public share download for path: ' . $path, [
                'app' => 'transfer_quota_monitor'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error tracking public share: ' . $e->getMessage(), [
                'app' => 'transfer_quota_monitor',
                'exception' => $e
            ]);
        }
    }
    
    /**
     * Track a download by X-Accel-Redirect path
     * 
     * @param string $accelPath The X-Accel-Redirect path
     */
    private function trackXAccelRedirect($accelPath) {
        try {
            // Try to determine the file from the X-Accel-Redirect path
            $matches = [];
            if (preg_match('/\/data\/([^\/]+)\/files\/(.+)$/', $accelPath, $matches)) {
                $userId = $matches[1];
                $path = '/' . $matches[2];
                
                $this->logger->debug('Parsed X-Accel-Redirect path: user=' . $userId . ', path=' . $path, [
                    'app' => 'transfer_quota_monitor'
                ]);
                
                $this->trackFileByPath($path, $userId);
            } else {
                // If the regex didn't match, log the path for debugging
                $this->logger->debug('X-Accel-Redirect path did not match expected format: ' . $accelPath, [
                    'app' => 'transfer_quota_monitor'
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->debug('Error tracking X-Accel-Redirect: ' . $e->getMessage());
        }
    }
    
    /**
     * Track a download by file name
     * 
     * @param string $filename The file name
     */
    private function trackFileByName($filename) {
        try {
            // Try to find the file by its name
            $user = $this->userSession->getUser();
            if ($user) {
                $userFolder = $this->rootFolder->getUserFolder($user->getUID());
                $files = $userFolder->getById($filename);
                
                if (!empty($files) && !$files[0]->isDirectory()) {
                    $node = $files[0];
                    $size = $node->getSize();
                    $owner = $node->getOwner();
                    
                    if ($owner && $size > 0) {
                        $ownerUid = $owner->getUID();
                        
                        $this->logger->info('Download tracked by name: ' . $filename . ' - ' . $size . ' bytes for user ' . $ownerUid);
                        $this->quotaService->addUserTransfer($ownerUid, $size);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug('Error tracking file by name: ' . $e->getMessage());
        }
    }
    
    /**
     * Track a direct file download
     * 
     * @param string $path The path of the file
     */
    private function trackDirectFileDownload($path) {
        try {
            // Extract the file ID from the path
            $matches = [];
            if (preg_match('/\/apps\/files\/download\/(\d+)/', $path, $matches)) {
                $fileId = $matches[1];
                
                $this->logger->debug('Parsed direct file download path: file ID=' . $fileId, [
                    'app' => 'transfer_quota_monitor'
                ]);
                
                $this->trackFileById($fileId);
            } else {
                // If the regex didn't match, log the path for debugging
                $this->logger->debug('Direct file download path did not match expected format: ' . $path, [
                    'app' => 'transfer_quota_monitor'
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->debug('Error tracking direct file download: ' . $e->getMessage());
        }
    }
    
    /**
     * Track a stream download
     * 
     * @param StreamResponse|BinaryFileResponse $response The response
     * @param string $path The path of the file
     */
    private function trackStreamDownload($response, $path) {
        try {
            // Try to get the file size from the response headers
            $headers = $response->getHeaders();
            $size = $headers['Content-Length'] ?? null;
            
            if ($size) {
                $this->logger->debug('Parsed stream download size: ' . $size . ' bytes', [
                    'app' => 'transfer_quota_monitor'
                ]);
                
                // Try to get the user ID from the path
                $matches = [];
                if (preg_match('/\/files\/([^\/]+)/', $path, $matches)) {
                    $userId = $matches[1];
                    
                    $this->logger->debug('Parsed stream download user ID: ' . $userId, [
                        'app' => 'transfer_quota_monitor'
                    ]);
                    
                    $this->quotaService->addUserTransfer($userId, $size);
                } else {
                    // If the regex didn't match, log the path for debugging
                    $this->logger->debug('Stream download path did not match expected format: ' . $path, [
                        'app' => 'transfer_quota_monitor'
                    ]);
                }
            } else {
                $this->logger->debug('Could not determine stream download size', [
                    'app' => 'transfer_quota_monitor'
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->debug('Error tracking stream download: ' . $e->getMessage());
        }
    }
}
