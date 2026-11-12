<?php /** @var array $newLink with url, expires, commands */ ?>
<div class="new-link">
  <label for="dl-url">Download link <span class="muted small">(expires <?= e($newLink['expires']) ?>)</span></label>
  <div class="copy-row">
    <input id="dl-url" type="text" readonly value="<?= e($newLink['url']) ?>" spellcheck="false">
    <button type="button" class="btn" data-copy="dl-url">Copy</button>
  </div>
  <label for="dl-cmd">Download and install on the agent <span class="muted small">(as root)</span></label>
  <div class="copy-row copy-row-block">
    <textarea id="dl-cmd" readonly rows="<?= substr_count($newLink['commands'], "\n") + 1 ?>" class="code" spellcheck="false"><?= e($newLink['commands']) ?></textarea>
    <button type="button" class="btn" data-copy="dl-cmd">Copy</button>
  </div>
</div>
