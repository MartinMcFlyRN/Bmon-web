<?php /** @var string $username @var string|null $error */ ?>
<form method="post" action="<?= e(url('/login')) ?>" class="card login-card">
  <h1>Sign in</h1>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error" role="alert"><?= e($error) ?></div>
  <?php endif ?>
  <?= csrf_field() ?>
  <label for="username">Username</label>
  <input id="username" name="username" type="text" value="<?= e($username) ?>"
         autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus maxlength="64">
  <label for="password">Password</label>
  <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="4096">
  <button type="submit" class="btn btn-primary btn-block">Sign in</button>
</form>
