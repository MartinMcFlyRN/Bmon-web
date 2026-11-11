<?php /** @var int $status @var string $message @var string $title */ ?>
<div class="card error-card">
  <h1><?= e($status) ?> · <?= e($title) ?></h1>
  <pre class="error-message"><?= e($message) ?></pre>
  <p><a href="<?= e(url('/')) ?>">Back to the dashboard</a></p>
</div>
