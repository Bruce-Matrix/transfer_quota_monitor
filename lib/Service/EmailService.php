<?php
namespace OCA\TransferQuotaMonitor\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Defaults;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Mail\IMailer;
use OCP\Mail\IEMailTemplate;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use OCP\L10N\IFactory;

class EmailService {
    /** @var IMailer */
    private $mailer;

    /** @var Defaults */
    private $defaults;

    /** @var IConfig */
    private $config;

    /** @var IL10N */
    private $l10n;
    
    /** @var IFactory */
    private $l10nFactory;

    /** @var IURLGenerator */
    private $urlGenerator;

    /** @var ITimeFactory */
    private $timeFactory;

    /** @var LoggerInterface */
    private $logger;
    
    /** @var IGroupManager */
    private $groupManager;

    /** @var string */
    private $appName;

    public function __construct(
        IMailer $mailer,
        Defaults $defaults,
        IConfig $config,
        IL10N $l10n,
        IFactory $l10nFactory,
        IURLGenerator $urlGenerator,
        ITimeFactory $timeFactory,
        LoggerInterface $logger,
        IGroupManager $groupManager,
        string $appName
    ) {
        $this->mailer = $mailer;
        $this->defaults = $defaults;
        $this->config = $config;
        $this->l10n = $l10n;
        $this->l10nFactory = $l10nFactory;
        $this->urlGenerator = $urlGenerator;
        $this->timeFactory = $timeFactory;
        $this->logger = $logger;
        $this->groupManager = $groupManager;
        $this->appName = $appName;
    }

