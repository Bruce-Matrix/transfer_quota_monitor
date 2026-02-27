<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Service;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use OCA\TransferQuotaMonitor\Service\EmailService;

class TransferQuotaService {
    /** @var IDBConnection */
    private $db;
    
    /** @var IConfig */
    private $config;
    
    /** @var INotificationManager */
    private $notificationManager;
    
    /** @var IUserManager */
    private $userManager;
    
    /** @var EmailService */
    private $emailService;
    
    /** @var LoggerInterface */
    private $logger;
    
    /** @var string */
    private $appName;
    
    public function __construct(
        IDBConnection $db,
        IConfig $config,
        INotificationManager $notificationManager,
        IUserManager $userManager,
        EmailService $emailService,
        LoggerInterface $logger,
        string $appName
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->notificationManager = $notificationManager;
        $this->userManager = $userManager;
        $this->emailService = $emailService;
        $this->logger = $logger;
        $this->appName = $appName;
        
        // Check if table exists and create it if it doesn't
        $this->ensureTableExists();
    }
    
    /**
     * Ensure the transfer_quota_limits table exists
     */
    private function ensureTableExists() {
        try {
            $this->db->executeQuery("SELECT 1 FROM *PREFIX*transfer_quota_limits LIMIT 1");
        } catch (\Exception $e) {
            // Table doesn't exist, create it
            $this->logger->info('Creating transfer_quota_limits table');
            $sql = "CREATE TABLE IF NOT EXISTS *PREFIX*transfer_quota_limits (
                id SERIAL PRIMARY KEY, 
                user_id VARCHAR(64) NOT NULL UNIQUE, 
                monthly_limit BIGINT NOT NULL DEFAULT 0, 
                current_usage BIGINT NOT NULL DEFAULT 0, 
                last_reset TIMESTAMP NOT NULL DEFAULT NOW(), 
                warning_sent SMALLINT NOT NULL DEFAULT 0, 
                critical_warning_sent SMALLINT NOT NULL DEFAULT 0
            )";
            $this->db->executeStatement($sql);
        }
    }
    
    /**
     * Get quota info for a user
     * 
     * @param string $userId
     * @return array
     */
    public function getUserQuota(string $userId) {
        $this->ensureTableExists();
        
        $qb = $this->db->getQueryBuilder();
        
        try {
            $qb->select('*')
               ->from('*PREFIX*transfer_quota_limits')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            
            $result = $qb->executeQuery();
            $row = $result->fetchAssociative();
            $result->free();
            
            if ($row) {
                return [
                    'userId' => $row['user_id'],
                    'limit' => (int)$row['monthly_limit'],
                    'usage' => (int)$row['current_usage'],
                    'lastReset' => $row['last_reset'],
                    'warningSent' => (int)$row['warning_sent'],
                    'criticalWarningSent' => (int)$row['critical_warning_sent']
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Error getting user quota: ' . $e->getMessage(), ['app' => $this->appName]);
            // Ensure table exists for next time
            $this->ensureTableExists();
        }
        
        // Return default if no entry exists
        return [
            'userId' => $userId,
            'limit' => 0,
            'usage' => 0,
            'lastReset' => date('Y-m-d H:i:s'),
            'warningSent' => 0,
            'criticalWarningSent' => 0
        ];
    }
    
    /**
     * Set a user's quota
     * 
     * @param string $userId
     * @param int $quota Quota in bytes
     * @return bool
     */
    public function setUserQuota(string $userId, int $quota) {
        $this->ensureTableExists();
        
        $qb = $this->db->getQueryBuilder();
        
        try {
            // Check if record exists and get current usage
            $existingQuota = $this->getUserQuota($userId);
            $currentUsage = $existingQuota['usage'];
            
            if ($existingQuota['limit'] > 0) {
                // Update
                $qb->update('*PREFIX*transfer_quota_limits')
                   ->set('monthly_limit', $qb->createNamedParameter($quota, \PDO::PARAM_INT));
                
                // If quota changed, reset warning flags so new notifications can be sent
                if ($existingQuota['limit'] != $quota) {
                    $qb->set('warning_sent', $qb->createNamedParameter(0, \PDO::PARAM_INT))
                       ->set('critical_warning_sent', $qb->createNamedParameter(0, \PDO::PARAM_INT));
                }
                
                $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
                $qb->executeStatement();
            } else {
                // Insert
                $qb->insert('*PREFIX*transfer_quota_limits')
                   ->values([
                        'user_id' => $qb->createNamedParameter($userId),
                        'monthly_limit' => $qb->createNamedParameter($quota, \PDO::PARAM_INT),
                        'current_usage' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
                        'last_reset' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                        'warning_sent' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
                        'critical_warning_sent' => $qb->createNamedParameter(0, \PDO::PARAM_INT)
                   ]);
                
                $qb->executeStatement();
            }
            
            // Immediately check if the user's current usage exceeds the new quota thresholds
            if ($quota > 0 && $currentUsage > 0) {
                $this->checkThresholds($userId, $currentUsage, $quota);
            }
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error setting user quota: ' . $e->getMessage(), ['app' => $this->appName]);
            return false;
        }
    }
    
    /**
     * Add transfer usage for a user
     * 
     * @param string $userId
     * @param int $bytes
     * @return bool
     */
    public function addUserTransfer(string $userId, int $bytes) {
        $this->ensureTableExists();
        
        $quota = $this->getUserQuota($userId);
        
        if (!$quota || $quota['limit'] === 0) {
            // No quota set for this user, no need to track
            return true;
        }
        
        $qb = $this->db->getQueryBuilder();
        
        try {
            // Update usage with proper type casting for PostgreSQL compatibility
            $newUsage = $quota['usage'] + $bytes;
            
            $qb->update('*PREFIX*transfer_quota_limits')
               ->set('current_usage', $qb->createNamedParameter((string)$newUsage, \PDO::PARAM_STR))
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            
            $qb->executeStatement();
            
            // Check thresholds
            $this->checkThresholds($userId, $newUsage, $quota['limit']);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error adding user transfer: ' . $e->getMessage(), ['app' => $this->appName, 'exception' => $e]);
            return false;
        }
    }
    
    /**
     * Force check a user's quota based on current usage
     * 
     * @param string $userId
     * @return bool
     */
    public function forceCheckUserQuota(string $userId) {
        $this->ensureTableExists();
        
        $quota = $this->getUserQuota($userId);
        
        if (!$quota || $quota['limit'] === 0) {
            // No quota set for this user, no need to check
            return true;
        }
        
        // Reset notification flags
        $qb = $this->db->getQueryBuilder();
        $qb->update('*PREFIX*transfer_quota_limits')
           ->set('warning_sent', $qb->createNamedParameter(0, \PDO::PARAM_INT))
           ->set('critical_warning_sent', $qb->createNamedParameter(0, \PDO::PARAM_INT))
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
        
        // Check thresholds with current usage
        $this->checkThresholds($userId, $quota['usage'], $quota['limit']);
        
        return true;
    }
    
    /**
     * Reset a user's usage
     * 
     * @param string $userId
     * @return bool
     */
    public function resetUserUsage(string $userId) {
        $this->ensureTableExists();
        
        $qb = $this->db->getQueryBuilder();
        
        try {
            $qb->update('*PREFIX*transfer_quota_limits')
               ->set('current_usage', $qb->createNamedParameter(0, \PDO::PARAM_INT))
               ->set('last_reset', $qb->createNamedParameter(date('Y-m-d H:i:s')))
               ->set('warning_sent', $qb->createNamedParameter(0, \PDO::PARAM_INT))
               ->set('critical_warning_sent', $qb->createNamedParameter(0, \PDO::PARAM_INT))
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            
            $qb->executeStatement();
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error resetting user usage: ' . $e->getMessage(), ['app' => $this->appName]);
            return false;
        }
    }
    
    /**
     * Reset all users' usage
     * 
     * @return bool
     */
    public function resetAllUsage() {
        $this->ensureTableExists();
        
        $qb = $this->db->getQueryBuilder();
        
        try {
            $qb->update('*PREFIX*transfer_quota_limits')
               ->set('current_usage', $qb->createNamedParameter(0, \PDO::PARAM_INT))
               ->set('last_reset', $qb->createNamedParameter(date('Y-m-d H:i:s')))
               ->set('warning_sent', $qb->createNamedParameter(0, \PDO::PARAM_INT))
               ->set('critical_warning_sent', $qb->createNamedParameter(0, \PDO::PARAM_INT));
            
            $qb->executeStatement();
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error resetting all usage: ' . $e->getMessage(), ['app' => $this->appName]);
            return false;
        }
    }
    
    /**
     * Check if a user has reached warning thresholds
     * 
     * @param string $userId
     * @param int $usage
     * @param int $limit
     */
    private function checkThresholds(string $userId, int $usage, int $limit) {
        if ($limit <= 0) {
            return;
        }
        
        $warningThreshold = (int)$this->config->getAppValue($this->appName, 'warning_threshold', 80);
        $criticalThreshold = (int)$this->config->getAppValue($this->appName, 'critical_threshold', 95);
        
        $percentUsed = ($usage / $limit) * 100;
        
        $user = $this->getUserQuota($userId);
        
        // Get the user object
        $userObject = $this->userManager->get($userId);
        if (!$userObject) {
            $this->logger->warning('Could not find user ' . $userId . ' to send notifications');
            return;
        }
        
        // Warning notification
        if ($percentUsed >= $warningThreshold && $user['warningSent'] === 0) {
            // Send notification
            $this->sendWarningNotification($userId, $percentUsed, $warningThreshold);
            
            // Send email
            $this->emailService->sendWarningEmail($userObject, (int)$percentUsed, $warningThreshold, $limit, $usage);
            
            // Update the DB
            $qb = $this->db->getQueryBuilder();
            $qb->update('*PREFIX*transfer_quota_limits')
               ->set('warning_sent', $qb->createNamedParameter(1, \PDO::PARAM_INT))
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            
            $qb->executeStatement();
        }
        
        // Critical notification
        if ($percentUsed >= $criticalThreshold && $user['criticalWarningSent'] === 0) {
            // Send notification
            $this->sendCriticalNotification($userId, $percentUsed, $criticalThreshold);
            
            // Send email
            $this->emailService->sendCriticalEmail($userObject, (int)$percentUsed, $criticalThreshold, $limit, $usage);
            
            // Notify admin
            $this->emailService->notifyAdmin($userObject, (int)$percentUsed, $limit, $usage);
            
            // Update the DB
            $qb = $this->db->getQueryBuilder();
            $qb->update('*PREFIX*transfer_quota_limits')
               ->set('critical_warning_sent', $qb->createNamedParameter(1, \PDO::PARAM_INT))
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            
            $qb->executeStatement();
        }
    }
    
    /**
     * Send a warning notification
     * 
     * @param string $userId
     * @param float $percentUsed
     * @param int $threshold
     */
    private function sendWarningNotification(string $userId, float $percentUsed, int $threshold) {
        $notification = $this->notificationManager->createNotification();
        $notification->setApp($this->appName)
                     ->setUser($userId)
                     ->setDateTime(new \DateTime())
                     ->setObject('transfer_quota', $userId)
                     ->setSubject('warning_threshold_reached', [
                         'percent' => round($percentUsed, 1),
                         'threshold' => $threshold
                     ]);
        
        $this->notificationManager->notify($notification);
    }
    
    /**
     * Send a critical notification
     * 
     * @param string $userId
     * @param float $percentUsed
     * @param int $threshold
     */
    private function sendCriticalNotification(string $userId, float $percentUsed, int $threshold) {
        $notification = $this->notificationManager->createNotification();
        $notification->setApp($this->appName)
                     ->setUser($userId)
                     ->setDateTime(new \DateTime())
                     ->setObject('transfer_quota', $userId)
                     ->setSubject('critical_threshold_reached', [
                         'percent' => round($percentUsed, 1),
                         'threshold' => $threshold
                     ]);
        
        $this->notificationManager->notify($notification);
    }
}
