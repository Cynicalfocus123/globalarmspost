<?php
declare(strict_types=1);
// Homepage: the sections set in the admin (home_sections), on a 12-column grid.
$sections = render_home_sections();
?>
<h1 class="sr-only"><?= e(setting('site_name', 'Global Arms Post')) ?></h1>
<?php if ($sections === ''): ?>
<p class="empty" id="home">No stories yet.</p>
<?php else: ?>
<div class="home-grid" id="home"><?= $sections ?></div>
<?php endif; ?>
