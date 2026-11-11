<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\Db;
use Bmon\Web\View;

final class DashboardController
{
    public function index(): string
    {
        $stats = null;
        $collectorError = null;
        try {
            $stats = Db::one(
                'SELECT COUNT(*) AS hosts,
                        COALESCE(SUM(offline_since IS NOT NULL), 0) AS hosts_offline,
                        COALESCE(SUM(offline_since IS NULL AND services_failed > 0), 0) AS hosts_failing,
                        COALESCE(SUM(services_total), 0) AS services,
                        COALESCE(SUM(services_failed), 0) AS services_failed
                   FROM ' . Db::collector('hosts')
            );
        } catch (\PDOException $e) {
            error_log('bmon-web: collector database: ' . $e->getMessage());
            $collectorError = 'The collector database is not reachable. Check collector_db in the configuration '
                . 'and that the web database user has SELECT on it.';
        }
        return View::render('dashboard', [
            'title' => 'Dashboard',
            'nav' => 'dashboard',
            'stats' => $stats,
            'collectorError' => $collectorError,
        ]);
    }
}
