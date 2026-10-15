<?php
/**
 * Common functions — Eliminacode.
 * Session, login and roles, JSON replies, activity log, app name and logo.
 *
 * Roles: 'admin' (everything) and 'operator' (calls numbers on queue/operator.php).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';

// The day (and so the ticket numbering) changes at midnight Italian time, whatever config/database.php says.
date_default_timezone_set('Europe/Rome');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const APP_DEFAULT_NAME = 'Eliminacode';
const USER_ROLES = ['admin' => 'Amministratore', 'operator' => 'Operatore'];

/** HTML-escape. */
function h($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** The name shown on the pages, the totem and the ticket (Amministrazione › Eliminacode › Impostazioni). */
function appName(): string
{
    $n = trim((string) (getSetting('queue', [])['brand'] ?? ''));
    return $n !== '' ? $n : APP_DEFAULT_NAME;
}

/** The logo (assets/img/logo.png) as a web path, or null when there is none. */
function brandLogoUrl(): ?string
{
    $f = __DIR__ . '/../assets/img/logo.png';
    return is_file($f) ? '/assets/img/logo.png?v=' . filemtime($f) : null;
}

/** Favicon and phone home-screen icon: the Upgrade mark (assets/brand, as in the chiamata app). */
function brandHeadTags(): string
{
    return '<link rel="icon" type="image/png" href="/assets/brand/icon-192.png">'
        . '<link rel="apple-touch-icon" href="/assets/brand/icon-180.png">';
}

/** Upgrade's contact: under the Upgrade logo and at the bottom of the printed ticket. */
const UPGRADE_CONTACT = 'Upgrade srls : 3288054977';

/** Small Upgrade logo with the contact under it: login, staff pages, totem and monitor. */
function poweredBy(string $class = ''): string
{
    return '<div class="powered ' . h($class) . '"><span class="powered-mark">'
        . '<img src="/assets/brand/upgrade-logo.png" alt="Upgrade" width="96" height="31">'
        . '<small class="powered-contact">' . h(UPGRADE_CONTACT) . '</small></span></div>';
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

/** The logged-in, active user, or null. */
function getCurrentUser(): ?array
{
    static $user = false;
    if (!isLoggedIn()) {
        return null;
    }
    if ($user === false || (int) ($user['id'] ?? 0) !== (int) $_SESSION['user_id']) {
        $st = getDBConnection()->prepare("SELECT * FROM users WHERE id = ? AND active = 1");
        $st->execute([$_SESSION['user_id']]);
        $user = $st->fetch() ?: null;
    }
    return $user;
}

function hasRole($roles): bool
{
    $user = getCurrentUser();
    return $user && in_array($user['role'], (array) $roles, true);
}

function requireLogin(): void
{
    if (!getCurrentUser()) {
        header('Location: /login.php');
        exit;
    }
}

function requireRole($roles): void
{
    requireLogin();
    if (!hasRole($roles)) {
        header('Location: /unauthorized.php');
        exit;
    }
}

/** Where a user lands after login. */
function homeUrl(?string $role): string
{
    return $role === 'admin' ? '/admin/index.php' : '/queue/operator.php';
}

function jsonResponse($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function logActivity(string $action, ?string $entityType = null, ?int $entityId = null, $details = null): void
{
    try {
        getDBConnection()->prepare(
            "INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $_SESSION['user_id'] ?? null,
            $action,
            $entityType,
            $entityId,
            $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('logActivity: ' . $e->getMessage());
    }
}
