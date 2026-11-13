<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $rows */
// Pages (task F24): About Us, Terms of Service, Privacy Policy, Advertise, Contact Us and any page the admin adds.
// The footer links to them (Menu → Footer menu).
?>
<div class="a-head"><h1>Pages</h1><a class="a-btn" href="/admin/pages/new">+ New page</a></div>
<p class="a-small">Pages like About Us or Contact Us. Link to them in <a href="/admin/menu?m=footer">Menu → Footer menu</a> or the header menu.</p>
<ul class="a-rows pages-list">
  <?php foreach ($rows as $p): ?>
  <li class="page-row" data-id="<?= (int) $p['id'] ?>">
    <span class="grow"><a class="t" href="/admin/pages/<?= (int) $p['id'] ?>"><?= e((string) $p['title']) ?></a><span class="a-small">/<?= e((string) $p['slug']) ?> · updated <?= e(fmt_date((string) $p['updated_at'])) ?></span></span>
    <a class="a-btn2 sm hide-phone" href="<?= e(site_link('page', ['slug' => (string) $p['slug']])) ?>" target="_blank" rel="noopener">View ↗</a>
    <a class="a-btn2 sm" href="/admin/pages/<?= (int) $p['id'] ?>">Edit</a>
  </li>
  <?php endforeach; ?>
</ul>
<?php if ($rows === []): ?><p class="a-mute">No pages yet.</p><?php endif; ?>
