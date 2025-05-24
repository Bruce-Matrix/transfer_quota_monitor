<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\IConfig;
use OCP\IUserManager;

class Admin implements ISettings {
    private $config;
    private $userManager;

    public function __construct(IConfig $config, IUserManager $userManager) {
        $this->config = $config;
        $this->userManager = $userManager;
    }

    public function getForm(): TemplateResponse {
        $users = [];
        $this->userManager->callForSeenUsers(function($user) use (&$users) {
            $users[] = [
                'id' => $user->getUID(),
                'displayName' => $user->getDisplayName()
            ];
        });

        $parameters = [
            'users' => $users,
            'warning_threshold' => $this->config->getAppValue('transfer_quota_monitor', 'warning_threshold', '80'),
            'critical_threshold' => $this->config->getAppValue('transfer_quota_monitor', 'critical_threshold', '90'),
        ];

        return new TemplateResponse('transfer_quota_monitor', 'admin', $parameters);
    }

    public function getSection(): string {
        return 'additional';
    }

    public function getPriority(): int {
        return 55;
    }
}
