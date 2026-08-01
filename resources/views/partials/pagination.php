<?php
/**
 * @var array{total:int, page:int, per_page:int, last_page:int} $result
 * @var string $baseUrl  Query string is rebuilt from the current request.
 */
$page = (int) ($result['page'] ?? 1);
$last = (int) ($result['last_page'] ?? 1);
$total = (int) ($result['total'] ?? 0);
$perPage = (int) ($result['per_page'] ?? 20);

if ($last <= 1) {
    return;
}

$query = $_GET;
$link = static function (int $target) use ($query): string {
    $query['page'] = $target;

    return '?' . http_build_query($query);
};

$from = (($page - 1) * $perPage) + 1;
$to = min($total, $page * $perPage);

$window = 2;
$start = max(1, $page - $window);
$end = min($last, $page + $window);
?>
<nav class="pagination" aria-label="Pagination">
    <span class="pagination__info"><?= e((string) $from) ?>–<?= e((string) $to) ?> of <?= e((string) $total) ?></span>

    <a class="page-link <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= e($link(max(1, $page - 1))) ?>" aria-label="Previous">
        <?= icon('chevron-left', 'icon icon-sm') ?>
    </a>

    <?php if ($start > 1): ?>
        <a class="page-link" href="<?= e($link(1)) ?>">1</a>
        <?php if ($start > 2): ?><span class="faint">…</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
        <a class="page-link <?= $i === $page ? 'is-active' : '' ?>" href="<?= e($link($i)) ?>"><?= e((string) $i) ?></a>
    <?php endfor; ?>

    <?php if ($end < $last): ?>
        <?php if ($end < $last - 1): ?><span class="faint">…</span><?php endif; ?>
        <a class="page-link" href="<?= e($link($last)) ?>"><?= e((string) $last) ?></a>
    <?php endif; ?>

    <a class="page-link <?= $page >= $last ? 'is-disabled' : '' ?>" href="<?= e($link(min($last, $page + 1))) ?>" aria-label="Next">
        <?= icon('chevron-right', 'icon icon-sm') ?>
    </a>
</nav>
