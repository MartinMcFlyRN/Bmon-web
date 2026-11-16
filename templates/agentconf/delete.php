<?php /** Confirmation of a bulk deletion from the Agent list. @var array $hosts @var array $counts @var bool $allowed */
$offline = array_values(array_filter($hosts, static fn ($h) => $h['offline_since'] !== null));
$online = array_values(array_filter($hosts, static fn ($h) => $h['offline_since'] === null));
?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="<?= e(url('/agentconf')) ?>">Agent</a> /</p>
    <h1>Delete <?= count($offline) ?> agent<?= count($offline) === 1 ? '' : 's' ?></h1>
  </div>
</div>

<?php if (!$allowed): ?>
  <div class="alert alert-error">Bmon-web may not delete hosts in the collector database. As a database administrator run:
    <code><?= e(\Bmon\Web\HostDelete::grantHint()) ?></code></div>
<?php endif ?>

<?php if ($online): ?>
  <div class="alert alert-warn"><?= count($online) ?> selected agent<?= count($online) === 1 ? ' is' : 's are' ?> reporting again and will be left alone:
    <?= e(implode(', ', array_column($online, 'hostname'))) ?>.</div>
<?php endif ?>

<?php if (!$offline): ?>
  <div class="card muted">None of the selected agents is offline: nothing to delete. <a href="<?= e(url('/agentconf')) ?>">Back to the list</a></div>
<?php else: ?>
<div class="card">
  <p>These agents are deleted from Bmon-web and from the collector with everything about them: services, events, name and notes,
    inventory, AgentConf checks, published versions, auto-update state, port scans and download links. The audit log is kept.
    If one of them reports again, it appears as a new host.</p>
  <table class="table table-compact">
    <thead><tr><th>Agent</th><th>Address</th><th class="num">Services</th><th class="num">Bmon checks</th><th>Offline since</th><th>Last report</th></tr></thead>
    <tbody>
    <?php foreach ($offline as $h): ?>
      <tr>
        <td><a class="strong" href="<?= e(url('/agentconf/' . $h['id'])) ?>"><?= e($h['hostname']) ?></a>
          <div class="muted small"><?= e(trim($h['os_name'] . ' ' . $h['os_release'])) ?></div></td>
        <td class="mono"><?= e($h['remote_addr']) ?></td>
        <td class="num"><?= e($h['services']) ?></td>
        <td class="num"><?= e($counts[(int) $h['id']]['n'] ?? 0) ?></td>
        <td class="nowrap"><?= e(fmt_dt($h['offline_since'], 'Y-m-d H:i')) ?></td>
        <td class="nowrap muted"><?= e(since($h['last_seen'])) ?> ago</td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <form method="post" action="<?= e(url('/agentconf/delete')) ?>" class="form bulk-confirm">
    <?= csrf_field() ?>
    <?php foreach ($hosts as $h): ?><input type="hidden" name="ids[]" value="<?= e($h['id']) ?>"><?php endforeach ?>
    <label for="confirm-count">To confirm, type the number of agents to delete (<strong><?= count($offline) ?></strong>)</label>
    <input id="confirm-count" name="confirm_count" type="text" inputmode="numeric" autocomplete="off" required size="6">
    <div class="form-actions">
      <button type="submit" class="btn btn-danger"<?= $allowed ? '' : ' disabled' ?>>Delete <?= count($offline) ?> agent<?= count($offline) === 1 ? '' : 's' ?></button>
      <a class="btn" href="<?= e(url('/agentconf')) ?>">Cancel</a>
    </div>
  </form>
</div>
<?php endif ?>
