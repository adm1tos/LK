<?php $user = current_user(); ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'LK') ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php if ($user): ?>
        <?php require INCLUDES_PATH . '/sidebar.php'; ?>
    <?php endif; ?>
    <div class="main-area">
        <header class="topbar">
            <div>
                <h1><?= e($pageTitle ?? 'LK') ?></h1>
                <?php if (!empty($pageSubtitle)): ?>
                    <p class="muted"><?= e($pageSubtitle) ?></p>
                <?php endif; ?>
            </div>
            <div class="topbar-actions">
                <?php if ($user): ?>
                    <div class="user-box">
                        <strong><?= e($user['username']) ?></strong>
                        <span><?= e($user['role']) ?></span>
                    </div>
                    <a class="btn btn-secondary" href="<?= e(url('logout.php')) ?>">Выйти</a>
                <?php else: ?>
                    <a class="btn btn-secondary" href="<?= e(url('login.php')) ?>">Вход</a>
                    <a class="btn" href="<?= e(url('register.php')) ?>">Регистрация</a>
                <?php endif; ?>
            </div>
        </header>
        <main class="content">
            <?php if ($success = flash('success')): ?>
                <div class="alert alert-success"><?= e($success) ?></div>
            <?php endif; ?>
            <?php if ($error = flash('error')): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>
