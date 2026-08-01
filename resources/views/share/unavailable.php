<?php

use App\Core\View;

View::extend('layouts.public');
View::share('title', 'Link unavailable');

/** @var string $message */

View::startSection('content');
?>
<div class="card" style="max-width:440px; margin:0 auto">
    <div class="card__body text-center">
        <div class="empty__icon"><?= icon('link-off', 'icon icon-hero') ?></div>
        <h1>Link unavailable</h1>
        <p class="muted mt-2"><?= e($message) ?></p>
        <p class="faint small mt-3">If you believe this is a mistake, ask the person who shared it for a new link.</p>
    </div>
</div>
<?php
View::endSection();
