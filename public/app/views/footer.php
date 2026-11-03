<?php
declare(strict_types=1);
/** @var string $site */
/** @var array{w1: int, h1: int, w2: int, h2: int} $logo */
$cats = q_all('SELECT c.slug, c.name, p.slug AS parent FROM categories c LEFT JOIN categories p ON p.id = c.parent_id
                WHERE c.slug <> ? ORDER BY c.position, c.id', ['regions']);
?>
<footer class="site-footer">
  <div class="wrap footer-grid">
    <div class="footer-about">
      <?= view('logo', ['site' => $site, 'logo' => $logo, 'class' => 'logo logo-footer', 'eager' => false]) ?>
      <p><?= e(setting('about')) ?></p>
    </div>
    <nav class="footer-cats" aria-label="Categories">
      <h2>Categories</h2>
      <ul>
        <?php foreach ($cats as $c): ?>
        <li><a href="<?= e(site_link('category', ['slug' => (string) $c['slug'], 'parent' => (string) ($c['parent'] ?? '')])) ?>"><?= e((string) $c['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </nav>
  </div>
  <div class="wrap footer-base">© <?= e(gmdate('Y')) ?> <?= e($site) ?></div>
</footer>
