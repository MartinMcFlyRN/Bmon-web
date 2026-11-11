<?php /** @var array $entries */ ?>
<h1>Audit log</h1>
<p class="muted">Last <?= count($entries) ?> entries: sign-ins, password changes and user management.</p>
<table class="table table-compact">
  <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead>
  <tbody>
  <?php foreach ($entries as $a): ?>
    <tr>
      <td class="nowrap"><?= e(fmt_dt($a['created_at'], 'Y-m-d H:i:s')) ?></td>
      <td><?= e($a['username']) ?></td>
      <td><span class="action action-<?= e(str_contains($a['action'], 'fail') || str_contains($a['action'], 'throttl') ? 'bad' : 'ok') ?>"><?= e($a['action']) ?></span></td>
      <td><?= e($a['detail']) ?></td>
      <td class="nowrap"><?= e($a['ip']) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
