<?php
/**
 * Page header — Eliminacode.
 * Top bar (Operatore, Amministrazione, user, logout) and, in /admin/, the sidebar.
 * Set $pageTitle before including.
 */
$currentUser = getCurrentUser();
$self        = $_SERVER['PHP_SELF'] ?? '';
$pageTitle   = $pageTitle ?? appName();
$inAdmin     = $currentUser && $currentUser['role'] === 'admin' && strpos($self, '/admin/') === 0;
$navLink     = static fn(string $href, string $icon, string $label) =>
    '<a href="' . $href . '" class="' . ($self === $href ? 'active' : '') . '"><i class="fas ' . $icon . '"></i> ' . h($label) . '</a>';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - <?= h(appName()) ?></title>
    <?= brandHeadTags() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Mono:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <style>
        .app-body { display:flex; align-items:stretch; }
        .app-body > .main-content { flex:1 1 auto; min-width:0; }
        .admin-sidebar { flex:0 0 230px; width:230px; background:#0c4a6e; min-height:calc(100vh - 70px); padding:16px 12px; }
        .admin-sidebar a { display:flex; align-items:center; gap:11px; padding:11px 13px; border-radius:8px; color:rgba(255,255,255,.82); text-decoration:none; font-size:.95rem; margin-bottom:3px; }
        .admin-sidebar a i { width:18px; text-align:center; }
        .admin-sidebar a:hover { background:rgba(255,255,255,.08); color:#fff; }
        .admin-sidebar a.active { background:var(--primary,#0284c7); color:#fff; }
        @media (max-width:1024px){ .app-body{flex-direction:column;} .admin-sidebar{flex:none;width:auto;min-height:0;display:flex;flex-wrap:wrap;} }
    </style>
</head>
<body class="role-<?= h($currentUser['role'] ?? 'guest') ?>">
    <nav class="main-nav">
        <div class="nav-brand">
            <?php if ($logo = brandLogoUrl()): ?>
                <span class="nav-logo"><img src="<?= h($logo) ?>" alt="<?= h(appName()) ?>"></span>
            <?php else: ?>
                <span class="nav-logo nav-mark"><img src="/assets/brand/upgrade-mark.png" alt="Upgrade"></span>
                <span><?= h(appName()) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($currentUser): ?>
        <div class="nav-links">
            <?= $navLink('/queue/operator.php', 'fa-forward', 'Operatore') ?>
            <?php if ($currentUser['role'] === 'admin'): ?>
                <a href="/admin/index.php" class="<?= strpos($self, '/admin/') === 0 ? 'active' : '' ?>"><i class="fas fa-cog"></i> Amministrazione</a>
            <?php endif; ?>
        </div>
        <div class="nav-user">
            <div class="user-info">
                <span class="user-name"><?= h($currentUser['full_name']) ?></span>
                <span class="user-role"><?= h(USER_ROLES[$currentUser['role']] ?? $currentUser['role']) ?></span>
            </div>
            <a href="/logout.php" class="btn-logout" title="Esci"><i class="fas fa-sign-out-alt"></i></a>
        </div>
        <?php endif; ?>
    </nav>

    <div class="app-body">
        <?php if ($inAdmin): ?>
        <aside class="admin-sidebar">
            <?= $navLink('/admin/index.php', 'fa-ticket', 'Eliminacode') ?>
            <?= $navLink('/admin/printer.php', 'fa-print', 'Stampante') ?>
            <?= $navLink('/admin/stats.php', 'fa-chart-column', 'Statistiche') ?>
            <?= $navLink('/admin/users.php', 'fa-users', 'Utenti') ?>
            <?= $navLink('/admin/activity.php', 'fa-history', 'Registro attività') ?>
        </aside>
        <?php endif; ?>
        <main class="main-content">
