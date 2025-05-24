<?php

namespace OCA\TransferQuotaMonitor\Controller;

use OCA\TransferQuotaMonitor\Service\TransferQuotaService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;

class AdminController extends Controller {
    /** @var IConfig */
    protected $config;
    
    /** @var TransferQuotaService */
    private $quotaService;
    
    /** @var IUserManager */
    private $userManager;
    
    /** @var string */
    protected $appName;

    public function __construct($appName, IRequest $request, IConfig $config, TransferQuotaService $quotaService, IUserManager $userManager) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->quotaService = $quotaService;
        $this->userManager = $userManager;
        $this->appName = $appName;
    }

    /**
     * @NoAdminRequired
     * @return JSONResponse
     */
    public function getQuotas() {
        $users = [];
        $quotas = [];
        
        $this->userManager->callForAllUsers(function($user) use (&$users) {
            $users[] = $user;
        });
        
        // Get quota for each user
        foreach ($users as $user) {
            $userId = $user->getUID();
            $quota = $this->quotaService->getUserQuota($userId);
            
            $quotas[] = [
                'userId' => $userId,
                'displayName' => $user->getDisplayName(),
                'limit' => $quota['limit'],
                'usage' => $quota['usage'],
                'lastReset' => $quota['lastReset']
            ];
        }
        
        // Get thresholds for the frontend
        $warningThreshold = $this->config->getAppValue($this->appName, 'warning_threshold', '80');
        $criticalThreshold = $this->config->getAppValue($this->appName, 'critical_threshold', '95');
        
        return new JSONResponse([
            'quotas' => $quotas,
            'warning_threshold' => $warningThreshold,
            'critical_threshold' => $criticalThreshold
        ]);
    }

    /**
     * @NoAdminRequired
     * @param string $userId
     * @param int $quota in GiB
     * @return JSONResponse
     */
    public function setQuota($userId, $quota) {
        // Convert GiB to bytes for storage (RedCloud's tiered plans: 250GB, 500GB, 1TB)
        $quotaBytes = (int)$quota * 1024 * 1024 * 1024;
        
        // Set the quota - this already checks thresholds internally
        $success = $this->quotaService->setUserQuota($userId, $quotaBytes);
        
        // Do NOT call forceCheckUserQuota as it will cause duplicate notifications
        
        return new JSONResponse([
            'status' => $success ? 'success' : 'error'
        ]);
    }

    /**
     * @NoAdminRequired
     * @param int $warning
     * @param int $critical
     * @return JSONResponse
     */
    public function setThresholds($warning, $critical) {
        $this->config->setAppValue($this->appName, 'warning_threshold', $warning);
        $this->config->setAppValue($this->appName, 'critical_threshold', $critical);
        
        // When thresholds change, we should recheck all users' quotas since someone may now
        // be over the new thresholds
        $users = [];
        $this->userManager->callForAllUsers(function($user) use (&$users) {
            $users[] = $user;
        });
        
        foreach ($users as $user) {
            $userId = $user->getUID();
            $this->quotaService->forceCheckUserQuota($userId);
        }
        
        return new JSONResponse(['status' => 'success']);
    }

    /**
     * @NoAdminRequired
     * @param string $userId
     * @return JSONResponse
     */
    public function resetUserUsage($userId) {
        $success = $this->quotaService->resetUserUsage($userId);
        
        return new JSONResponse([
            'status' => $success ? 'success' : 'error',
            'success' => $success
        ]);
    }
}
