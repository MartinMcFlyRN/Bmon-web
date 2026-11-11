<?php
/**
 * @var array $hosts @var array|null $counts @var string $q @var string $filter
 * @var string $sort @var string $dir @var int $page @var int $pages @var int $total
 * @var string|null $collectorError
 */

$th = static fn (string $key, string $label, string $class = ''): string
    => sort_th('/status', $key, $label, $sort, $dir, $class);

/** Coloured bar for a percentage (green < 70, amber < 90, red). */
$bar = static function (float|int|string|null $v): string {
    if ($v === null) {
        return '<span class="muted">—</span>';
    }
    $v = (float) $v;
    $level = $v >= 90 ? 'fail' : ($v >= 70 ? 'warn' : 'ok');
    return '<span class="meter" title="' . e(number_format($v, 1)) . '%"><span class="meter-fill meter-' . $level
        . ' w' . e(max(1, min(20, (int) round($v / 5)))) . '"></span></span>'
        . '<span class="meter-val">' . e(number_format($v, 1)) . '%</span>';
};
?>
<div class="page-head">
  <h1>Status</h1>
  <?= autorefresh_control() ?>
</div>

<?php if ($collectorError !== null): ?>
  <div class="alert alert-error"><?= e($collectorError) ?></div>
<?php else: ?>

<div class="toolbar">
  <nav class="segmented" aria-label="Filter by state">
    <?php foreach (['all' => 'All', 'ok' => 'OK', 'failing' => 'Failing', 'offline' => 'Offline'] as $key => $label): ?>
      <a href="<?= e(url('/status') . query_with(['filter' => $key === 'all' ? null : $key, 'page' => null])) ?>"
         class="seg seg-<?= e($key) ?><?= $filter === $key ? ' active' : '' ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>>
        <?= e($label) ?> <span class="seg-count"><?= e($counts[$key]) ?></span>
      </a>
    <?php endforeach ?>
  </nav>
  <form method="get" action="<?= e(url('/status')) ?>" class="search" role="search">
    <?php foreach (['filter', 'sort', 'dir'] as $keep): if (!empty($_GET[$keep]) && is_string($_GET[$keep])): ?>
      <input type="hidden" name="<?= e($keep) ?>" value="<?= e($_GET[$keep]) ?>">
    <?php endif; endforeach ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search host or address" aria-label="Search host or address">
    <button type="submit" class="btn">Search</button>
    <?php if ($q !== ''): ?><a class="btn" href="<?= e(url('/status') . query_with(['q' => null, 'page' => null])) ?>">Clear</a><?php endif ?>
  </form>
</div>

<?php if (!$hosts): ?>
  <div class="card muted">No hosts<?= $q !== '' ? ' match “' . e($q) . '”' : '' ?>.</div>
<?php else: ?>
<table class="table table-status">
  <thead>
    <tr>
      <?= $th('state', 'Status') ?>
      <?= $th('host', 'Host') ?>
      <?= $th('cpu', 'CPU') ?>
      <?= $th('mem', 'Memory') ?>
      <?= $th('load', 'Load') ?>
      <?= $th('services', 'Services', 'num') ?>
      <?= $th('report', 'Last report') ?>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($hosts as $h):
      $offline = (int) $h['state'] === 2;
      $failing = (int) $h['state'] === 1; ?>
    <tr class="<?= $offline ? 'row-off' : '' ?>">
      <td class="nowrap">
        <?php if ($offline): ?>
          <span class="status status-off">Offline</span>
        <?php elseif ($failing): ?>
          <span class="status status-fail"><?= e($h['services_failed']) ?> failing</span>
        <?php else: ?>
          <span class="status status-ok">OK</span>
        <?php endif ?>
      </td>
      <td>
        <a class="strong" href="<?= e(url('/status/' . $h['id'])) ?>"><?= e($h['hostname']) ?></a>
        <?php $meta = implode(' · ', array_filter([
            trim($h['os_name'] . ' ' . $h['os_release']),
            $h['monit_version'] !== '' ? 'Monit ' . $h['monit_version'] : '',
        ])); ?>
        <div class="muted small">
          <?= e($h['remote_addr']) ?><span class="hide-sm"><?= $h['remote_addr'] !== '' && $meta !== '' ? ' · ' : '' ?><?= e($meta) ?></span>
        </div>
      </td>
      <td class="nowrap"><?= $bar($h['cpu']) ?></td>
      <td class="nowrap"><?= $bar($h['mem']) ?></td>
      <td class="nowrap mono">
        <?php if ($h['load1'] !== null): ?>
          <span title="1, 5, 15 minutes<?= (int) $h['cpu_count'] > 0 ? ' · ' . e($h['cpu_count']) . ' CPU' : '' ?>">
            <?= e(number_format((float) $h['load1'], 2)) ?> <span class="muted"><?= e(number_format((float) $h['load5'], 2)) ?> <?= e(number_format((float) $h['load15'], 2)) ?></span>
          </span>
        <?php else: ?><span class="muted">—</span><?php endif ?>
      </td>
      <td class="nowrap num">
        <?php if ((int) $h['services_failed'] > 0 && !$offline): ?><span class="count-fail"><?= e($h['services_failed']) ?></span> / <?php endif ?><?= e($h['services_total']) ?>
      </td>
      <td class="nowrap<?= $offline ? '' : ' muted' ?>" title="<?= e(fmt_dt($h['last_seen'], 'Y-m-d H:i:s')) ?>">
        <?= e(since($h['last_seen'])) ?> ago
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pages">
  <?php if ($page > 1): ?><a class="btn" href="<?= e(url('/status') . query_with(['page' => $page - 1])) ?>">← Previous</a><?php endif ?>
  <span class="muted">Page <?= e($page) ?> of <?= e($pages) ?> · <?= e($total) ?> hosts</span>
  <?php if ($page < $pages): ?><a class="btn" href="<?= e(url('/status') . query_with(['page' => $page + 1])) ?>">Next →</a><?php endif ?>
</nav>
<?php else: ?>
<p class="muted small"><?= e($total) ?> host<?= $total === 1 ? '' : 's' ?>.</p>
<?php endif ?>
<?php endif ?>
<?php endif ?>
