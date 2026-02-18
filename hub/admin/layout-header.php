<?php
/**
 * Admin Layout Template
 * 
 * Renders the header, navigation, and layout wrapper.
 * Include this at the top of every admin page after requireLogin().
 * 
 * Usage:
 *   require_once '../config/config.php';
 *   requireLogin();
 *   $pageTitle = 'Dashboard';
 *   $currentNav = 'dashboard';
 *   include 'layout-header.php';
 *   // ... page content ...
 *   include 'layout-footer.php';
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

$user = currentUser();
$navItems = [
    'index'    => ['label' => 'Dashboard', 'icon' => '📊'],
    'sites'    => ['label' => 'Sites',     'icon' => '🌐'],
    'usage'    => ['label' => 'Usage',     'icon' => '📈'],
    'settings' => ['label' => 'Settings',  'icon' => '⚙️'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle ?? 'Admin') ?> — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <header class="app-header">
        <div class="header-left">
            <div class="app-logo">
                <span class="app-logo-icon">⚡</span>
                <span><?= h(APP_NAME) ?></span>
            </div>
            <nav class="header-nav">
                <?php foreach ($navItems as $key => $item): ?>
                    <a href="<?= $key ?>.php" class="nav-link <?= ($currentNav ?? '') === $key ? 'active' : '' ?>">
                        <?= $item['icon'] ?> <?= $item['label'] ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <div class="header-right">
            <span class="version-badge">v<?= h(APP_VERSION) ?></span>
            <div class="user-menu">
                <?php if (!empty($user['avatar'])): ?>
                    <img src="<?= h($user['avatar']) ?>" alt="" class="user-avatar">
                <?php endif; ?>
                <span class="user-name"><?= h($user['name']) ?></span>
                <span class="badge <?= roleBadgeClass($user['role']) ?>"><?= roleLabel($user['role']) ?></span>
            </div>
            <a href="../logout.php" class="btn btn-outline btn-sm">Logout</a>
        </div>
    </header>

    <div class="page-container">