    /**
     * Send warning email to user
     *
     * @param IUser $user The user
     * @param int $usagePercent The percentage of quota used
     * @param int $threshold The threshold that was reached
     * @param int $quotaLimit The total quota limit in bytes
     * @param int $usedBytes The number of bytes used
     * @return bool
     */
    public function sendWarningEmail(IUser $user, int $usagePercent, int $threshold, int $quotaLimit, int $usedBytes): bool {
        $recipientEmail = $user->getEMailAddress();
        if (!$recipientEmail) {
            $this->logger->warning('Could not send warning email to user {uid} because the email address is not set', [
                'uid' => $user->getUID(),
            ]);
            return false;
        }

        $displayName = $user->getDisplayName();
        $senderName = $this->defaults->getName();

        // Create the email template
        $template = $this->mailer->createEMailTemplate('transfer_quota_monitor.warning', [
            'displayname' => $displayName,
            'usage_percent' => $usagePercent,
            'threshold' => $threshold,
            'quota_limit' => $this->formatBytes($quotaLimit),
            'used_bytes' => $this->formatBytes($usedBytes),
            'instance' => $this->defaults->getName(),
        ]);

        // Setup email template
        $template->setSubject($this->l10n->t('Warning: Data transfer limit approaching'));
        $template->addHeader();
        $template->addHeading($this->l10n->t('Data Transfer Warning'));
        
        // Add the logo
        $template->addBodyText($this->l10n->t('Hello %s,', [$displayName]));
        $template->addBodyText($this->l10n->t('Your data transfer usage has reached %d%% of your monthly limit.', [$usagePercent]));
        
        // Add the details
        $template->addBodyText(
            $this->l10n->t('Current usage: %1$s of %2$s (%3$d%%)', [
                $this->formatBytes($usedBytes),
                $this->formatBytes($quotaLimit),
                $usagePercent
            ])
        );
        
        // Add a link to the Files app
        $filesUrl = $this->urlGenerator->getAbsoluteURL('/');
        $template->addBodyButton($this->l10n->t('Go to Files'), $filesUrl);
        
        // Add footer info
        $template->addBodyText($this->l10n->t('If you need more transfer capacity, please contact your administrator.'));
        $template->addFooter();
        
        // Send the email
        try {
            $message = $this->mailer->createMessage();
            $message->setTo([$recipientEmail => $displayName]);
            $message->useTemplate($template);
            $this->mailer->send($message);
            $this->logger->info('Sent warning email to user {uid} about transfer quota', [
                'uid' => $user->getUID(),
            ]);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Could not send warning email: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Send critical warning email to user
     *
     * @param IUser $user The user
     * @param int $usagePercent The percentage of quota used
     * @param int $threshold The threshold that was reached
     * @param int $quotaLimit The total quota limit in bytes
     * @param int $usedBytes The number of bytes used
     * @return bool
     */
    public function sendCriticalEmail(IUser $user, int $usagePercent, int $threshold, int $quotaLimit, int $usedBytes): bool {
        $recipientEmail = $user->getEMailAddress();
        if (!$recipientEmail) {
            $this->logger->warning('Could not send critical email to user {uid} because the email address is not set', [
                'uid' => $user->getUID(),
            ]);
            return false;
        }

        $displayName = $user->getDisplayName();
        $senderName = $this->defaults->getName();

        // Create the email template
        $template = $this->mailer->createEMailTemplate('transfer_quota_monitor.critical', [
            'displayname' => $displayName,
            'usage_percent' => $usagePercent,
            'threshold' => $threshold,
            'quota_limit' => $this->formatBytes($quotaLimit),
            'used_bytes' => $this->formatBytes($usedBytes),
            'instance' => $this->defaults->getName(),
        ]);

        // Setup email template
        $template->setSubject($this->l10n->t('CRITICAL: Data transfer limit almost reached'));
        $template->addHeader();
        $template->addHeading($this->l10n->t('Data Transfer Critical Warning'));
        
        // Add the logo
        $template->addBodyText($this->l10n->t('Hello %s,', [$displayName]));
        $template->addBodyText($this->l10n->t('Your data transfer usage has reached %d%% of your monthly limit.', [$usagePercent]));
        $template->addBodyText($this->l10n->t('You may soon be unable to upload or download files if you reach 100%% of your limit.'));
        
        // Add the details
        $template->addBodyText(
            $this->l10n->t('Current usage: %1$s of %2$s (%3$d%%)', [
                $this->formatBytes($usedBytes),
                $this->formatBytes($quotaLimit),
                $usagePercent
            ])
        );
        
        // Add a link to the Files app
        $filesUrl = $this->urlGenerator->getAbsoluteURL('/');
        $template->addBodyButton($this->l10n->t('Go to Files'), $filesUrl);
        
        // Add footer info
        $template->addBodyText($this->l10n->t('If you need more transfer capacity, please contact your administrator.'));
        $template->addFooter();
        
        // Send the email
        try {
            $message = $this->mailer->createMessage();
            $message->setTo([$recipientEmail => $displayName]);
            $message->useTemplate($template);
            $this->mailer->send($message);
            $this->logger->info('Sent critical email to user {uid} about transfer quota', [
                'uid' => $user->getUID(),
            ]);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Could not send critical email: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Send admin notification about user exceeding transfer quota
     *
     * @param IUser $user The user exceeding their quota
     * @param int $usagePercent The percentage of quota used
     * @param int $quotaLimit The total quota limit in bytes
     * @param int $usedBytes The number of bytes used
     * @return bool
     */
    public function notifyAdmin(IUser $user, int $usagePercent, int $quotaLimit, int $usedBytes): bool {
        // Get all admin users
        $adminUsers = $this->groupManager->get('admin')->getUsers();
        if (empty($adminUsers)) {
            $this->logger->warning('No admin users found to notify about transfer quota');
            return false;
        }
        
        $displayName = $user->getDisplayName();
        $userId = $user->getUID();
        $senderName = $this->defaults->getName();
        $success = false;

        // Send notification to each admin
        foreach ($adminUsers as $adminUser) {
            $adminEmail = $adminUser->getEMailAddress();
            
            // Skip admins without email or disabled
            if (!$adminEmail || !$adminUser->isEnabled()) {
                continue;
            }
            
            // Get admin's language
            $language = $this->l10nFactory->getUserLanguage($adminUser);
            $l10n = $this->l10nFactory->get('transfer_quota_monitor', $language);
            
            // Create the email template
            $template = $this->mailer->createEMailTemplate('transfer_quota_monitor.admin', [
                'username' => $userId,
                'displayname' => $displayName,
                'admin_displayname' => $adminUser->getDisplayName(),
                'usage_percent' => $usagePercent,
                'quota_limit' => $this->formatBytes($quotaLimit),
                'used_bytes' => $this->formatBytes($usedBytes),
                'instance' => $this->defaults->getName(),
            ]);

            // Setup email template
            $template->setSubject($l10n->t('User %s has exceeded transfer quota', [$userId]));
            $template->addHeader();
            $template->addHeading($l10n->t('RedCloud Transfer Quota Alert'));
            
            $template->addBodyText($l10n->t('Hello %s,', [$adminUser->getDisplayName()]));
            $template->addBodyText($l10n->t('User %s (%s) has exceeded %d%% of their monthly data transfer limit.', [$displayName, $userId, $usagePercent]));
            
            // Add the details
            $template->addBodyText(
                $l10n->t('Current usage: %1$s of %2$s (%3$d%%)', [
                    $this->formatBytes($usedBytes),
                    $this->formatBytes($quotaLimit),
                    $usagePercent
                ])
            );
            
            // Add a link to the admin settings
            $adminUrl = $this->urlGenerator->getAbsoluteURL('/settings/admin/additional');
            $template->addBodyButton($l10n->t('Go to Admin Settings'), $adminUrl);
            
            $template->addFooter();
            
            // Send the email
            try {
                $message = $this->mailer->createMessage();
                $message->setTo([$adminEmail => $adminUser->getDisplayName()]);
                $message->useTemplate($template);
                $this->mailer->send($message);
                $this->logger->info('Sent admin notification email to {admin} about user {uid} transfer quota', [
                    'admin' => $adminUser->getUID(),
                    'uid' => $user->getUID(),
                ]);
                $success = true;
            } catch (\Exception $e) {
                $this->logger->error('Could not send admin notification email to {admin}: {message}', [
                    'admin' => $adminUser->getUID(),
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
        
        return $success;
    }

    /**
     * Format bytes to human readable format
     * 
     * @param int $bytes The number of bytes
     * @param int $precision The precision of the result
     * @return string
     */
    private function formatBytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
