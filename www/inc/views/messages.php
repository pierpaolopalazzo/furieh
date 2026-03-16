<?php if ($message): ?>
  <div class="msg-ok full"><?= $message ?></div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="msg-err full">ERROR: <?= h($error) ?></div>
<?php endif; ?>