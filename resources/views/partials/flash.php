<?php
/** @var list<array{type:string,message:string}> $flash */
/** @var array<string,list<string>> $errors */

$map = [
    'success' => ['alert-success', 'check-circle'],
    'error'   => ['alert-danger', 'x-circle'],
    'danger'  => ['alert-danger', 'x-circle'],
    'warning' => ['alert-warning', 'alert'],
    'info'    => ['alert-info', 'info'],
];
?>

<?php foreach (($flash ?? []) as $item): ?>
    <?php [$class, $iconName] = $map[$item['type']] ?? $map['info']; ?>
    <div class="alert <?= e($class) ?>">
        <?= icon($iconName) ?>
        <div class="alert__body"><?= e($item['message']) ?></div>
    </div>
<?php endforeach; ?>

<?php if (($errors ?? []) !== []): ?>
    <div class="alert alert-danger">
        <?= icon('alert') ?>
        <div class="alert__body">
            <div class="alert__title">Please correct the following:</div>
            <ul style="margin:6px 0 0; padding-left:18px">
                <?php foreach ($errors as $messages): ?>
                    <?php foreach ((array) $messages as $message): ?>
                        <li><?= e($message) ?></li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>
