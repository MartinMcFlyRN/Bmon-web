<?php
/** @var string $title @var string $nav @var string $content @var array|null $user */
use Bmon\Web\Config;

$appName = (string) Config::get('app.name');
$isAdmin = ($user['role'] ?? '') === 'admin';
$tab = static fn (string $id): string => $nav === $id ? ' class="active" aria-current="page"' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title !== '' ? "$title · $appName" : $appName) ?></title>
<link rel="icon" href="<?= e(url('/assets/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>?v=<?= e(BMON_WEB_VERSION) ?>">
<script src="<?= e(url('/assets/app.js')) ?>?v=<?= e(BMON_WEB_VERSION) ?>" defer></script>
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= e(url('/')) ?>">
    <img src="<?= e(url('/assets/logo.svg')) ?>" alt="" width="26" height="26">
    <span><?= e($appName) ?></span>
  </a>
  <nav class="tabs" aria-label="Main">
    <a href="<?= e(url('/')) ?>"<?= $tab('dashboard') ?>>Dashboard</a>
    <a href="<?= e(url('/status')) ?>"<?= $tab('status') ?>>Status</a>
    <a href="<?= e(url('/inventory')) ?>"<?= $tab('inventory') ?>>Inventory</a>
    <span class="tab-soon" title="Coming soon">Reports</span>
    <?php if ($isAdmin): ?>
      <a href="<?= e(url('/admin/users')) ?>"<?= $tab('admin') ?>>Admin</a>
    <?php endif ?>
  </nav>
  <div class="usermenu">
    <a href="<?= e(url('/profile')) ?>"<?= $tab('profile') ?> title="My account">
      <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1))) ?></span>
      <?= e($user['username']) ?>
    </a>
    <form method="post" action="<?= e(url('/logout')) ?>">
      <?= csrf_field() ?>
      <button type="submit" class="linklike">Sign out</button>
    </form>
  </div>
</header>

<?php if ($nav === 'admin'): ?>
<nav class="subnav" aria-label="Admin">
  <a href="<?= e(url('/admin/users')) ?>"<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', url('/admin/users')) ? ' class="active"' : '' ?>>Users</a>
  <a href="<?= e(url('/admin/audit')) ?>"<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', url('/admin/audit')) ? ' class="active"' : '' ?>>Audit log</a>
</nav>
<?php endif ?>

<main class="container">
  <?php require __DIR__ . '/partials/flashes.php' ?>
  <?= $content ?>
</main>

<footer class="footer">Bmon-web <?= e(BMON_WEB_VERSION) ?></footer>
</body>
</html>
