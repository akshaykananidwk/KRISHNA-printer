<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;

/**
 * Unauthenticated liveness endpoint for load balancers and uptime monitors.
 *
 * Deliberately minimal: it reports whether the application can serve requests
 * and reach its database, and nothing else. Version numbers, printer counts and
 * queue depths are not exposed here — an unauthenticated endpoint should not
 * describe the estate to whoever asks.
 */
final class HealthController extends Controller
{
    public function __construct(private Database $db)
    {
    }

    /** GET /health */
    public function check(Request $request): Response
    {
        $healthy = true;
        $checks = [];

        try {
            $healthy = (int) $this->db->scalar('SELECT 1') === 1;
            $checks['database'] = $healthy ? 'ok' : 'unexpected result';
        } catch (\Throwable) {
            $healthy = false;
            $checks['database'] = 'unreachable';
        }

        $maintenanceFile = (string) Config::get('app.maintenance_file', '');
        $inMaintenance = $maintenanceFile !== '' && is_file($maintenanceFile);
        $checks['maintenance'] = $inMaintenance ? 'active' : 'inactive';

        return $this->json([
            'status' => $healthy ? ($inMaintenance ? 'maintenance' : 'ok') : 'degraded',
            'checks' => $checks,
            'time' => gmdate('c'),
        ], $healthy ? 200 : 503);
    }
}
