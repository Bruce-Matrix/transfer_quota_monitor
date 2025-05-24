<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025 RedCloud
 * @license AGPL-3.0-or-later
 *
 * Create your routes in here. The name is the lowercase name of the controller
 * without the controller part, the stuff after the hash is the method.
 */

return [
    'routes' => [
        ['name' => 'admin#getQuotas', 'url' => '/admin/quotas', 'verb' => 'GET'],
        ['name' => 'admin#setQuota', 'url' => '/admin/quota', 'verb' => 'POST'],
        ['name' => 'admin#setThresholds', 'url' => '/admin/thresholds', 'verb' => 'POST'],
        ['name' => 'admin#resetUserUsage', 'url' => '/admin/reset', 'verb' => 'POST'],
        // Download tracking endpoints
        ['name' => 'download#stream_media', 'url' => '/api/stream/{fileId}', 'verb' => 'GET'],
    ]
];
