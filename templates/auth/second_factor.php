<?php
/** @var array $user @var bool $setup @var string|null $error @var string|null $secret @var string|null $uri */
?>
<form method="post" action="<?= e(url('/login/2fa')) ?>" class="card login-card<?= $setup ? ' mfa-setup' : '' ?>">
  <?= csrf_field() ?>
  <?php if ($setup): ?>
    <h1>Set up two-factor authentication</h1>
    <p class="muted">Bmon-web asks every user for a code of an authenticator app at each sign-in. Set it up once, now.</p>
  <?php else: ?>
    <h1>Two-factor authentication</h1>
    <p class="muted">Enter the code of your authenticator app for <strong><?= e($user['username']) ?></strong>.</p>
  <?php endif ?>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error" role="alert"><?= e($error) ?></div>
  <?php endif ?>

  <?php if ($setup): ?>
    <ol class="mfa-steps">
      <li>Install an authenticator app on your phone, if you have none: Google Authenticator, Microsoft Authenticator,
        FreeOTP, Aegis, 1Password, Bitwarden…</li>
      <li>In the app, add an account and scan this QR code:
        <div class="mfa-qr" data-otpauth="<?= e($uri) ?>" role="img" aria-label="QR code for the authenticator app"></div>
        <details class="mfa-manual"><summary>Cannot scan it? Enter the key by hand</summary>
          <p>Key (time-based, 6 digits, 30 seconds):</p>
          <p class="mono mfa-secret"><?= e(trim(chunk_split((string) $secret, 4, ' '))) ?></p>
        </details>
      </li>
      <li>Enter the 6-digit code the app shows for Bmon:</li>
    </ol>
  <?php endif ?>

  <label for="code"><?= $setup ? 'Code from the app' : 'Code' ?></label>
  <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7"
         autocomplete="one-time-code" autocapitalize="none" spellcheck="false" required autofocus class="mfa-code">
  <button type="submit" class="btn btn-primary btn-block"><?= $setup ? 'Confirm and sign in' : 'Sign in' ?></button>
  <button type="submit" name="cancel" value="1" class="btn btn-block mfa-cancel" formnovalidate>Back to sign in</button>
  <?php if (!$setup): ?>
    <p class="hint">Lost your phone? An administrator can reset your two-factor authentication: you then set it up again at the next sign-in.</p>
  <?php endif ?>
</form>
<?php if ($setup): ?>
<script src="<?= e(url('/assets/vendor/qrcode.js')) ?>?v=<?= e(BMON_WEB_VERSION) ?>" defer></script>
<script src="<?= e(url('/assets/totp.js')) ?>?v=<?= e(BMON_WEB_VERSION) ?>" defer></script>
<?php endif ?>
