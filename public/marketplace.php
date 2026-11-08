<?php
require_once __DIR__ . '/../src/bootstrap.php';

use Src\Auth\AuthManager;
use Src\Core\Csrf;
use Src\Core\Flash;
use Src\Core\Html;
use Src\Service\ScheduleService;
use Src\Service\TradeService;
use Src\Service\AuditService;
use Src\Model\AuditEntry;

$auth = new AuthManager();
$currentUser = $auth->getRequiredUser();
$scheduleService = new ScheduleService();
$tradeService = new TradeService();
$auditService = new AuditService();

$error = null;
$success = null;

$okMessages = [
    'trade_accepted' => 'Sie haben den Dienst erfolgreich übernommen! Der Tausch wartet nun auf die administrative Freigabe durch die Dienststellenleitung.',
    'trade_cancelled' => 'Ihre Tauschanfrage wurde erfolgreich storniert.',
];
$errMessages = [
    'accept_failed' => 'Fehler beim Übernehmen des Dienstes. Ggf. wurde das Angebot bereits zurückgezogen oder Sie sind selbst der Ersteller.',
    'cancel_failed' => 'Fehler beim Stornieren der Tauschanfrage.',
];
Flash::consume($success, $error, $okMessages, $errMessages);

// Handle Trade Actions - Accept and Cancel (Post/Redirect/Get: no duplicate on F5)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::requireValid();
    $action = Html::p('action');
    $tradeId = Html::p('trade_id');

    if ($action === 'accept') {
        if ($tradeService->acceptTrade($tradeId, $currentUser->getId())) {
            $auditService->log(AuditEntry::TRADE_ACCEPTED, 'trade', $tradeId);
            Flash::redirect('marketplace.php', 'trade_accepted');
        } else {
            Flash::redirect('marketplace.php', null, 'accept_failed');
        }
    } elseif ($action === 'cancel') {
        if ($tradeService->cancelTrade($tradeId, $currentUser->getId())) {
            $auditService->log(AuditEntry::TRADE_CANCELLED, 'trade', $tradeId);
            Flash::redirect('marketplace.php', 'trade_cancelled');
        } else {
            Flash::redirect('marketplace.php', null, 'cancel_failed');
        }
    }
}

// Fetch all trades and filter
$allTrades = $tradeService->getAllTrades();
$blocks = $scheduleService->getAllBlocks();

// Build a map of blocks for easy lookup
$blockMap = [];
foreach ($blocks as $b) {
    $blockMap[$b->getId()] = $b;
}

// User map for names
$userMap = [];
foreach ($auth->getAllUsers() as $u) {
    $userMap[$u->getId()] = $u->getName();
}

// Separate trades into Open, Pending (Waiting Admin), and Completed
$openTrades = [];
$pendingTrades = [];
$myOpenTrades = [];

foreach ($allTrades as $trade) {
    $block = $blockMap[$trade->getBlockId()] ?? null;
    if (!$block) {
        continue;
    }

    if ($trade->isOpen()) {
        if ($trade->isOwner($currentUser->getId())) {
            $myOpenTrades[] = [
                'trade' => $trade,
                'block' => $block
            ];
        } else {
            $openTrades[] = [
                'trade' => $trade,
                'block' => $block
            ];
        }
    } elseif ($trade->isPendingApproval()) {
        $pendingTrades[] = [
            'trade' => $trade,
            'block' => $block
        ];
    }
}

// Personal trade history: completed trades involving me (newest first)
$myTradeHistory = [];
foreach ($allTrades as $trade) {
    if (!($trade->isApproved() || $trade->isRejected() || $trade->isCancelled())) {
        continue;
    }
    if (!$trade->isOwner($currentUser->getId()) && !$trade->isApprover($currentUser->getId())) {
        continue;
    }
    $block = $blockMap[$trade->getBlockId()] ?? null;
    if (!$block) {
        continue;
    }
    $myTradeHistory[] = ['trade' => $trade, 'block' => $block];
}
usort($myTradeHistory, fn ($a, $b) => strcmp($b['trade']->getCreatedAt(), $a['trade']->getCreatedAt()));
$myTradeHistory = array_slice($myTradeHistory, 0, 20);
?>
<!DOCTYPE html>
<html lang="de" class="h-full bg-slate-50 text-slate-800">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PsychKG Bereitschaftsplaner - Tauschangebote</title>
    <!-- Lokales Tailwind CSS (CSP-konform, kein externes CDN) -->
    <link rel="stylesheet" href="assets/app.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📅</text></svg>">
    <!-- Google Fonts -->
    <!-- Lokale Webfonts (kein CDN, keine IP-Uebermittlung an Dritte) -->
    <link rel="stylesheet" href="assets/fonts.css">
    <meta name="theme-color" content="#4f46e5">
