<?php
/** @var bool $isNew @var array $form @var array $errors @var array|null $target @var bool|null $isSelf */
use Bmon\Web\Users;

$err = static fn (string $f): string => !empty($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . e($f) . '-error"' : '';
$action = $isNew ? url('/admin/users') : url('/admin/users/' . $target['id']);
$isSelf ??= false;
?>
<div class="page-head">
  <h1><?= $isNew ? 'New user' : 'Edit user ' . e($target['username']) ?></h1>
  <a class="btn" href="<?= e(url('/admin/users')) ?>">Back to users</a>
</div>

<div class="grid-2">
  <form method="post" action="<?= e($action) ?>" class="card" autocomplete="off">
    <?= csrf_field() ?>
    <label for="username">Username</label>
    <input id="username" name="username" type="text" maxlength="64" required value="<?= e($form['username']) ?>"
           autocapitalize="none" spellcheck="false"<?= $err('username') ?>>
    <?php $field = 'username'; require __DIR__ . '/../partials/field_error.php' ?>

    <label for="full_name">Full name</label>
    <input id="full_name" name="full_name" type="text" maxlength="255" value="<?= e($form['full_name']) ?>"<?= $err('full_name') ?>>
    <?php $field = 'full_name'; require __DIR__ . '/../partials/field_error.php' ?>

    <label for="email">E-mail</label>
    <input id="email" name="email" type="email" maxlength="255" value="<?= e($form['email']) ?>"<?= $err('email') ?>>
    <?php $field = 'email'; require __DIR__ . '/../partials/field_error.php' ?>

    <label for="role">Role</label>
    <select id="role" name="role"<?= $err('role') ?>>
      <?php foreach (Users::ROLES as $value => $label): ?>
        <option value="<?= e($value) ?>"<?= $form['role'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach ?>
    </select>
    <?php $field = 'role'; require __DIR__ . '/../partials/field_error.php' ?>

    <label class="check">
      <input type="checkbox" name="is_active" value="1"<?= (int) $form['is_active'] ? ' checked' : '' ?>>
      Account active
    </label>

    <fieldset>
      <legend><?= $isNew ? 'Password' : 'Reset password' ?></legend>
      <?php if (!$isNew): ?><p class="hint">Leave empty to keep the current password. A reset signs the user out everywhere.</p><?php endif ?>
      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="new-password"<?= $isNew ? ' required' : '' ?><?= $err('password') ?>>
      <?php $field = 'password'; require __DIR__ . '/../partials/field_error.php' ?>
      <label for="password_confirm">Repeat password</label>
      <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password"<?= $isNew ? ' required' : '' ?><?= $err('password_confirm') ?>>
      <?php $field = 'password_confirm'; require __DIR__ . '/../partials/field_error.php' ?>
    </fieldset>

    <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create user' : 'Save' ?></button>
  </form>

  <?php if (!$isNew): ?>
  <aside class="card">
    <h2>Account</h2>
    <dl class="kv">
      <dt>Created</dt><dd><?= e(fmt_dt($target['created_at'])) ?></dd>
      <dt>Updated</dt><dd><?= e(fmt_dt($target['updated_at'])) ?></dd>
      <dt>Last sign-in</dt><dd><?= e(fmt_dt($target['last_login_at'])) ?> <?= $target['last_login_ip'] !== '' ? 'from ' . e($target['last_login_ip']) : '' ?></dd>
    </dl>
    <?php if (!$isSelf): ?>
      <form method="post" action="<?= e(url('/admin/users/' . $target['id'] . '/delete')) ?>"
            data-confirm="Delete user <?= e($target['username']) ?>? This cannot be undone.">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-danger">Delete user</button>
      </form>
    <?php else: ?>
      <p class="hint">This is your account: you cannot delete it, disable it or change its role.</p>
    <?php endif ?>
  </aside>
  <?php endif ?>
</div>
