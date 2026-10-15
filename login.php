<?php
/** Login — Eliminacode. */
require_once __DIR__ . '/includes/functions.php';

if ($user = getCurrentUser()) {
    header('Location: ' . homeUrl($user['role']));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $error = 'Inserisci nome utente e password.';
    } else {
        $st = getDBConnection()->prepare("SELECT * FROM users WHERE username = ? AND active = 1");
        $st->execute([$username]);
        $u = $st->fetch();
        if ($u && password_verify($password, $u['password']) && isset(USER_ROLES[$u['role']])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $u['id'];
            logActivity('login');
            header('Location: ' . homeUrl($u['role']));
            exit;
        }
        $error = 'Nome utente o password non validi.';
    }
}
$logo = brandLogoUrl();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accedi - <?= h(appName()) ?></title>
    <?= brandHeadTags() ?>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Mono:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-logo">
            <?php if ($logo): ?>
                <img class="login-logo-img" src="<?= h($logo) ?>" alt="<?= h(appName()) ?>">
            <?php else: ?>
                <img class="login-brand" src="/assets/brand/upgrade-logo.png" alt="Upgrade" width="220" height="72">
                <small class="powered-contact" style="display:block;margin-top:4px;font-size:.8rem">Upgrade srls : 3288054977</small>
                <h1><?= h(appName()) ?></h1>
            <?php endif; ?>
            <p class="text-muted">Sistema eliminacode</p>
        </div>

        <?php if ($error): ?>
            <div class="login-error"><i class="fas fa-exclamation-circle"></i> <?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="login-form">
            <div class="form-group">
                <i class="fas fa-user"></i>
                <input type="text" name="username" class="form-control" placeholder="Nome utente" value="<?= h($_POST['username'] ?? '') ?>" required autofocus>
            </div>
            <div class="form-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" class="form-control" placeholder="Password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block"><i class="fas fa-sign-in-alt"></i> Accedi</button>
        </form>
        <?php if ($logo): ?><?= poweredBy() ?><?php endif; ?>
    </div>
</body>
</html>
