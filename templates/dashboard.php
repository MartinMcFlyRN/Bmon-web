<?php
/**
 * @var array|null $stats @var string|null $collectorError @var int $limit
 * @var array $offlineHosts @var array $failingHosts @var array $failingServices
 */
use Bmon\Web\Monit;

/** Prints "and N more" when a list has been cut at $limit rows. */
$more = static function (array $rows, int $total) use ($limit): string {
    return count($rows) > $limit
        ? '<p class="list-more">… and ' . e($total - $limit) . ' more · <a href="' . e(url('/status')) . '">see Status</a></p>'
        : '';
};
?>
<div class="page-head">
  <h1>Dashboard</h1>
  <?= autorefresh_control() ?>
</div>

<?php if ($collectorError !== null): ?>
  <div class="alert alert-error"><?= e($collectorError) ?></div>
<?php else: ?>
  <?php
    $hostsOk = (int) $stats['hosts'] - (int) $stats['hosts_offline'] - (int) $stats['hosts_failing'];
    $servicesOk = (int) $stats['services'] - (int) $stats['services_failed'];
  ?>
  <div class="dash-cols">
    <div class="dash-col">
      <section class="tile">
        <h2>Hosts</h2>
        <p class="tile-value"><?= e($stats['hosts']) ?></p>
        <ul class="tile-breakdown">
          <li><span class="dot dot-ok"></span><?= e($hostsOk) ?> OK</li>
          <li><span class="dot dot-fail"></span><?= e($stats['hosts_failing']) ?> with failing services</li>
          <li><span class="dot dot-off"></span><?= e($stats['hosts_offline']) ?> offline</li>
        </ul>
      </section>

      <?php if ($offlineHosts): ?>
      <section class="panel">
        <h2><span class="dot dot-off"></span>Offline hosts</h2>
        <table class="table table-compact">
          <thead><tr><th>Host</th><th>Address</th><th>Offline for</th><th>Last report</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($offlineHosts, 0, $limit) as $h): ?>
            <tr>
              <td><a class="strong" href="<?= e(url('/status/' . $h['id'])) ?>"><?= e($h['hostname']) ?></a></td>
              <td class="mono"><?= e($h['remote_addr']) ?></td>
              <td class="nowrap"><?= e(since($h['offline_since'])) ?></td>
              <td class="nowrap muted"><?= e(fmt_dt($h['last_seen'])) ?></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
        <?= $more($offlineHosts, (int) $stats['hosts_offline']) ?>
      </section>
      <?php endif ?>

      <?php if ($failingHosts): ?>
      <section class="panel">
        <h2><span class="dot dot-fail"></span>Hosts with failing services</h2>
        <table class="table table-compact">
          <thead><tr><th>Host</th><th>Address</th><th>Failing</th><th>Last report</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($failingHosts, 0, $limit) as $h): ?>
            <tr>
              <td><a class="strong" href="<?= e(url('/status/' . $h['id'])) ?>"><?= e($h['hostname']) ?></a></td>
              <td class="mono"><?= e($h['remote_addr']) ?></td>
              <td class="nowrap"><span class="count-fail"><?= e($h['services_failed']) ?></span> / <?= e($h['services_total']) ?></td>
              <td class="nowrap muted"><?= e(since($h['last_seen'])) ?> ago</td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
        <?= $more($failingHosts, (int) $stats['hosts_failing']) ?>
      </section>
      <?php endif ?>
    </div>

    <div class="dash-col">
      <section class="tile">
        <h2>Services</h2>
        <p class="tile-value"><?= e($stats['services']) ?></p>
        <ul class="tile-breakdown">
          <li><span class="dot dot-ok"></span><?= e($servicesOk) ?> OK</li>
          <li><span class="dot dot-fail"></span><?= e($stats['services_failed']) ?> failing</li>
          <?php if ((int) $stats['services_offline'] > 0): ?>
            <li><span class="dot dot-off"></span><?= e($stats['services_offline']) ?> on offline hosts (not counted)</li>
          <?php endif ?>
        </ul>
      </section>

      <?php if ($failingServices): ?>
      <section class="panel">
        <h2><span class="dot dot-fail"></span>Failing services</h2>
        <table class="table table-compact">
          <thead><tr><th>Host</th><th>Service</th><th>Problem</th><th>Since</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($failingServices, 0, $limit) as $s): ?>
            <tr>
              <td class="nowrap"><a class="strong" href="<?= e(url('/status/' . $s['host_id'])) ?>"><?= e($s['hostname']) ?></a></td>
              <td>
                <?= e($s['name']) ?>
                <span class="muted small"><?= e(Monit::serviceType((int) $s['type'])) ?></span>
              </td>
              <td class="cell-trunc">
                <span class="fail-text"><?= e(implode(', ', Monit::failures((int) $s['status']))) ?></span>
                <?php if (!empty($s['message'])): ?>
                  <div class="muted small event-msg" title="<?= e($s['message']) ?>"><?= e($s['message']) ?></div>
                <?php endif ?>
              </td>
              <td class="nowrap"><?= e(since($s['status_changed_at'])) ?></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
        <?= $more($failingServices, (int) $stats['services_failed']) ?>
      </section>
      <?php endif ?>
    </div>
  </div>
<?php endif ?>