</head>
<body class="min-h-full flex flex-col bg-slate-50 text-slate-800">

    <?php $activePage = 'marketplace'; require __DIR__ . '/../templates/nav.php'; ?>

    <!-- Main Content Area -->
    <main id="main-content" class="flex-grow max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold tracking-tight text-slate-900">Tauschangebote</h1>
            <p class="text-slate-600 mt-2 text-sm">Finden Sie Schichtvertretungen oder bieten Sie eigene zugewiesene Bereitschaftszeiten zur Übernahme an.</p>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
            <div role="alert" aria-live="polite" class="php-alert php-alert-success mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-start space-x-2">
                <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div role="alert" aria-live="polite" class="php-alert php-alert-error mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm flex items-start space-x-2">
                <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- Main Column: Open Trades of others -->
            <div class="lg:col-span-2 space-y-8">
                
                <!-- Open Trades Card -->
                <div class="glass-card rounded-3xl p-6">
                    <h2 class="text-xl font-bold text-slate-900 mb-6">Offene Tauschangebote von Kollegen</h2>

                    <?php if (empty($openTrades)): ?>
                        <div class="text-center py-16">
                            <svg class="w-20 h-20 mx-auto text-slate-200 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M8 7H12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4m10-2a2 2 0 100-4 2 2 0 000 4z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 14a2 2 0 100 4 2 2 0 000-4z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M8 16a2 2 0 100 4 2 2 0 000-4z"/>
                            </svg>
                            <p class="text-sm text-slate-500 font-medium">Aktuell stehen keine offenen Dienste zum Tausch auf dem Marktplatz.</p>
                            <p class="text-xs text-slate-400 mt-1">Sobald Kollegen Schichten freigeben, erscheinen diese hier.</p>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 anim-stagger">
                            <?php foreach ($openTrades as $item): 
                                $trade = $item['trade'];
                                $block = $item['block'];
                                $netHours = ScheduleService::calculateActiveHours($block->getStartTimestamp(), $block->getEndTimestamp());
                            ?>
                                <div class="p-5 rounded-2xl bg-white border-2 border-amber-200 flex flex-col justify-between hover:border-amber-300 hover:shadow-md transition-all group shadow-sm lift">
                                    <div class="space-y-4">
                                        <div class="flex justify-between items-start">
                                            <span class="px-2 py-0.5 rounded text-xs bg-amber-50 text-amber-700 font-bold uppercase border border-amber-200/50">
                                                Tausch angeboten
                                            </span>
                                            <span class="text-xs font-semibold text-slate-500">
                                                Netto: <?= number_format($netHours, 1, ',', '') ?>h
                                            </span>
                                        </div>
                                        <div>
                                            <div class="text-xs text-slate-500">Diensthabender</div>
                                            <div class="text-sm font-bold text-slate-900 mt-0.5"><?= htmlspecialchars($userMap[$trade->getRequesterId()] ?? 'Mitarbeiter') ?></div>
                                        </div>
                                        <div>
                                            <div class="text-xs text-slate-500">Zeitraum</div>
                                            <div class="text-sm font-semibold text-indigo-700 mt-0.5"><?= $block->getFormattedStart() ?></div>
                                            <div class="text-sm font-semibold text-indigo-700"><?= $block->getFormattedEnd() ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-6 pt-4 border-t border-slate-100">
                                        <form action="marketplace.php" method="POST">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="accept">
                                            <input type="hidden" name="trade_id" value="<?= htmlspecialchars($trade->getId()) ?>">
                                            <button type="submit" 
                                                    class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold uppercase rounded-xl transition-all shadow-md shadow-indigo-600/10 hover:shadow-indigo-600/20 active:scale-[0.98]">
                                                Dienst übernehmen
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pending Trade Approvals Widget -->
                <div class="glass-card rounded-3xl p-6">
                    <h2 class="text-lg font-bold text-slate-900 mb-6">Angenommene Tauschvorgänge in Warteschlange</h2>
                    
                    <?php if (empty($pendingTrades)): ?>
                        <div class="empty-state">
                            <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <p class="empty-state-title">Keine Tauschanträge</p>
                            <p class="empty-state-text">Nach der Übernahme durch einen Kollegen wird der Tausch hier zur Freigabe angezeigt.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($pendingTrades as $item): 
                                $trade = $item['trade'];
                                $block = $item['block'];
                            ?>
                                <div class="p-4 rounded-xl bg-violet-50/50 border border-violet-200 flex flex-col sm:flex-row justify-between sm:items-center gap-4 text-xs shadow-sm">
                                    <div>
                                        <div class="font-bold text-slate-900"><?= $block->getFormattedStart() ?> – <?= $block->getFormattedEnd() ?></div>
                                        <div class="text-slate-600 mt-1">
                                            Vorschlag: <strong class="text-rose-700"><?= htmlspecialchars($userMap[$trade->getRequesterId()] ?? 'Mitarbeiter') ?></strong> 
                                            ➜ <strong class="text-emerald-700"><?= htmlspecialchars($userMap[$trade->getReceiverId()] ?? 'Mitarbeiter') ?></strong>
                                        </div>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <span class="inline-flex items-center px-2 py-1 rounded bg-violet-50 border border-violet-200 text-violet-700 font-bold uppercase text-xs">
                                            Wartet auf Freigabe
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Sidebar: My Open Offers -->
            <div class="space-y-8">
                
                <!-- My Open Trades Card -->
                <div class="glass-card rounded-3xl p-6">
                    <h2 class="text-lg font-semibold text-slate-900 mb-6 font-display">Meine offenen Tauschangebote</h2>
                    
                    <?php if (empty($myOpenTrades)): ?>
                        <div class="text-center py-10">
                            <svg class="w-16 h-16 mx-auto text-slate-200 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <p class="text-sm text-slate-500 font-medium">Sie bieten derzeit keine Dienste zum Tausch an.</p>
                            <p class="text-xs text-slate-400 mt-1">Im Dashboard können Sie eigene Dienste auf dem Marktplatz anbieten.</p>
                            <a href="dashboard.php" class="mt-4 inline-flex items-center px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold uppercase transition-all shadow-sm">
                                Zum Dashboard
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($myOpenTrades as $item): 
                                $trade = $item['trade'];
                                $block = $item['block'];
                                $netHours = ScheduleService::calculateActiveHours($block->getStartTimestamp(), $block->getEndTimestamp());
                            ?>
                                <div class="p-4 rounded-2xl bg-white border-2 border-amber-200 flex flex-col gap-3 shadow-sm">
                                    <div>
                                        <div class="text-xs font-semibold text-slate-500">Dienstblock</div>
                                        <div class="text-sm font-bold text-slate-900 mt-0.5"><?= $block->getFormattedStart() ?></div>
                                        <div class="text-sm font-bold text-slate-900"><?= $block->getFormattedEnd() ?></div>
                                        <div class="text-xs text-slate-600 mt-1">Netto-Dienststunden: <?= number_format($netHours, 1, ',', '') ?>h</div>
                                    </div>

                                    <div>
                                        <form action="marketplace.php" method="POST">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="trade_id" value="<?= htmlspecialchars($trade->getId()) ?>">
                                            <button type="submit" 
                                                    class="w-full py-2 px-3 rounded-lg border border-rose-200 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-semibold transition-all">
                                                Angebot zurückziehen
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- My Trade History -->
                <div class="glass-card rounded-3xl p-6">
                    <h2 class="text-lg font-semibold text-slate-900 mb-6 font-display">Meine abgeschlossenen Vorgänge</h2>
                    <?php if (empty($myTradeHistory)): ?>
                        <p class="text-xs text-slate-400 text-center py-6">Noch keine abgeschlossenen Tauschvorgänge.</p>
                    <?php else: ?>
                        <div class="space-y-2.5">
                            <?php foreach ($myTradeHistory as $item):
                                $trade = $item['trade'];
                                $block = $item['block'];
                                $approved = $trade->isApproved();
                                $cancelled = $trade->isCancelled();
                                $otherId = ($trade->getRequesterId() === $currentUser->getId())
                                    ? $trade->getReceiverId() : $trade->getRequesterId();
                            ?>
                                <div class="p-3 rounded-xl bg-white border border-slate-200 shadow-sm">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="text-xs font-bold text-slate-900"><?= $block->getFormattedStart() ?> – <?= $block->getFormattedEnd() ?></div>
                                        <span class="px-2 py-0.5 rounded text-xs font-bold uppercase flex-shrink-0 <?= $approved ? 'bg-emerald-100 text-emerald-700' : ($cancelled ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500') ?>">
                                            <?= $approved ? 'Genehmigt' : ($cancelled ? 'Storniert' : 'Abgelehnt') ?>
                                        </span>
                                    </div>
                                    <div class="text-xs text-slate-500 mt-1">
                                        <?= $trade->getRequesterId() === $currentUser->getId() ? 'Abgegeben an' : 'Übernommen von' ?>:
                                        <strong><?= htmlspecialchars($userMap[$otherId] ?? 'Mitarbeiter') ?></strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Info Card -->
                <div class="glass-card rounded-3xl p-6 bg-gradient-to-br from-indigo-50/40 to-slate-50/80 text-xs text-slate-600 space-y-3">
                    <h3 class="font-bold text-slate-800 uppercase tracking-wider font-display">Tausch-Regularien</h3>
                    <p>1. Ein Dienst gilt erst als getauscht, nachdem die <strong>Dienststellenleitung (Admin)</strong> den Tausch genehmigt hat.</p>
                    <p>2. Bis zur Genehmigung durch den Admin bleibt die ursprünglich eingeteilte Person für den Dienst verantwortlich.</p>
                    <p>3. Tauschangebote können storniert werden, solange sie noch nicht von einem Kollegen angenommen wurden.</p>
                    <p class="pt-2 border-t border-slate-200/70 flex flex-wrap gap-x-3 gap-y-1">
                        <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-amber-400"></span> Offen</span>
                        <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-violet-400"></span> Wartet auf Freigabe</span>
                        <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-emerald-400"></span> Genehmigt</span>
                    </p>
                </div>

            </div>

        </div>

    </main>

    <footer class="border-t border-slate-200 bg-white py-6 text-center text-xs text-slate-500">
        PsychKG NRW Bereitschaftsdienstplaner – Copyright by Dario Jozic
    </footer>

</body>
</html>
