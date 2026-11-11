<?php
/** @var array $form @var array $errors @var array $user */
use Bmon\Web\Users;

$err = static fn (string $f): string => !empty($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . e($f) . '-error"' : '';
?>
<h1>My account</h1>

<div class="grid-2">
  <form method="post" action="<?= e(url('/profile')) ?>" class="card">
    <h2>Details</h2>
    <?= csrf_field() ?>
    <dl class="kv">
      <dt>Username</dt><dd><?= e($user['username']) ?></dd>
      <dt>Role</dt><dd><span class="badge badge-<?= e($user['role']) ?>"><?= e(Users::ROLES[$user['role']] ?? $user['role']) ?></span></dd>
      <dt>Last sign-in</dt><dd><?= e(fmt_dt($user['last_login_at'])) ?> <?= $user['last_login_ip'] !== '' ? 'from ' . e($user['last_login_ip']) : '' ?></dd>
    </dl>
    <label for="full_name">Full name</label>
    <input id="full_name" name="full_name" type="text" maxlength="255" value="<?= e($form['full_name']) ?>" autocomplete="name"<?= $err('full_name') ?>>
    <?php $field = 'full_name'; require __DIR__ . '/partials/field_error.php' ?>
    <label for="email">E-mail</label>
    <input id="email" name="email" type="email" maxlength="255" value="<?= e($form['email']) ?>" autocomplete="email"<?= $err('email') ?>>
    <?php $field = 'email'; require __DIR__ . '/partials/field_error.php' ?>
    <button type="submit" class="btn btn-primary">Save</button>
  </form>

  <form method="post" action="<?= e(url('/profile/password')) ?>" class="card">
    <h2>Change password</h2>
    <?= csrf_field() ?>
    <input type="text" name="username" value="<?= e($user['username']) ?>" autocomplete="username" hidden>
    <label for="current_password">Current password</label>
    <input id="current_password" name="current_password" type="password" autocomplete="current-password" required<?= $err('current_password') ?>>
    <?php $field = 'current_password'; require __DIR__ . '/partials/field_error.php' ?>
    <label for="new_password">New password</label>
    <input id="new_password" name="new_password" type="password" autocomplete="new-password" required<?= $err('new_password') ?>>
    <?php $field = 'new_password'; require __DIR__ . '/partials/field_error.php' ?>
    <label for="confirm_password">Repeat new password</label>
    <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required<?= $err('confirm_password') ?>>
    <?php $field = 'confirm_password'; require __DIR__ . '/partials/field_error.php' ?>
    <p class="hint">Changing the password signs out all your other sessions.</p>
    <button type="submit" class="btn btn-primary">Change password</button>
  </form>
</div>
