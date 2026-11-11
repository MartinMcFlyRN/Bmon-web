<?php
/** @var string $title @var string $content */
use Bmon\Web\Config;

$appName = (string) Config::get('app.name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title !== '' ? "$title · $appName" : $appName) ?></title>
<link rel="icon" href="<?= e(url('/assets/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>?v=<?= e(BMON_WEB_VERSION) ?>">
</head>
<body class="guest">
<main class="guest-box">
  <div class="guest-brand">
    <img src="<?= e(url('/assets/logo.svg')) ?>" alt="" width="44" height="44">
    <span><?= e($appName) ?></span>
  </div>
  <?php require __DIR__ . '/partials/flashes.php' ?>
  <?= $content ?>
</main>
</body>
</html>
