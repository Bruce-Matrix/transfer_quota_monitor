<?php
/** @var $l \OCP\IL10N */
/** @var $_ array */

// Properly include JavaScript and CSS
script('transfer_quota_monitor', 'admin');
style('transfer_quota_monitor', 'admin');

// Add DOCTYPE to fix quirks mode
?>
<!DOCTYPE html>
<div id="transfer_quota_settings" class="section">
    <h2><?php p($l->t('Transfer Quota Settings')); ?></h2>
    
    <div class="quota-warning-thresholds">
        <h3><?php p($l->t('Warning Thresholds')); ?></h3>
        <p>
            <label for="warning_threshold">
                <?php p($l->t('First warning at %s%%', '')); ?>
            </label>
            <input type="number" 
                   id="warning_threshold"
                   name="warning_threshold"
                   min="1"
                   max="100"
                   value="<?php p($_['warning_threshold']); ?>" />
        </p>
        <p>
            <label for="critical_threshold">
                <?php p($l->t('Critical warning at %s%%', '')); ?>
            </label>
            <input type="number"
                   id="critical_threshold"
                   name="critical_threshold"
                   min="1"
                   max="100"
                   value="<?php p($_['critical_threshold']); ?>" />
        </p>
    </div>

    <div class="quota-user-limits">
        <h3><?php p($l->t('User Transfer Limits')); ?></h3>
        <p class="settings-hint">
            <?php p($l->t('Set monthly data transfer limits for users. Users will receive notifications when they reach the warning thresholds.')); ?>
        </p>
        
        <table id="transfer_quota_limits" class="grid">
            <thead>
                <tr>
                    <th><?php p($l->t('User')); ?></th>
                    <th><?php p($l->t('Monthly Limit (GB)')); ?></th>
                    <th><?php p($l->t('Current Usage (GB)')); ?></th>
                    <th><?php p($l->t('Last Reset')); ?></th>
                    <th><?php p($l->t('Actions')); ?></th>
                </tr>
            </thead>
            <tbody>
                <!-- Populated via JavaScript -->
            </tbody>
        </table>
    </div>
</div>

<div id="quota_loading" class="icon-loading-small hidden"></div>
<span id="quota_msg" class="msg success hidden"></span>
