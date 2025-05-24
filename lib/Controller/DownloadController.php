<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Controller;

use OCA\TransferQuotaMonitor\Service\TransferQuotaService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for tracking streaming/media content
 */
class DownloadController extends Controller {
    /** @var IRootFolder */
    private $rootFolder;
    
    /** @var IUserSession */
    private $userSession;
    
    /** @var TransferQuotaService */
    private $quotaService;
    
    /** @var LoggerInterface */
    private $logger;
    
    public function __construct(
        string $appName,
        IRequest $request,
        IRootFolder $rootFolder,
        IUserSession $userSession,
        TransferQuotaService $quotaService,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->quotaService = $quotaService;
        $this->logger = $logger;
    }
    
    /**
     * Track streaming media files
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param int $fileId ID of the file to stream
     * @return Response
     */
    public function streamMedia(int $fileId): Response {
        $user = $this->userSession->getUser();
        
        if (!$user) {
            return new JSONResponse(['error' => 'Not logged in'], 401);
        }
        
        try {
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $files = $userFolder->getById($fileId);
            
            if (empty($files)) {
                throw new NotFoundException('File not found: ' . $fileId);
            }
            
            $file = reset($files);
            
            if ($file->isDirectory()) {
                throw new NotFoundException('Not a file: ' . $fileId);
            }
            
            // Track the stream access
            $owner = $file->getOwner();
            $size = $file->getSize();
            
            if ($owner && $size > 0) {
                $this->logger->debug('Stream tracked: ' . $size . ' bytes for user ' . $owner->getUID());
                $this->quotaService->addUserTransfer($owner->getUID(), $size);
            }
            
            // Return the file for streaming
            $response = new FileDisplayResponse($file);
            $response->addHeader('Content-Disposition', 'inline; filename="' . $file->getName() . '"');
            return $response;
            
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            $this->logger->error('Error streaming file: ' . $e->getMessage(), [
                'app' => 'transfer_quota_monitor',
                'exception' => $e
            ]);
            return new JSONResponse(['error' => 'Internal server error'], 500);
        }
    }
}
