<?php
/** Auto-update status of an agent (agent page, refreshed every 5 s by app.js).
 * @var array $update @var int $enabledCount */
$rel = $update['release'];
$st = $update['state'];
$installed = (string) ($st['installed_version'] ?? '');
$short = static fn (string $v): string => $v === '' ? '—' : substr($v, 0, 12);
?>
<dl class="kv">
  <dt>Published</dt>
  <dd><?php if ($rel === null): ?><span class="muted">Nothing published yet.</span>
    <?php else: ?><span class="mono"><?= e($short($rel['version'])) ?></span> · <?= e($rel['checks']) ?> checks · <?= e(fmt_dt($rel['created_at'])) ?> · <?= e($rel['created_by']) ?>
    <?php endif ?>
    <?php if ($update['changed'] && $enabledCount): ?> <span class="badge badge-warn">Changes not published</span><?php endif ?></dd>
  <dt>On the agent</dt>
  <dd><?php if ($st === null || $st['last_poll_at'] === null): ?><span class="muted">The agent has not asked for updates yet.</span>
    <?php else: ?>
      <span class="mono"><?= e($short($installed)) ?></span>
      <?php if ($rel !== null && $installed === $rel['version']): ?><span class="badge badge-ok">Up to date</span>
      <?php elseif ($rel !== null): ?><span class="badge badge-warn">Update pending</span><?php endif ?>
      <span class="muted small">· last asked <?= e(since($st['last_poll_at'])) ?> ago from <?= e($st['last_poll_ip']) ?></span>
    <?php endif ?></dd>
  <?php if ($st !== null && $st['last_result_at'] !== null): ?>
    <dt>Last install</dt>
    <dd><?= match ($st['last_status']) {
          'installed' => '<span class="badge badge-ok">Installed</span>',
          'rolled_back' => '<span class="badge badge-fail">Rolled back</span>',
          default => '<span class="badge badge-fail">Failed</span>',
        } ?> <span class="mono small"><?= e($short($st['installed_version'] ?? '')) ?></span>
      <span class="muted small">· <?= e(fmt_dt($st['last_result_at'], 'Y-m-d H:i:s')) ?></span>
      <?php if (trim((string) $st['last_message']) !== ''): ?><pre class="update-output"><?= e($st['last_message']) ?></pre><?php endif ?></dd>
  <?php endif ?>
  <?php if ($st !== null && $st['last_auth_error'] !== ''): ?>
    <dt>Refused</dt>
    <dd class="text-fail"><?= e($st['last_auth_error']) ?> <span class="muted small">· <?= e(fmt_dt($st['last_auth_error_at'], 'Y-m-d H:i:s')) ?></span></dd>
  <?php endif ?>
</dl>
