<?php
require_once __DIR__ . '/../src/bootstrap.php';

use Src\Auth\AuthManager;
use Src\Core\Html;
use Src\Service\ScheduleService;
use Src\Service\BillingService;
use Src\Service\ShiftService;

$auth = new AuthManager();
$auth->requireAdmin();

$currentUser = $auth->getRequiredUser();
$scheduleService = new ScheduleService();
$billingService = new BillingService();

// Date range from query parameters (validated; tampered values fall back to defaults)
[$billingStartTs, $billingEndTs] = BillingService::parseBillingRange(
    Html::qDate('start') ?: null,
    Html::qDate('end') ?: null
);
$billingStartInput = date('Y-m-d', $billingStartTs);
$billingEndInput = date('Y-m-d', $billingEndTs);

$allBlocks = $scheduleService->getAllBlocks();
$billingSummary = $billingService->generateSummary($billingStartTs, $billingEndTs);

// Filter blocks overlapping the date range (same logic as the billing summary)
$filteredBlocks = [];
foreach ($allBlocks as $block) {
    if ($block->getStartTimestamp() <= $billingEndTs && $block->getEndTimestamp() > $billingStartTs) {
        $filteredBlocks[] = $block;
    }
}

// Build user name map
$userMap = [];
foreach ($auth->getAllUsers() as $u) {
    $userMap[$u->getId()] = $u->getName();
}

$periodLabel = date('d.m.Y', $billingStartTs) . ' – ' . date('d.m.Y', $billingEndTs);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dienstplan-Export – PsychKG NRW – <?= $periodLabel ?></title>
    <!-- Lokale Webfonts (kein CDN, keine IP-Uebermittlung an Dritte) -->
    <link rel="stylesheet" href="assets/fonts.css">
    <link rel="stylesheet" href="assets/css/export_pdf.css">
