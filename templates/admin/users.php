<?php
/** @var array $users @var array $user */
use Bmon\Web\Users;
?>
<div class="page-head">
  <h1>Users</h1>
  <a class="btn btn-primary" href="<?= e(url('/admin/users/new')) ?>">New user</a>
</div>

<table class="table">
  <thead>
    <tr><th>Username</th><th>Full name</th><th>E-mail</th><th>Role</th><th>Status</th><th>Last sign-in</th></tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td>
        <a href="<?= e(url('/admin/users/' . $u['id'])) ?>"><?= e($u['username']) ?></a>
        <?php if ((int) $u['id'] === (int) $user['id']): ?><span class="muted">(you)</span><?php endif ?>
      </td>
      <td><?= e($u['full_name']) ?></td>
      <td><?= e($u['email']) ?></td>
      <td><span class="badge badge-<?= e($u['role']) ?>"><?= e(Users::ROLES[$u['role']] ?? $u['role']) ?></span></td>
      <td><?= (int) $u['is_active'] ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-off">Disabled</span>' ?></td>
      <td><?= e(fmt_dt($u['last_login_at'])) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<p class="muted"><?= count($users) ?> user<?= count($users) === 1 ? '' : 's' ?>.
  Administrators manage users and settings; users can view the monitoring pages.</p>
