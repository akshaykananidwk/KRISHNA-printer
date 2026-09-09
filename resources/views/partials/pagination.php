<?php
/**
 * Pagination control.
 *
 * @var array{page:int,pages:int,total:int,per_page:int} $result
 * @var string $baseUrl   path without a page parameter
 * @var string $query     extra query string (no leading ?)
 */

use App\Core\View;

$page = (int) $result['page'];
$pages = (int) $result['pages'];
$total = (int) $result['total'];

if ($pages < 1) {
    return;
}

$link = static function (int $target) use ($baseUrl, $query): string {
    $params = $query !== '' ? $query . '&page=' . $target : 'page=' . $target;
    return $baseUrl . '?' . $params;
};

// Show a window around the current page rather than every page number — with
// 50 locations and months of jobs, a full list would be unusable.
$window = 2;
$start = max(1, $page - $window);
$end = min($pages, $page + $window);
?>
<nav class="a-pagination" aria-label="Pagination">
  <?php if ($page > 1): ?>
    <a class="a-pagination__link" href="<?= View::e($link($page - 1)) ?>" rel="prev" aria-label="Previous page">‹</a>
  <?php endif; ?>

  <?php if ($start > 1): ?>
    <a class="a-pagination__link" href="<?= View::e($link(1)) ?>">1</a>
    <?php if ($start > 2): ?><span class="a-muted">…</span><?php endif; ?>
  <?php endif; ?>

  <?php for ($i = $start; $i <= $end; $i++): ?>
    <a class="a-pagination__link<?= $i === $page ? ' is-active' : '' ?>"
       href="<?= View::e($link($i)) ?>"
       <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
  <?php endfor; ?>

  <?php if ($end < $pages): ?>
    <?php if ($end < $pages - 1): ?><span class="a-muted">…</span><?php endif; ?>
    <a class="a-pagination__link" href="<?= View::e($link($pages)) ?>"><?= $pages ?></a>
  <?php endif; ?>

  <?php if ($page < $pages): ?>
    <a class="a-pagination__link" href="<?= View::e($link($page + 1)) ?>" rel="next" aria-label="Next page">›</a>
  <?php endif; ?>

  <span class="a-pagination__info">
    <?= number_format($total) ?> result<?= $total === 1 ? '' : 's' ?>
  </span>
</nav>