</head>
<body>

    <!-- Action Bar (screen only) -->
    <div class="action-bar">
        <button class="btn btn-primary" id="print-btn">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
            Drucken / Als PDF speichern
        </button>
        <a href="admin.php" class="btn btn-secondary">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            Zurück zum Admin-Bereich
        </a>
    </div>

    <div class="print-container">

        <!-- Document Header -->
        <div class="doc-header">
            <div>
                <h1>Dienstplan – Bereitschaftsdienst</h1>
                <div class="subtitle">Unterbringung nach PsychKG NRW • Ordnungsbehörde</div>
            </div>
            <div class="meta">
                <strong>Abrechnungszeitraum</strong>
                <?= $periodLabel ?><br>
                Erstellt am: <?= date('d.m.Y, H:i') ?> Uhr<br>
                Erstellt von: <?= htmlspecialchars($currentUser->getName()) ?>
            </div>
        </div>

        <!-- Quick Summary Cards -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="label">Dienstblöcke im Zeitraum</div>
                <div class="value"><?= count($filteredBlocks) ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Eingesetzte Mitarbeiter</div>
                <div class="value"><?= count($billingSummary) ?></div>
            </div>
            <?php 
                $totalHours = 0;
                foreach ($billingSummary as $s) {
                    $totalHours += $s['total_hours'];
                }
            ?>
            <div class="summary-card">
                <div class="label">Gesamt-Bereitschaftsstunden (Netto)</div>
                <div class="value"><?= number_format($totalHours, 1, ',', '.') ?> Std.</div>
            </div>
        </div>

        <!-- Section 1: Detailed Schedule Table -->
        <div class="section-title">Detaillierter Dienstplan</div>
        <table>
            <thead>
                <tr>
                    <th style="width:5%">#</th>
                    <th style="width:30%">Dienstzeitraum (Mi–Mi)</th>
                    <th style="width:25%">Zugeordneter Mitarbeiter</th>
                    <th style="width:15%" class="text-right">Netto-Stunden</th>
                    <th style="width:15%" class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($filteredBlocks)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center; color:#94a3b8; padding:2rem;">
                            Keine Dienstblöcke im gewählten Zeitraum vorhanden.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($filteredBlocks as $idx => $block): 
                        // Hours clipped to the export range (consistent with the summary)
                        $netHours = ScheduleService::calculateActiveHours(max($block->getStartTimestamp(), $billingStartTs), min($block->getEndTimestamp(), $billingEndTs));
                        $statusLabel = 'Geplant';
                        $badgeClass = 'badge-assigned';
                        if ($block->getStatus() === 'trade_open') {
                            $statusLabel = 'Tausch offen';
                            $badgeClass = 'badge-open';
                        } elseif ($block->getStatus() === 'trade_pending') {
                            $statusLabel = 'Wartet auf Freigabe';
                            $badgeClass = 'badge-pending';
                        }
                    ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td class="font-bold"><?= $block->getFormattedStart() ?> – <?= $block->getFormattedEnd() ?></td>
                            <td><?= htmlspecialchars($userMap[$block->getAssignedUserId()] ?? 'Unbekannt') ?></td>
                            <td class="text-right font-mono font-bold"><?= number_format($netHours, 1, ',', '') ?>h</td>
                            <td class="text-center"><span class="badge <?= $badgeClass ?>"><?= $statusLabel ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Section 2: Billing Summary per Employee -->
        <div class="section-title">Zusammenfassung pro Mitarbeiter</div>
        <table>
            <thead>
                <tr>
                    <th style="width:35%">Mitarbeiter</th>
                    <th style="width:20%" class="text-center">Geleistete Dienste</th>
                    <th style="width:25%" class="text-right">Bereitschaftsstunden (Netto)</th>
                    <th style="width:20%">Dienst-Zeiträume</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($billingSummary)): ?>
                    <tr>
                        <td colspan="4" style="text-align:center; color:#94a3b8; padding:2rem;">
                            Keine Daten im gewählten Zeitraum.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($billingSummary as $data): 
                        $user = $data['user'];
                    ?>
                        <tr>
                            <td class="font-bold"><?= htmlspecialchars($user->getName()) ?></td>
                            <td class="text-center font-bold"><?= $data['blocks_count'] ?></td>
                            <td class="text-right font-mono font-bold"><?= number_format($data['total_hours'], 1, ',', '') ?> Std.</td>
                            <td style="font-size:0.7rem; color:#64748b;">
                                <?php foreach ($data['details'] as $detail): ?>
                                    <?= date('d.m.', $detail['start_ts'] ?? strtotime($detail['start'])) ?>–<?= date('d.m.', $detail['end_ts'] ?? strtotime($detail['end'])) ?> 
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Section 3: PsychKG Information Box -->
        <div class="section-title">Hinweise zur Berechnung</div>
        <div style="font-size:0.75rem; color:#64748b; line-height:1.7; background:#f8fafc; padding:1rem; border-radius:0.5rem; border:1px solid #e2e8f0;">
            <strong>Berechnungsgrundlage gemäß Einsatzgemeinschaft:</strong><br>
            • Dienstwoche: <?= ShiftService::getDayName(ShiftService::getDutyStartDay()) ?> <?= ShiftService::getDutyStartTime() ?> Uhr bis folgender <?= ShiftService::getDayName(ShiftService::getDutyStartDay()) ?> <?= ShiftService::getBereitschaftEndTime() ?> Uhr<br>
            • Innendienst-Entlastung: <?= ShiftService::isInnendienstEnabled() ? 'aktiv' : 'deaktiviert – dann keine Entlastung, reine Bereitschaft' ?>, <?= ShiftService::getInnendienstStart() ?>–<?= ShiftService::getInnendienstEnd() ?> Uhr (exkl. Feiertage NRW)<br>
            • An Wochenenden und Feiertagen gilt durchgehende Rufbereitschaft (24h)<br>
            • Die ausgewiesenen Netto-Stunden beziehen sich ausschließlich auf die aktive Bereitschaftszeit<br>
            • NRW-Feiertage werden automatisch als Volldienst-Tage berechnet (kein Innendienst)
        </div>

        <!-- Document Footer -->
        <div class="doc-footer">
            PsychKG NRW Bereitschaftsdienst-Planer • Ordnungsbehörde NRW • Dokument generiert am <?= date('d.m.Y') ?> um <?= date('H:i') ?> Uhr
        </div>

    </div>

    <script src="assets/js/print.js" nonce="<?= CSP_NONCE ?>"></script>
</body>
</html>
