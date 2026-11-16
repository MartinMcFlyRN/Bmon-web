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

  <section class="card devices-card">
    <h2>Remembered computers</h2>
    <?php if ($rememberDays === 0): ?>
      <p class="muted">Remembering computers is turned off: the two-factor code is asked at every sign-in.</p>
    <?php elseif (!$devices): ?>
      <p class="muted">None. Tick “Remember this computer” when you enter the two-factor code, and that browser asks only for
        the password for <?= $rememberDays ?> days.</p>
    <?php else: ?>
      <table class="table table-compact">
        <thead><tr><th>Browser</th><th>Remembered</th><th>Last used</th><th>Until</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($devices as $d): ?>
          <tr>
            <td><?= e(\Bmon\Web\TrustedDevices::label($d['user_agent'])) ?><?= (int) $d['id'] === $currentDevice ? ' <span class="badge badge-ok">This one</span>' : '' ?></td>
            <td class="nowrap"><?= e(fmt_dt($d['created_at'])) ?> <span class="muted small">from <?= e($d['ip']) ?></span></td>
            <td class="nowrap"><?= $d['last_used_at'] ? e(fmt_dt($d['last_used_at'])) . ' <span class="muted small">from ' . e($d['last_ip']) . '</span>' : '<span class="muted">—</span>' ?></td>
            <td class="nowrap"><?= e(fmt_dt($d['expires_at'], 'Y-m-d')) ?></td>
            <td><form method="post" action="<?= e(url('/profile/devices/' . $d['id'] . '/forget')) ?>">
              <?= csrf_field() ?><button type="submit" class="btn btn-sm">Forget</button></form></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <form method="post" action="<?= e(url('/profile/devices/0/forget')) ?>" data-confirm="Forget all your remembered computers? They ask for the two-factor code again.">
        <?= csrf_field() ?><button type="submit" class="btn btn-sm">Forget all</button>
      </form>
      <p class="hint">Changing the password, or a reset of the two-factor by an administrator, forgets them all.</p>
    <?php endif ?>
  </section>
</div>
