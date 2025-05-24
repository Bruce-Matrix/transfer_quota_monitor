<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Notification;

use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\IURLGenerator;

class Notifier implements INotifier {
    private $l10nFactory;
    private $urlGenerator;
    
    // Track notifications to prevent duplicates
    private static $sentNotifications = [];

    public function __construct(IFactory $l10nFactory, IURLGenerator $urlGenerator) {
        $this->l10nFactory = $l10nFactory;
        $this->urlGenerator = $urlGenerator;
    }

    public function getID(): string {
        return 'transfer_quota_monitor';
    }

    public function getName(): string {
        return $this->l10nFactory->get('transfer_quota_monitor')->t('Transfer Quota Monitor');
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== 'transfer_quota_monitor') {
            throw new \InvalidArgumentException('Application not supported');
        }
        
        // Prevent duplicate notifications by tracking what we've recently sent
        $notificationKey = $notification->getUser() . '-' . $notification->getSubject();
        if (isset(self::$sentNotifications[$notificationKey])) {
            // Skip duplicates of the same type that were recently processed
            $timeSinceLastNotification = time() - self::$sentNotifications[$notificationKey];
            if ($timeSinceLastNotification < 300) { // 5 minutes
                throw new \InvalidArgumentException('Duplicate notification skipped');
            }
        }
        
        // Mark this notification as sent
        self::$sentNotifications[$notificationKey] = time();
        
        // Limit the cache size
        if (count(self::$sentNotifications) > 100) {
            self::$sentNotifications = array_slice(self::$sentNotifications, -50, null, true);
        }

        // Get the l10n for the user's language
        $l = $this->l10nFactory->get('transfer_quota_monitor', $languageCode);

        // Make sure percentage is an integer
        $parameters = $notification->getSubjectParameters();
        if (isset($parameters['percent'])) {
            // Round to integer to avoid formatting issues
            $parameters['percent'] = (int)round($parameters['percent']);
        }

        // Handle the known subject
        if ($notification->getSubject() === 'warning_threshold_reached') {
            $percentage = $parameters['percent'];
            $threshold = $parameters['threshold'];

            $notification->setParsedSubject(
                $l->t('You have reached %d%% of your monthly data transfer limit', [
                    $percentage
                ])
            );

            $notification->setRichSubject(
                $l->t('You have reached **%d%%** of your monthly data transfer limit', [
                    $percentage
                ])
            );

            $notification->setParsedMessage(
                $l->t('You may experience service limitations if you exceed your monthly transfer quota. Please consider reducing your data transfers until the quota resets next month.')
            );

            $filesUrl = $this->urlGenerator->linkToRouteAbsolute('files.view.index');
            $notification->setLink($filesUrl);

        } elseif ($notification->getSubject() === 'critical_threshold_reached') {
            $percentage = $parameters['percent'];
            $threshold = $parameters['threshold'];

            $notification->setParsedSubject(
                $l->t('CRITICAL: You have reached %d%% of your monthly data transfer limit', [
                    $percentage
                ])
            );

            $notification->setRichSubject(
                $l->t('**CRITICAL**: You have reached **%d%%** of your monthly data transfer limit', [
                    $percentage
                ])
            );

            $notification->setParsedMessage(
                $l->t('You are close to exceeding your monthly transfer quota. Once you reach 100%%, your ability to upload and download files may be severely restricted until the quota resets next month.')
            );

            $filesUrl = $this->urlGenerator->linkToRouteAbsolute('files.view.index');
            $notification->setLink($filesUrl);

        } else {
            throw new \InvalidArgumentException('Subject not supported: ' . $notification->getSubject());
        }

        return $notification;
    }
}
