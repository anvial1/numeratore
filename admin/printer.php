<?php
/**
 * Admin: the totem's ticket printer (non-fiscal network thermal printer, ESC/POS on port 9100).
 * Settings in settings.queue (printer_*), used by queuePrinter() / queuePrintTicket().
 * "Verifica collegamento" opens the port from the server; "Stampa di prova" prints a sample ticket.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/queue.php';
requireRole(['admin']);

$back = static function (string $msg, bool $ok = true) {
    $_SESSION['printer_flash'] = [$msg, $ok];
    header('Location: /admin/printer.php');
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $cfg = queueSettings();
        $cfg['printer_host']     = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_POST['printer_host'] ?? ''));
        $cfg['printer_port']     = max(1, min(65535, (int) ($_POST['printer_port'] ?? 9100)));
        $cfg['printer_width']    = max(24, min(64, (int) ($_POST['printer_width'] ?? 48)));
        $cfg['printer_codepage'] = max(0, min(255, (int) ($_POST['printer_codepage'] ?? 2)));
        setSetting('queue', $cfg);
        logActivity('queue_printer_updated', 'settings', null, ['host' => $cfg['printer_host'], 'port' => $cfg['printer_port']]);
        $back($cfg['printer_host'] === '' ? 'Stampante tolta: il totem stampa dal browser del tablet.' : 'Stampante salvata. Premi "Verifica collegamento" per controllarla.');
    }
    if ($action === 'check') {
        [$ok, $msg] = queuePrinterCheck();
        $back($msg, $ok);
    }
    if ($action === 'test') {
        $res = queuePrintTicket(['id' => 0, 'service' => 'Prova stampa', 'label' => 'A000', 'ahead_text' => 'In coda prima di te: 3',
                                 'wait_text' => queueWaitText(12), 'time' => date('d/m/Y H:i')]);
        $back($res['ok'] ? 'Biglietto di prova inviato alla stampante.' : 'Stampa non riuscita: ' . $res['error'], $res['ok']);
    }
}

$cfg   = queueSettings();
$flash = $_SESSION['printer_flash'] ?? null;
unset($_SESSION['printer_flash']);
$set   = trim((string) $cfg['printer_host']) !== '';

$pageTitle = 'Stampante';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <h1><i class="fas fa-print"></i> Stampante dei biglietti</h1>
</div>

<?php if ($flash): [$msg, $ok] = $flash; ?>
    <div class="alert mb-lg" style="background:<?= $ok ? 'rgba(39,174,96,.1)' : 'rgba(231,76,60,.1)' ?>;color:<?= $ok ? 'var(--success)' : 'var(--danger)' ?>;padding:14px;border-radius:8px;">
        <i class="fas <?= $ok ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i> <?= h($msg) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-receipt"></i> Stampante termica del totem (non fiscale, ESC/POS)</h2></div>
    <form method="POST" class="card-body">
        <input type="hidden" name="action" value="save">
        <p class="text-muted" style="font-size:.9rem">Il totem stampa il biglietto su questa stampante di rete (porta 9100, come le stampanti non fiscali del gestionale).
            Il server la raggiunge anche tramite la VPN del locale. Se lasci vuoto l'indirizzo, o la stampante non risponde, il totem stampa il biglietto dal browser del tablet.</p>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Indirizzo IP</label>
                <input type="text" name="printer_host" class="form-control" value="<?= h($cfg['printer_host']) ?>" placeholder="192.168.1.50"></div>
            <div class="form-group" style="max-width:140px"><label class="form-label">Porta</label>
                <input type="number" name="printer_port" min="1" max="65535" class="form-control" value="<?= (int) $cfg['printer_port'] ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group" style="max-width:220px"><label class="form-label">Caratteri per riga</label>
                <input type="number" name="printer_width" min="24" max="64" class="form-control" value="<?= (int) $cfg['printer_width'] ?>" list="pr-widths">
                <datalist id="pr-widths"><option value="48">carta 80 mm</option><option value="42">carta 80 mm</option><option value="32">carta 58 mm</option></datalist></div>
            <div class="form-group" style="max-width:220px"><label class="form-label">Codepage</label>
                <input type="number" name="printer_codepage" min="0" max="255" class="form-control" value="<?= (int) $cfg['printer_codepage'] ?>"></div>
        </div>
        <p class="text-muted" style="font-size:.85rem;margin-top:-4px">Carta 80 mm: 48 caratteri (o 42). Carta 58 mm: 32. Codepage 2 = lettere accentate (PC850), 0 = PC437, come nel gestionale.</p>
        <button class="btn btn-primary"><i class="fas fa-save"></i> Salva</button>
    </form>
</div>

<div class="card mt-lg">
    <div class="card-header"><h2><i class="fas fa-stethoscope"></i> Prova</h2></div>
    <div class="card-body">
        <?php if ($set): ?>
            <p class="text-muted" style="font-size:.9rem">Prima controlla che il server raggiunga la stampante, poi stampa un biglietto di prova per vedere l'impaginazione.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <form method="POST"><input type="hidden" name="action" value="check">
                    <button class="btn btn-outline"><i class="fas fa-plug"></i> Verifica collegamento</button></form>
                <form method="POST"><input type="hidden" name="action" value="test">
                    <button class="btn btn-outline"><i class="fas fa-print"></i> Stampa un biglietto di prova</button></form>
            </div>
        <?php else: ?>
            <p class="text-muted">Nessuna stampante impostata: il totem stampa il biglietto dal browser del tablet.</p>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
