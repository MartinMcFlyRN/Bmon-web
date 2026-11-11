<?php
/** @var array $host @var array $services @var array $groups @var array $events @var string $sort @var string $dir
 * @var array $labels @var array $allLabels @var bool $canEdit */
use Bmon\Web\Monit;

$offline = $host['offline_since'] !== null;
$failed = array_filter($services, static fn ($s) => (int) $s['status'] !== 0);
$th = static fn (string $key, string $label, string $class = ''): string
    => sort_th('/status/' . $host['id'], $key, $label, $sort, $dir, $class);
?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="<?= e(url('/status')) ?>">Status</a> /</p>
    <h1 class="host-title">
      <?= e($host['hostname']) ?>
      <?php if ($offline): ?>
        <span class="status status-off">Offline for <?= e(since($host['offline_since'])) ?></span>
      <?php elseif ($failed): ?>
        <span class="status status-fail"><?= count($failed) ?> failing</span>
      <?php else: ?>
        <span class="status status-ok">OK</span>
      <?php endif ?>
    </h1>
  </div>
  <?= autorefresh_control() ?>
</div>

<?php if ($offline): ?>
  <div class="alert alert-info">No report from Monit since <?= e(fmt_dt($host['last_seen'], 'Y-m-d H:i:s')) ?>. The values below are from the last report and may be out of date.</div>
<?php endif ?>

<div class="grid-2">
  <div class="stack">
  <section class="card">
    <h2>Host</h2>
    <dl class="kv">
      <dt>Address</dt><dd class="mono"><?= e($host['remote_addr'] ?: '—') ?></dd>
      <dt>System</dt><dd><?= e(trim($host['os_name'] . ' ' . $host['os_release']) ?: '—') ?> <span class="muted"><?= e($host['machine']) ?></span></dd>
      <dt>Kernel</dt><dd class="small"><?= e($host['os_version'] ?: '—') ?></dd>
      <dt>CPUs</dt><dd><?= e($host['cpu_count'] ?: '—') ?></dd>
      <dt>Memory</dt><dd><?= e(fmt_bytes((int) $host['memory_kb'] * 1024)) ?> <span class="muted">· swap <?= e(fmt_bytes((int) $host['swap_kb'] * 1024)) ?></span></dd>
    </dl>
  </section>

  <section class="card" id="labels">
    <h2>Labels</h2>
    <?php if ($labels): ?>
      <ul class="label-list">
        <?php foreach ($labels as $l): ?>
          <li class="label-chip" title="<?= e($l['description']) ?>">
            <a href="<?= e(url('/labels/' . $l['id'])) ?>"><?= e($l['name']) ?></a>
            <?php if ($canEdit): ?>
              <form method="post" action="<?= e(url('/status/' . $host['id'] . '/labels/' . $l['id'] . '/delete')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="chip-remove" title="Remove label <?= e($l['name']) ?>" aria-label="Remove label <?= e($l['name']) ?>">×</button>
              </form>
            <?php endif ?>
          </li>
        <?php endforeach ?>
      </ul>
    <?php else: ?>
      <p class="muted">No labels on this host.</p>
    <?php endif ?>

    <?php if ($canEdit):
        $assigned = array_column($labels, 'id');
        $available = array_filter($allLabels, static fn ($l) => !in_array($l['id'], $assigned, true)); ?>
      <?php if ($available): ?>
        <form method="post" action="<?= e(url('/status/' . $host['id'] . '/labels')) ?>" class="inline-form">
          <?= csrf_field() ?>
          <select name="label_id" aria-label="Label to add" required>
            <option value="">Add a label…</option>
            <?php foreach ($available as $l): ?>
              <option value="<?= e($l['id']) ?>"><?= e($l['name']) ?><?= $l['description'] !== '' ? ' — ' . e(mb_strimwidth($l['description'], 0, 60, '…')) : '' ?></option>
            <?php endforeach ?>
          </select>
          <button type="submit" class="btn">Add</button>
        </form>
      <?php elseif (!$allLabels): ?>
        <p class="hint">No labels defined yet: <a href="<?= e(url('/labels/new')) ?>">create one</a>.</p>
      <?php else: ?>
        <p class="hint">All labels are already on this host. <a href="<?= e(url('/labels/new')) ?>">New label</a></p>
      <?php endif ?>
    <?php endif ?>
  </section>
  </div>
  <section class="card">
    <h2>Monit</h2>
    <dl class="kv">
      <dt>Version</dt><dd><?= e($host['monit_version']) ?></dd>
      <dt>Uptime</dt><dd><?= e(fmt_duration((int) $host['monit_uptime'])) ?></dd>
      <dt>Poll interval</dt><dd><?= e($host['poll_interval']) ?>s</dd>
      <dt>Last report</dt><dd><?= e(fmt_dt($host['last_seen'], 'Y-m-d H:i:s')) ?> <span class="muted">(<?= e(since($host['last_seen'])) ?> ago)</span></dd>
      <dt>First seen</dt><dd><?= e(fmt_dt($host['first_seen'])) ?></dd>
      <dt>httpd</dt><dd class="mono"><?= $host['httpd_port'] ? e(($host['httpd_address'] ?: '*') . ':' . $host['httpd_port'] . ((int) $host['httpd_ssl'] ? ' (SSL)' : '')) : '—' ?></dd>
      <dt>Control file</dt><dd class="mono small"><?= e($host['control_file'] ?: '—') ?></dd>
      <dt>Monit ID</dt><dd class="mono small"><?= e($host['monit_id']) ?></dd>
    </dl>
  </section>
