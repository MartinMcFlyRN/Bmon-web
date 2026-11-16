<?php
/** Listening ports of an agent (agent page, refreshed every 5 s). @var array|null $ports */
use Bmon\Web\AgentPorts;

$pending = AgentPorts::pending($ports);
$rows = $ports !== null && $ports['output'] !== null ? AgentPorts::parse((string) $ports['output']) : [];
$scope = ['all' => ['All interfaces', 'badge-warn'], 'local' => ['This host only', 'badge-ok'], 'one' => ['One address', 'badge-off']];
?>
<?php if ($pending): ?>
  <p class="muted"><span class="spinner" aria-hidden="true"></span> Waiting for the agent — requested <?= e(since($ports['requested_at'])) ?> ago by <?= e($ports['requested_by']) ?>.
    It answers within seconds when reachable, otherwise at its next auto-update run.</p>
<?php endif ?>
<?php if ($ports === null || $ports['scanned_at'] === null): ?>
  <?php if (!$pending): ?><p class="muted">Never scanned. The scan runs only when you ask for it.</p><?php endif ?>
<?php elseif ($ports['tool'] === 'none'): ?>
  <p class="text-fail">The agent has neither <span class="mono">ss</span> nor <span class="mono">netstat</span>: install iproute2 (or net-tools).</p>
<?php else: ?>
  <p class="hint">Scanned <?= e(fmt_dt($ports['scanned_at'], 'Y-m-d H:i:s')) ?> (<?= e(since($ports['scanned_at'])) ?> ago) with
    <span class="mono"><?= e($ports['tool']) ?> -tulpn</span>: <?= count($rows) ?> listening socket<?= count($rows) === 1 ? '' : 's' ?>.</p>
  <?php if ($rows): ?>
  <table class="table table-compact ports-table">
    <thead><tr><th class="num">Port</th><th>Proto</th><th>Address</th><th>Reachable from</th><th>Process</th><th class="num">PID</th><th>Usual service</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="num mono strong"><?= e($r['port']) ?></td>
        <td class="mono"><?= e(strtoupper($r['proto'])) ?></td>
        <td class="mono"><?= e($r['addr']) ?></td>
        <td><span class="badge <?= e($scope[$r['scope']][1]) ?>"><?= e($scope[$r['scope']][0]) ?></span></td>
        <td class="mono"><?= $r['process'] !== '' ? e($r['process']) : '<span class="muted">—</span>' ?></td>
        <td class="num mono muted"><?= e($r['pid']) ?></td>
        <td class="muted"><?= e($r['service']) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
<?php endif ?>
