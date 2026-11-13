<?php
declare(strict_types=1);
/** @var array<string, mixed> $pg */
// A page (task F24): About Us, Terms of Service, Privacy Policy, Advertise, Contact Us … written in Admin → Pages.
// The body is the allow-list cleaned HTML (clean_html()), the same as a story's.
?>
<article class="article page-doc" id="page-<?= e((string) $pg['slug']) ?>">
  <nav class="crumbs" aria-label="Breadcrumb">
    <a href="<?= e(site_link('home')) ?>">Home</a> <span aria-hidden="true">›</span> <span><?= e((string) $pg['title']) ?></span>
  </nav>
  <h1 class="article-title"><?= e((string) $pg['title']) ?></h1>
  <p class="article-meta mute">Updated <time datetime="<?= e(seo_time((string) $pg['updated_at'])) ?>"><?= e(fmt_date((string) $pg['updated_at'])) ?></time></p>
  <div class="article-body page-body"><?= (string) $pg['body'] ?></div>
</article>