</div>

<section class="panel">
  <h2>Services <span class="muted">(<?= count($services) ?>)</span></h2>
  <?php if (!$services): ?>
    <div class="card muted">No services reported.</div>
  <?php else: ?>
  <table class="table table-services">
    <thead><tr>
      <?= $th('status', 'Status') ?>
      <?= $th('service', 'Service') ?>
      <?= $th('type', 'Type / details') ?>
      <?= $th('since', 'Since') ?>
      <?= $th('checked', 'Checked') ?>
    </tr></thead>
    <tbody>
    <?php foreach ($services as $s):
        [$cls, $label] = Monit::serviceState($s);
        $summary = Monit::summary((int) $s['type'], $s['data']);
        $raw = Monit::flatten($s['data']); ?>
      <tr class="<?= $offline ? 'row-off' : '' ?>">
        <td class="svc-status"><span class="status status-<?= e($cls) ?>"><?= e($label) ?></span>
          <?php if ((int) $s['pending_action'] > 0): ?>
            <div class="muted small">pending: <?= e(Monit::ACTIONS[(int) $s['pending_action']] ?? $s['pending_action']) ?></div>
          <?php endif ?>
        </td>
        <td class="nowrap">
          <span class="strong"><?= e($s['name']) ?></span>
          <div class="muted small"><?= e(Monit::serviceType((int) $s['type'])) ?></div>
        </td>
        <td class="cell-trunc">
          <?php if ($summary): ?>
            <dl class="summary">
              <?php foreach ($summary as $k => $v): ?>
                <div><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd></div>
              <?php endforeach ?>
            </dl>
          <?php endif ?>
          <?php if ($raw): ?>
            <details class="raw">
              <summary>All values</summary>
              <table class="table-raw">
                <?php foreach ($raw as $k => $v): ?>
                  <tr><th><?= e($k) ?></th><td><?= e($v) ?></td></tr>
                <?php endforeach ?>
              </table>
            </details>
          <?php endif ?>
        </td>
        <td class="nowrap" title="<?= e(fmt_dt($s['status_changed_at'], 'Y-m-d H:i:s')) ?>"><?= e(since($s['status_changed_at'])) ?></td>
        <td class="nowrap muted" title="<?= e(fmt_dt($s['collected_at'], 'Y-m-d H:i:s')) ?>"><?= e(since($s['collected_at'])) ?> ago</td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</section>

<?php if ($groups): ?>
<section class="panel">
  <h2>Service groups</h2>
  <div class="groups">
    <?php foreach ($groups as $name => $members): ?>
      <div class="group"><span class="group-name"><?= e($name) ?></span>
        <?php foreach ($members as $m): ?><span class="chip"><?= e($m) ?></span><?php endforeach ?>
      </div>
    <?php endforeach ?>
  </div>
</section>
<?php endif ?>

<section class="panel">
  <h2>Recent events</h2>
  <?php if (!$events): ?>
    <div class="card muted">No events.</div>
  <?php else: ?>
  <table class="table table-compact">
    <thead><tr><th>Time</th><th>Service</th><th>Event</th><th>State</th><th>Message</th></tr></thead>
    <tbody>
    <?php foreach ($events as $ev):
        $state = (int) $ev['state'];
        $cls = $state === 1 ? 'fail' : ($state === 0 ? 'ok' : 'off'); ?>
      <tr>
        <td class="nowrap"><?= e(fmt_dt($ev['collected_at'], 'Y-m-d H:i:s')) ?></td>
        <td class="nowrap strong"><?= e($ev['service']) ?></td>
        <td class="nowrap"><?= e(Monit::eventName((int) $ev['event_id'])) ?></td>
        <td class="nowrap"><span class="status status-<?= e($cls) ?>"><?= e(Monit::EVENT_STATES[$state] ?? $state) ?></span></td>
        <td><?= e($ev['message']) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</section>
