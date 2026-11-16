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
    <span class="mono"><?= e($ports['tool']) ?> -tulpn</span>: <?= count($rows) ?> listening socket<?= count($rows) === 1 ? '' : 's' ?>, <?= count(AgentPorts::byPort($rows)) ?> port<?= count(AgentPorts::byPort($rows)) === 1 ? '' : 's' ?>, <?= count(AgentPorts::byService($rows)) ?> service<?= count(AgentPorts::byService($rows)) === 1 ? '' : 's' ?>.</p>
  <?php if ($rows):
      $badge = static fn (string $k): string => '<span class="badge ' . e($scope[$k][1]) . '">' . e($scope[$k][0]) . '</span>';
      $procs = static function (array $procs): string {
          $out = [];
          foreach ($procs as $p) {
              $out[] = $p['process'] === '' ? '<span class="muted">—</span>'
                  : e($p['process']) . ($p['pid'] !== '' ? ' <span class="muted">' . e($p['pid']) . '</span>' : '');
          }
          return implode('<br>', $out);
      };
      $protos = static fn (array $p): string => e(strtoupper(implode('+', $p)));
  ?>
  <button type="button" class="btn ports-toggle" data-ports-toggle aria-expanded="false">
    <span class="when-closed">Show results</span><span class="when-open">Hide results</span>
  </button>
  <div class="ports-results">
  <nav class="segmented ports-views" aria-label="Group the listening ports">
    <button type="button" class="seg" data-ports-show="port">By port</button>
    <button type="button" class="seg" data-ports-show="service">By service</button>
    <button type="button" class="seg" data-ports-show="socket">All sockets</button>
  </nav>
  <table class="table table-compact ports-table" data-ports-view="port">
    <thead><tr><th class="num">Port</th><th>Proto</th><th>Usual service</th><th>Process <span class="muted">PID</span></th><th>Addresses</th><th>Reachable from</th></tr></thead>
    <tbody>
    <?php foreach (AgentPorts::byPort($rows) as $g): ?>
      <tr>
        <td class="num mono strong"><?= e($g['port']) ?></td>
        <td class="mono"><?= $protos($g['protos']) ?></td>
        <td class="muted"><?= e(implode(', ', $g['services'])) ?></td>
        <td class="mono"><?= $procs($g['procs']) ?></td>
        <td class="mono small"><?= implode('<br>', array_map('e', $g['addrs'])) ?></td>
        <td><?= $badge($g['scope']) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <table class="table table-compact ports-table" data-ports-view="service">
    <thead><tr><th>Service <span class="muted">(process)</span></th><th>PID</th><th>Ports</th><th>Usual service</th><th>Addresses</th><th>Reachable from</th></tr></thead>
    <tbody>
    <?php foreach (AgentPorts::byService($rows) as $g): ?>
      <tr>
        <td class="mono strong"><?= $g['process'] !== '' ? e($g['process']) : '<span class="muted">— unknown process</span>' ?></td>
        <td class="mono muted"><?= e(implode(', ', $g['pids'])) ?></td>
        <td class="mono"><?= implode(', ', array_map(static fn ($p) => '<span class="strong">' . e($p['port']) . '</span>/' . e(strtolower(implode('+', $p['protos']))), $g['ports'])) ?></td>
        <td class="muted"><?= e(implode(', ', $g['services'])) ?></td>
        <td class="mono small"><?= implode('<br>', array_map('e', $g['addrs'])) ?></td>
        <td><?= $badge($g['scope']) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <table class="table table-compact ports-table" data-ports-view="socket">
    <thead><tr><th class="num">Port</th><th>Proto</th><th>Address</th><th>Reachable from</th><th>Process</th><th class="num">PID</th><th>Usual service</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="num mono strong"><?= e($r['port']) ?></td>
        <td class="mono"><?= e(strtoupper($r['proto'])) ?></td>
        <td class="mono"><?= e($r['addr']) ?></td>
        <td><?= $badge($r['scope']) ?></td>
        <td class="mono"><?= $r['process'] !== '' ? e($r['process']) : '<span class="muted">—</span>' ?></td>
        <td class="num mono muted"><?= e($r['pid']) ?></td>
        <td class="muted"><?= e($r['service']) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
  <?php endif ?>
<?php endif ?>
