<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\Overview;
use Bmon\Web\View;

final class DashboardController
{
    /** Maximum rows per list; the rest is summarised as "and N more". */
    private const LIST_LIMIT = 100;

    public function index(): string
    {
        $vars = [
            'title' => 'Dashboard',
            'nav' => 'dashboard',
            'stats' => null,
            'offlineHosts' => [],
            'failingHosts' => [],
            'failingServices' => [],
            'limit' => self::LIST_LIMIT,
            'collectorError' => null,
        ];
        try {
            $limit = self::LIST_LIMIT + 1;  // one extra row tells whether the list was cut
            $vars['stats'] = Overview::stats();
            $vars['offlineHosts'] = Overview::offlineHosts($limit);
            $vars['failingHosts'] = Overview::failingHosts($limit);
            $vars['failingServices'] = Overview::failingServices($limit);
        } catch (\PDOException $e) {
            error_log('bmon-web: collector database: ' . $e->getMessage());
            $vars['collectorError'] = 'The collector database is not reachable. Check collector_db in the configuration '
                . 'and that the web database user has SELECT on it.';
        }
        return View::render('dashboard', $vars);
    }
}
