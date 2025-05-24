/**
 * @copyright Copyright (c) 2025 Bruce Matrix <bruce90matrix@gmail.com>
 * @license AGPL-3.0-or-later
 *
 * @fileoverview JavaScript for the Transfer Quota Monitor admin settings
 */

/* global OC, OCA, $ */

(function(OC, OCA, $, _) {
    'use strict';

    OCA.TransferQuotaMonitor = OCA.TransferQuotaMonitor || {};

    OCA.TransferQuotaMonitor.Admin = {
        initialize: function() {
            // Save thresholds
            $('#warning_threshold, #critical_threshold').on('change', this._saveThresholds);
            
            // Initial load
            this.loadUserQuotas();
        },

        _saveThresholds: function() {
            var warning = $('#warning_threshold').val();
            var critical = $('#critical_threshold').val();
            
            $('#quota_loading').removeClass('hidden');
            
            $.ajax({
                url: OC.generateUrl('/apps/transfer_quota_monitor/admin/thresholds'),
                type: 'POST',
                data: {
                    warning: warning,
                    critical: critical
                },
                success: function(response) {
                    $('#quota_msg').text(t('transfer_quota_monitor', 'Thresholds saved successfully')).removeClass('hidden');
                    setTimeout(function() {
                        $('#quota_msg').addClass('hidden');
                    }, 3000);
                },
                error: function(xhr) {
                    OC.Notification.showTemporary(t('transfer_quota_monitor', 'Error saving thresholds'));
                },
                complete: function() {
                    $('#quota_loading').addClass('hidden');
                }
            });
        },

        // Save user quota
        _saveUserQuota: function(userId, quota) {
            $('#quota_loading').removeClass('hidden');
            
            $.ajax({
                url: OC.generateUrl('/apps/transfer_quota_monitor/admin/quota'),
                type: 'POST',
                data: {
                    userId: userId,
                    quota: quota
                },
                success: function(response) {
                    $('#quota_msg').text(t('transfer_quota_monitor', 'Quota saved successfully')).removeClass('hidden');
                    setTimeout(function() {
                        $('#quota_msg').addClass('hidden');
                    }, 3000);
                },
                error: function(xhr) {
                    OC.Notification.showTemporary(t('transfer_quota_monitor', 'Error saving quota'));
                },
                complete: function() {
                    $('#quota_loading').addClass('hidden');
                }
            });
        },

        // Load user quotas
        loadUserQuotas: function() {
            var self = this;
            $('#quota_loading').removeClass('hidden');
            
            $.ajax({
                url: OC.generateUrl('/apps/transfer_quota_monitor/admin/quotas'),
                type: 'GET',
                success: function(response) {
                    var tbody = $('#transfer_quota_limits tbody');
                    tbody.empty();
                    
                    // Set threshold inputs
                    $('#warning_threshold').val(response.warning_threshold);
                    $('#critical_threshold').val(response.critical_threshold);
                    
                    response.quotas.forEach(function(quota) {
                        var tr = $('<tr>');
                        tr.append($('<td>').text(quota.displayName));
                        
                        // Calculate GB from bytes and round to nearest whole number
                        var quotaGB = Math.round(quota.limit / (1024*1024*1024));
                        
                        tr.append($('<td>').append(
                            $('<input type="number" min="0" step="1">').val(quotaGB).data('user-id', quota.userId).on('change', function() {
                                self._saveUserQuota(quota.userId, $(this).val());
                            })
                        ));
                        
                        // Calculate GB from bytes and round to nearest whole number
                        var usageGB = Math.round(quota.usage / (1024*1024*1024));
                        tr.append($('<td>').text(usageGB));
                        
                        tr.append($('<td>').text(quota.lastReset));
                        tr.append($('<td>').append(
                            $('<button class="icon-history">').attr('title', t('transfer_quota_monitor', 'Reset Usage')).click(function() {
                                self._resetUserQuota(quota.userId);
                            })
                        ));
                        tbody.append(tr);
                    });
                    
                    // We've removed the Process Downloads button from the template, so the click handler code below is no longer needed
                    // Process Downloads button handler code was previously here
                    
                    // Add a Reset button to each user row
                    $('.quota-user-item').each(function() {
                        var $row = $(this);
                        var userId = $row.data('user-id');
                        
                        // Only add the reset button, remove the process download button code
                        $row.find('.quota-user-actions').append(
                            '<a href="#" class="icon icon-history reset-user-quota" ' +
                            'data-user-id="' + userId + '" ' +
                            'title="' + t('transfer_quota_monitor', 'Reset quota usage') + '"></a>'
                        );
                    });

                    // Handle click on reset quota
                    $('#app-content').on('click', '.reset-user-quota', function(e) {
                        e.preventDefault();
                        var $button = $(this);
                        var userId = $button.data('user-id');
                        $button.addClass('loading');

                        $.ajax({
                            method: 'POST',
                            url: OC.generateUrl('/apps/transfer_quota_monitor/admin/reset'),
                            data: {
                                userId: userId
                            },
                            success: function(response) {
                                OC.Notification.showTemporary(response.message);
                                self.loadUserQuotas();
                            },
                            error: function(xhr) {
                                var msg = t('transfer_quota_monitor', 'Failed to reset quota');
                                if (xhr.responseJSON && xhr.responseJSON.message) {
                                    msg += ': ' + xhr.responseJSON.message;
                                }
                                OC.Notification.showTemporary(msg);
                            },
                            complete: function() {
                                $button.removeClass('loading');
                            }
                        });
                    });
                },
                error: function(xhr) {
                    OC.Notification.showTemporary(t('transfer_quota_monitor', 'Error loading quotas'));
                },
                complete: function() {
                    $('#quota_loading').addClass('hidden');
                }
            });
        },

        // Reset user quota
        _resetUserQuota: function(userId) {
            var self = this;
            if (!confirm(t('transfer_quota_monitor', 'Are you sure you want to reset this user\'s transfer usage?'))) {
                return;
            }

            $('#quota_loading').removeClass('hidden');
            
            $.ajax({
                url: OC.generateUrl('/apps/transfer_quota_monitor/admin/reset'),
                type: 'POST',
                data: {
                    userId: userId
                },
                success: function(response) {
                    self.loadUserQuotas();
                    $('#quota_msg').text(t('transfer_quota_monitor', 'Usage reset successfully')).removeClass('hidden');
                    setTimeout(function() {
                        $('#quota_msg').addClass('hidden');
                    }, 3000);
                },
                error: function(xhr) {
                    OC.Notification.showTemporary(t('transfer_quota_monitor', 'Error resetting usage'));
                },
                complete: function() {
                    $('#quota_loading').addClass('hidden');
                }
            });
        }
    };

})(OC, OCA, $, _);

// Only initialize when the document is fully loaded
document.addEventListener('DOMContentLoaded', function() {
    OCA.TransferQuotaMonitor.Admin.initialize();
});
