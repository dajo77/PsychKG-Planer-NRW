<?php
require_once __DIR__ . '/../src/bootstrap.php';

use Src\Auth\AuthManager;
use Src\Core\Config;
use Src\Core\Csrf;
use Src\Core\Flash;
use Src\Core\Html;
use Src\I18n\Translator;
use Src\Model\ScheduleBlock;
use Src\Service\ScheduleService;
use Src\Service\TradeService;
use Src\Service\AuditService;
use Src\Model\AuditEntry;

$auth = new AuthManager();
$currentUser = $auth->getRequiredUser();

// One-time welcome overlay after login (set by login.php, consumed here)
$showWelcome = !empty($_SESSION['just_logged_in']);
unset($_SESSION['just_logged_in']);
$scheduleService = new ScheduleService();
$tradeService = new TradeService();
$auditService = new AuditService();

// ?error= is attacker-controllable and used to be echoed verbatim into the
// alert banner (only the exact value "unauthorized" was replaced). That allowed
// a crafted link to display arbitrary text on the official domain — phishing,
// not XSS, since the value is HTML-escaped when rendered. Resolve it through a
// whitelist instead, matching the pattern Flash::consume() already uses.
$error = null;
if (Html::q('error') === 'unauthorized') {
    $error = Translator::t('dashboard.error.unauthorized');
}
$success = null;

$okMessages = [
    'trade_offered' => Translator::t('dashboard.ok.trade_offered'),
    'pw_changed' => Translator::t('dashboard.ok.pw_changed'),
];
$errMessages = [
    'trade_failed' => Translator::t('dashboard.err.trade_failed'),
    'pw_mismatch' => Translator::t('dashboard.err.pw_mismatch'),
    'pw_weak' => Translator::t('dashboard.err.pw_weak'),
    'pw_wrong' => Translator::t('dashboard.err.pw_wrong'),
];
Flash::consume($success, $error, $okMessages, $errMessages);

// Handle Trade Offer requests (Post/Redirect/Get: no duplicate on F5)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::requireValid();
    $action = Html::p('action');
    if ($action === 'offer_trade') {
        $blockId = Html::p('block_id');

        // IDOR guard: the posted block id is attacker-controlled. Only the
        // employee currently assigned to the block may offer it. Unknown ids
        // get the same 403 as foreign ids, so this is not an existence oracle.
        $block = $scheduleService->getBlockById($blockId);
        if ($block === null || $block->getAssignedUserId() !== $currentUser->getId()) {
            http_response_code(403);
            exit(Translator::t('dashboard.access_denied'));
        }

        if ($tradeService->requestTrade($blockId, $currentUser->getId())) {
            $auditService->log(AuditEntry::TRADE_OFFERED, 'trade', $blockId);
            Flash::redirect('dashboard.php', 'trade_offered');
        } else {
            Flash::redirect('dashboard.php', null, 'trade_failed');
        }
    } elseif ($action === 'change_own_password') {
        $currentPw = Html::pRaw('current_password');
        $newPw = Html::pRaw('new_password');
        $confirmPw = Html::pRaw('confirm_password');

        if ($newPw !== $confirmPw) {
            Flash::redirect('dashboard.php', null, 'pw_mismatch');
        } elseif (AuthManager::validatePasswordStrength($newPw) !== null) {
            Flash::redirect('dashboard.php', null, 'pw_weak');
        } elseif ($auth->changePassword($currentUser->getId(), $currentPw, $newPw)) {
            $auditService->log(AuditEntry::PASSWORD_CHANGED, 'user', $currentUser->getId());
            Flash::redirect('dashboard.php', 'pw_changed');
        } else {
            Flash::redirect('dashboard.php', null, 'pw_wrong');
        }
    }
}

// Retrieve data
$allBlocks = $scheduleService->getAllBlocks();

$userMap = [];
$usersById = [];
foreach ($auth->getAllUsers() as $u) {
    $userMap[$u->getId()] = $u->getName();
    $usersById[$u->getId()] = $u;
}

$myBlocks = [];
foreach ($allBlocks as $block) {
    if ($block->getAssignedUserId() === $currentUser->getId()) {
        $myBlocks[] = $block;
    }
}

// Find currently active block and phase
$now = time();
$activeBlock = $scheduleService->findActiveBlockAt($now);
$currentPhase = null;
$currentPhaseInterval = null;
$activeUserName = null;
$isOnCall = false;

if ($activeBlock !== null) {
    $currentPhaseInterval = ScheduleService::getPhaseAt(
        $activeBlock->getStartTimestamp(),
        $activeBlock->getEndTimestamp(),
        $now
    );
    $currentPhase = $currentPhaseInterval['type'];
    // Only a running Bereitschaft phase means "this person is on call". The
    // block container also covers the closing Innendienst window.
    $isOnCall = $currentPhase === 'bereitschaft';

    $assignedId = $activeBlock->getAssignedUserId();
    $activeUserName = $userMap[$assignedId] ?? null;
    
    $activeUserPhone = ($usersById[$assignedId] ?? null)?->getPhone();
    if ($activeUserPhone === '') {
        $activeUserPhone = null;
    }
}

    // Next duty change (phase flip or block boundary) for the live countdown
    $nextChangeTs = null;
    $nextChangeLabel = null;
    if ($activeBlock !== null) {
        $phaseEnd = ScheduleService::getNextPhaseChange(
            $activeBlock->getStartTimestamp(),
            $activeBlock->getEndTimestamp(),
            $now
        );
        if ($phaseEnd !== null) {
            if ($phaseEnd < $activeBlock->getEndTimestamp()) {
                $nextChangeTs = $phaseEnd;
                // Label = current phase (what we're leaving), NOT the next phase
                $nextChangeLabel = $currentPhase === 'innendienst' ? 'Innendienst' : 'Bereitschaft';
            } else {
                $nextChangeTs = $activeBlock->getEndTimestamp();
                $nextChangeLabel = 'Blockende';
            }
        }
    } else {
        $upcomingStarts = array_filter(
            array_map(fn($b) => $b->getStartTimestamp(), $allBlocks),
            fn($ts) => $ts > $now
        );
        if (!empty($upcomingStarts)) {
            sort($upcomingStarts);
            $nextChangeTs = $upcomingStarts[0];
            $nextChangeLabel = 'Dienstbeginn';
        }
    }

    // Human-readable countdown prefix: "Innendienst endet in ...",
    // "Blockende in ...", "Dienstbeginn in ...".
    $nextChangePhrase = $nextChangeLabel === null
        ? null
        : (in_array($nextChangeLabel, ['Blockende', 'Dienstbeginn'], true)
            ? $nextChangeLabel . ' in'
            : $nextChangeLabel . ' endet in');

// Compute compact sidebar summary
$myUpcomingBlocks = array_values(array_filter($myBlocks, function($b) use ($now) {
    return $b->getStartTimestamp() > $now;
}));
usort($myUpcomingBlocks, function($a, $b) {
    return $a->getStartTimestamp() <=> $b->getStartTimestamp();
});
$nextMyBlock = $myUpcomingBlocks[0] ?? null;
$totalMyNetHours = array_sum(array_map(function($b) {
    return ScheduleService::calculateActiveHours($b->getStartTimestamp(), $b->getEndTimestamp());
}, $myBlocks));

// Progress bar data (only meaningful while the on-call phase is running)
$activeProgressPct = null;
$activeProgressLabel = null;
if ($activeBlock && $currentPhase === 'bereitschaft' && $activeBlock->getAssignedUserId() === $currentUser->getId()) {
    $totalDuration = $activeBlock->getEndTimestamp() - $activeBlock->getStartTimestamp();
    $elapsed = $now - $activeBlock->getStartTimestamp();
    // A corrupt block (start == end) must not throw DivisionByZeroError.
    $activeProgressPct = $totalDuration > 0
        ? min(100, max(0, round(($elapsed / $totalDuration) * 100)))
        : 0;
    $activeProgressLabel = 'Dienst-Fortschritt';
}

// Phase-Fortschritt: Fortschritt innerhalb der aktuellen Phase (Bereitschaft / Innendienst).
$phaseProgressPct = null;
if ($currentPhaseInterval !== null) {
    $phaseStart = (int) $currentPhaseInterval['start'];
    $phaseEnd = (int) $currentPhaseInterval['end'];
    $phaseDuration = $phaseEnd - $phaseStart;
    $phaseProgressPct = $phaseDuration > 0
        ? max(0, min(100, (int) round((($now - $phaseStart) / $phaseDuration) * 100)))
        : 0;
}

// Month calendar data.
$calMonth = Html::qi('cal_month', (int)date('n', $now), 1, 12);
$calYear  = Html::qi('cal_year', (int)date('Y', $now), 2000, 2100);
$prevMonth = $calMonth - 1; $prevYear = $calYear;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $calMonth + 1; $nextYear = $calYear;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }
$firstDayTs = mktime(0, 0, 0, $calMonth, 1, $calYear);
$daysInMonth = (int)date('j', mktime(0, 0, 0, $calMonth + 1, 0, $calYear));
$startDow = (int)date('N', $firstDayTs); // 1=Mon ... 7=Sun

$calDayKeys = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $calDayKeys[] = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $d);
}

$myDutyDays = [];
foreach ($myBlocks as $block) {
    foreach ($calDayKeys as $dk) {
        if (ScheduleService::blockOverlapsDate(
            $block->getStartTimestamp(), $block->getEndTimestamp(), $dk
        )) {
            $myDutyDays[$dk] = true;
        }
    }
}
$todayYmd = date('Y-m-d', $now);
$germanMonths = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

// Dienstauskunft: who was/is on duty at a given date+time? (GET, read-only)
$aqDateInput = Html::qDate('aq_date');
$aqTimeInput = Html::qTime('aq_time');
$aqResult = null;
$aqError = null;
if ($aqDateInput !== '' || $aqTimeInput !== '') {
    $aqTs = false;
    if ($aqDateInput !== '') {
        $t = $aqTimeInput !== '' ? $aqTimeInput : '12:00';
        $dt = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $aqDateInput . ' ' . $t,
            new \DateTimeZone(Config::getTimezone())
        );
        if ($dt !== false && $dt->format('Y-m-d') === $aqDateInput) {
            $aqTs = $dt->getTimestamp();
        }
    }
    if ($aqTs === false) {
        $aqError = "Bitte ein gültiges Datum (TT.MM.JJJJ) wählen.";
    } else {
        $aqBlock = $scheduleService->findActiveBlockAt($aqTs);
        if ($aqBlock === null) {
            $aqError = "Für diesen Zeitpunkt ist kein Dienstblock hinterlegt.";
        } else {
            $aqUserId = $aqBlock->getAssignedUserId();
            $aqUser = null;
            foreach ($auth->getAllUsers() as $u) {
                if ($u->getId() === $aqUserId) {
                    $aqUser = $u;
                    break;
                }
            }
            $aqResult = ['block' => $aqBlock, 'user' => $aqUser, 'ts' => $aqTs];
        }
    }
}

// Timeline filter (display only): person + hide past blocks
$filterUserId = Html::q('f_user');
$filterHidePast = Html::q('f_hide_past') === '1';
$filteredBlocks = array_values(array_filter($allBlocks, function ($b) use ($filterUserId, $filterHidePast, $now) {
    if ($filterUserId !== '' && $b->getAssignedUserId() !== $filterUserId) {
        return false;
    }
    if ($filterHidePast && $b->getEndTimestamp() < $now) {
        return false;
    }
    return true;
}));
?>
<!DOCTYPE html>
<html lang="de" class="h-full bg-slate-50 text-slate-800">
<?php $pageTitle = Translator::t('dashboard.title'); require __DIR__ . '/../templates/head.php'; ?>
<body class="min-h-full flex flex-col bg-slate-50 text-slate-800">

    <?php $activePage = 'dashboard'; require __DIR__ . '/../templates/nav.php'; ?>

    <?php if ($showWelcome): ?>
    <div id="welcome-overlay" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 backdrop-blur-sm">
        <div class="glass-card rounded-3xl p-8 mx-4 text-center max-w-xs w-full">
            <svg class="w-20 h-20 mx-auto mb-4" viewBox="0 0 52 52" fill="none" aria-hidden="true">
                <circle class="checkmark-circle" cx="26" cy="26" r="24" stroke="#10b981" stroke-width="2.5"/>
                <path class="checkmark-check" d="M15 27l7 7 15-16" stroke="#10b981" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <div class="text-lg font-bold text-slate-900">Willkommen, <?= htmlspecialchars($currentUser->getName()) ?>!</div>
            <p class="text-xs text-slate-500 mt-1">Erfolgreich angemeldet</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Main Content Area -->
    <main id="main-content" class="flex-grow max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">

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

        <!-- Auto-Extension Warning -->
        <?php
        $extensionWarning = null;
        if (!empty($allBlocks)) {
            $lastBlock = $allBlocks[count($allBlocks) - 1];
            $daysUntilEnd = (int)ceil(($lastBlock->getEndTimestamp() - $now) / 86400);
            if ($daysUntilEnd <= 14 && $daysUntilEnd >= 0) {
                $extensionWarning = $daysUntilEnd;
            }
        }
        ?>
        <?php if ($extensionWarning !== null): ?>
            <div class="mb-6 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-sm flex items-start space-x-2">
                <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <div>
                    <span class="font-semibold">Dienstplan endet bald!</span>
                    Der aktuelle Plan läuft in <?= $extensionWarning ?> <?= $extensionWarning === 1 ? 'Tag' : 'Tagen' ?> aus.
                    <?php if ($currentUser->isAdmin()): ?>
                        <a href="admin.php?tab=plan" class="underline font-semibold hover:text-amber-900">Jetzt verlängern</a>
                    <?php else: ?>
                        Bitte informieren Sie die Dienststellenleitung.
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Slim Header -->
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight">Dashboard</h1>
            </div>
            <div class="flex items-center gap-2">
                <?php
                $currentMonth = (int) date('n');
                $currentYear = (int) date('Y');
                $isH1 = $currentMonth <= 6;
                ?>
                <?php if ($auth->isAdmin()): ?>
                <a href="export_schedule_pdf.php?start=<?= $currentYear ?>-01-01&end=<?= $currentYear ?>-06-30" target="_blank"
                   class="<?= $isH1 ? 'bg-indigo-600 hover:bg-indigo-700 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?> hidden sm:inline-flex items-center py-2 px-4 rounded-xl text-xs font-bold uppercase transition-all shadow-sm">
                    1. HJ
                </a>
                <a href="export_schedule_pdf.php?start=<?= $currentYear ?>-07-01&end=<?= $currentYear ?>-12-31" target="_blank"
                   class="<?= !$isH1 ? 'bg-indigo-600 hover:bg-indigo-700 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?> hidden sm:inline-flex items-center py-2 px-4 rounded-xl text-xs font-bold uppercase transition-all shadow-sm">
                    2. HJ
                </a>
                <?php endif; ?>
                <a href="export_ics.php" target="_blank"
                   class="hidden sm:inline-flex items-center py-2 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold uppercase transition-all shadow-sm">
                    <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    Kalender
                </a>
                <?php if ($auth->isAdmin()): ?>
                <a href="export_ics.php?scope=team" target="_blank"
                   class="hidden sm:inline-flex items-center py-2 px-4 rounded-xl bg-slate-700 hover:bg-slate-800 text-white text-xs font-bold uppercase transition-all shadow-sm"
                   title="ICS-Export des gesamten Teams">
                    <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    Team-Kalender
                </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <!-- Column 1 & 2: Active Shift & Complete Schedule -->
            <div class="lg:col-span-2 space-y-8">

                <!-- Live Status Widget -->
                <div class="glass-card rounded-3xl p-6 relative overflow-hidden transition-all duration-300 hover:shadow-[0_14px_40px_rgba(15,23,42,0.08)] hover:-translate-y-0.5 <?= $isOnCall ? 'ring-1 ring-emerald-200/60' : 'ring-1 ring-amber-200/60' ?>">
                    <div class="absolute inset-x-0 top-0 h-1 <?= $isOnCall ? 'bg-gradient-to-r from-emerald-400 via-emerald-500 to-emerald-600' : 'bg-gradient-to-r from-amber-400 via-amber-500 to-amber-600' ?>"></div>
                    <div class="absolute -inset-4 rounded-[2rem] bg-gradient-to-br <?= $isOnCall ? 'from-emerald-400/25 via-indigo-500/15' : 'from-amber-400/15 via-indigo-500/10' ?> duty-glow blur-2xl pointer-events-none" aria-hidden="true"></div>
                    <div class="flex items-center gap-2 mb-4">
        <span class="flex h-2.5 w-2.5 relative flex-shrink-0">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full <?= $isOnCall ? 'bg-emerald-400' : 'bg-amber-400' ?> opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2.5 w-2.5 <?= $isOnCall ? 'bg-emerald-500' : 'bg-amber-500' ?>"></span>
        </span>
        <h2 class="text-xs font-semibold uppercase tracking-wider text-indigo-600 font-display">Aktueller Dienststatus</h2>
    </div>

                    <?php if ($activeBlock !== null): ?>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <div>
                                    <?php if ($isOnCall): ?>
                                        <div class="flex items-center gap-3">
                                            <div class="h-12 w-12 rounded-2xl flex items-center justify-center flex-shrink-0 text-white font-bold text-2xl shadow-md <?= $isOnCall ? 'bg-gradient-to-br from-emerald-500 to-emerald-700' : 'bg-gradient-to-br from-amber-500 to-amber-700' ?>">
                                                <?= htmlspecialchars(mb_substr($activeUserName ?? 'N', 0, 1, 'UTF-8')) ?>
                                            </div>
                                            <div class="text-2xl font-bold text-slate-900"><?= htmlspecialchars($activeUserName ?? 'Nicht zugeordnet') ?></div>
                                            <?php if ($activeBlock->getAssignedUserId() === $currentUser->getId()): ?>
                                                <span class="px-2.5 py-1 rounded-full bg-emerald-100 border border-emerald-300 text-emerald-800 text-[10px] font-bold uppercase whitespace-nowrap">Das ist Ihr Dienst!</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="flex items-center gap-3">
                                            <div class="h-12 w-12 rounded-2xl flex items-center justify-center flex-shrink-0 text-white shadow-md bg-gradient-to-br from-amber-500 to-amber-700">
                                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                            </div>
                                            <div class="text-2xl font-bold text-slate-900">Innendienst</div>
                                            <span class="px-2.5 py-1 rounded-full bg-amber-100 border border-amber-300 text-amber-800 text-[10px] font-bold uppercase whitespace-nowrap">Kein Bereitschaftsdienst</span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="text-xs font-semibold text-slate-700 mt-1">
                                        <?= ScheduleBlock::formatTimestamp($currentPhaseInterval['start']) ?> – <?= ScheduleBlock::formatTimestamp($currentPhaseInterval['end']) ?>
                                    </div>
                                     
                                    <?php if ($isOnCall && !empty($activeUserPhone)): ?>
                                        <div class="text-xs mt-1">
                                            <a href="tel:<?= htmlspecialchars(preg_replace('/[^+\d]/', '', $activeUserPhone)) ?>" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-indigo-50 border border-indigo-200 text-indigo-700 text-sm font-semibold hover:bg-indigo-100 transition-colors shadow-sm">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.94.725l.548 2.2a1 1 0 01-.321.988l-1.305.98a10.582 10.582 0 004.872 4.872l.98-1.305a1 1 0 01.988-.321l2.2.548a1 1 0 001.07.94V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                                                <?= htmlspecialchars($activeUserPhone) ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                <?php if ($activeProgressPct !== null): ?>
                                <div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round($activeProgressPct) ?>" aria-label="Dienst-Fortschritt" class="flex items-center gap-2 pr-3 border-r border-slate-100">
                                    <svg viewBox="0 0 40 40" class="w-14 h-14 flex-shrink-0 -rotate-90" aria-hidden="true">
                                        <circle cx="20" cy="20" r="16" fill="none" stroke-width="4" class="stroke-slate-200"></circle>
                                        <circle cx="20" cy="20" r="16" fill="none" stroke-width="4" stroke-linecap="round" class="<?= $isOnCall ? 'stroke-emerald-500' : 'stroke-amber-500' ?> duty-ring-fg" stroke-dasharray="100.53" stroke-dashoffset="<?= (int)round(100.53 * (1 - $activeProgressPct / 100)) ?>"></circle>
                                    </svg>
                                    <span class="text-sm font-bold <?= $isOnCall ? 'text-emerald-700' : 'text-amber-700' ?>"><?= (int)round($activeProgressPct) ?>%</span>
                                </div>
                                <?php endif; ?>
                                <?php if ($currentPhaseInterval !== null): ?>
                                <div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)$phaseProgressPct ?>" aria-label="Phase-Fortschritt: <?= htmlspecialchars($currentPhase) ?>">
                                    <svg viewBox="0 0 40 40" class="w-14 h-14 flex-shrink-0 -rotate-90" aria-hidden="true">
                                        <circle cx="20" cy="20" r="16" fill="none" stroke-width="4" class="stroke-slate-200"></circle>
                                        <circle cx="20" cy="20" r="16" fill="none" stroke-width="4" stroke-linecap="round" class="<?= $currentPhase === 'bereitschaft' ? 'stroke-emerald-500' : 'stroke-amber-500' ?> duty-ring-fg" stroke-dasharray="100.53" stroke-dashoffset="<?= (int)round(100.53 * (1 - $phaseProgressPct / 100)) ?>"></circle>
                                    </svg>
                                </div>
                                <?php endif; ?>
                                <?php if ($currentPhase === 'innendienst'): ?>
                                    <div class="inline-flex items-center px-3 py-1.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs font-semibold whitespace-nowrap">
                                        <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                                        Innendienst
                                    </div>
                                <?php else: ?>
                                    <div class="inline-flex items-center px-3 py-1.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold whitespace-nowrap">
                                        <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.94.725l.548 2.2a1 1 0 01-.321.988l-1.305.98a10.582 10.582 0 004.872 4.872l.98-1.305a1 1 0 01.988-.321l2.2.548a1 1 0 001.07.94V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                                        Bereitschaft
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($isOnCall): ?>
                                <div class="flex flex-col gap-1 mt-3">
                                    <div class="text-[10px] uppercase tracking-wider font-semibold text-slate-400">Rufnummer der Rufbereitschaft</div>
                                    <a href="tel:<?= htmlspecialchars(preg_replace('/[^+\d]/', '', Config::DUTY_HOTLINE)) ?>" class="inline-flex items-center gap-1.5 text-sm font-bold text-indigo-700 hover:text-indigo-900 transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.94.725l.548 2.2a1 1 0 01-.321.988l-1.305.98a10.582 10.582 0 004.872 4.872l.98-1.305a1 1 0 01.988-.321l2.2.548a1 1 0 001.07.94V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                                        <?= htmlspecialchars(Config::DUTY_HOTLINE) ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-3 pt-3 border-t border-slate-100 text-xs text-slate-500 flex flex-wrap items-center gap-2">
                            <?php if ($nextChangeTs !== null): ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 font-semibold">
                                <?= htmlspecialchars($nextChangePhrase) ?> <span data-countdown="<?= $nextChangeTs ?>" class="font-mono tabular-nums font-bold">--:--:--</span>
                            </span>
                            <?php else: ?>
                            <span>Stand --:--:--</span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- Empty state: no active block -->
                        <div class="text-center py-6">
                            <svg class="w-16 h-16 mx-auto text-slate-200 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <p class="text-sm text-slate-500 font-medium">Aktuell kein aktiver Dienstblock</p>
                            <p class="text-xs text-slate-400 mt-1">Der nächste Dienst beginnt automatisch zum geplanten Zeitpunkt.</p>
                            <?php if ($nextChangeTs !== null): ?>
                                <p class="text-xs text-indigo-600 font-semibold mt-2">Nächster <?= htmlspecialchars($nextChangeLabel) ?>: <?= date('d.m.Y H:i', $nextChangeTs) ?> (in <span data-countdown="<?= $nextChangeTs ?>">--:--:--</span>)</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Complete Schedule Timeline -->
                <div class="glass-card rounded-3xl p-6">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-lg font-semibold text-slate-900">Dienstplanübersicht</h2>
                        <span class="text-xs text-slate-400"><?= count($filteredBlocks) ?> von <?= count($allBlocks) ?> Blöcken</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mb-4 text-[11px] text-slate-500">
                        <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span> Mein Dienst</span>
                        <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span> Tausch offen</span>
                        <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-violet-400"></span> Wartet auf Freigabe</span>
                        <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-indigo-500"></span> Aktuell aktiv</span>
                    </div>
                    <form action="dashboard.php" method="GET" class="flex flex-col sm:flex-row gap-2 mb-5">
                        <select name="f_user" class="flex-1 px-3 py-2 bg-white border border-slate-200 rounded-xl text-slate-800 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm">
                            <option value="">Alle Mitarbeiter</option>
                            <?php foreach ($usersById as $uid => $u): ?>
                                <option value="<?= htmlspecialchars($uid) ?>" <?= $filterUserId === $uid ? 'selected' : '' ?>><?= htmlspecialchars($u->getName()) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="f_hide_past_check" class="inline-flex items-center gap-2 px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs text-slate-600 cursor-pointer shadow-sm">
                            <input type="checkbox" id="f_hide_past_check" name="f_hide_past" value="1" <?= $filterHidePast ? 'checked' : '' ?> class="rounded border-slate-300 text-indigo-600">
                            Vergangene ausblenden
                        </label>
                        <button type="submit" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 text-xs font-bold uppercase transition-all shadow-sm">Filtern</button>
                    </form>

                    <?php $filterActive = ($filterUserId !== '' || $filterHidePast); ?>
                    <?php if (empty($filteredBlocks) && $filterActive): ?>
                        <div class="empty-state">
                            <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <p class="empty-state-title">Keine Treffer für diesen Filter</p>
                            <p class="empty-state-text"><a href="dashboard.php" class="underline hover:text-slate-600">Filter zurücksetzen</a></p>
                        </div>
                    <?php elseif (empty($allBlocks)): ?>
                        <div class="empty-state">
                            <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <p class="empty-state-title">Noch keine Dienstblöcke vorhanden</p>
                            <p class="empty-state-text">Bitte die Dienststellenleitung bitten, einen Dienstplan zu erstellen.</p>
                            <?php if ($currentUser->isAdmin()): ?>
                                <a href="admin.php" class="mt-2 inline-flex items-center px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold uppercase transition-all shadow-sm">
                                    Dienstplan erstellen
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="space-y-2 anim-stagger">
                            <?php
                            // Intelligente Anzeige: 2 vergangene + aktuell + 2 künftige; bei show_all=1 alle
                            $showAll = Html::q('show_all') === '1';
                            if ($showAll) {
                                $displayBlocks = $allBlocks; // Wirklich ALLE Blöcke
                                $hasMore = false;
                            } else {
                                usort($filteredBlocks, function($a, $b) {
                                return $a->getStartTimestamp() <=> $b->getStartTimestamp();
                            });
                            $currentIndex = -1;
                            foreach ($filteredBlocks as $i => $b) {
                                if ($activeBlock && $b->getId() === $activeBlock->getId()) {
                                    $currentIndex = $i;
                                }
                            }
                            $startIndex = max(0, $currentIndex - 2);
                            $endIndex = min(count($filteredBlocks) - 1, $currentIndex + 2);
                            if ($currentIndex === -1) {
                                // Kein aktiver Block: zeige die nächsten 5 Blöcke
                                $startIndex = 0;
                                $endIndex = min(4, count($filteredBlocks) - 1);
                            }
                            $displayBlocks = array_slice($filteredBlocks, $startIndex, $endIndex - $startIndex + 1, true);
                            $hasMore = ($startIndex > 0) || ($endIndex < count($filteredBlocks) - 1);
                            }
                            ?>
                            <?php foreach ($displayBlocks as $block):
                                $isOwn = $block->getAssignedUserId() === $currentUser->getId();
                                $isActive = ($activeBlock && $activeBlock->getId() === $block->getId());
                                $isPast = $block->getEndTimestamp() < $now;
                                $netHours = ScheduleService::calculateActiveHours($block->getStartTimestamp(), $block->getEndTimestamp());
                                $status = $block->getStatus();

                                $borderClass = 'border-slate-200';
                                $bgClass = 'bg-white';
                                if ($isActive) {
                                    $borderClass = 'border-indigo-300';
                                    $bgClass = 'bg-indigo-50/30';
                                } elseif ($isOwn && $status === 'trade_open') {
                                    $borderClass = 'border-amber-300';
                                    $bgClass = 'bg-amber-50/20';
                                } elseif ($isOwn && $status === 'trade_pending') {
                                    $borderClass = 'border-violet-300';
                                    $bgClass = 'bg-violet-50/20';
                                } elseif ($isOwn) {
                                    $borderClass = 'border-emerald-300';
                                    $bgClass = 'bg-emerald-50/20';
                                } elseif ($status === 'trade_open') {
                                    $borderClass = 'border-amber-200';
                                } elseif ($status === 'trade_pending') {
                                    $borderClass = 'border-violet-200';
                                }

                                $badgeClass = 'bg-slate-100 border-slate-200 text-slate-600';
                                $badgeText = 'Geplant';
                                if ($isActive) {
                                    $badgeClass = 'bg-indigo-100 border-indigo-200 text-indigo-700';
                                    $badgeText = 'Aktiv';
                                } elseif ($isPast && $status === 'assigned') {
                                    $badgeClass = 'bg-emerald-100 border-emerald-200 text-emerald-700';
                                    $badgeText = '✓ Erledigt';
                                } elseif ($isOwn && $status === 'assigned') {
                                    $badgeClass = 'bg-emerald-100 border-emerald-200 text-emerald-700';
                                    $badgeText = 'Mein Dienst';
                                } elseif ($status === 'trade_open') {
                                    $badgeClass = 'bg-amber-100 border-amber-200 text-amber-700';
                                    $badgeText = 'Tausch offen';
                                } elseif ($status === 'trade_pending') {
                                    $badgeClass = 'bg-violet-100 border-violet-200 text-violet-700';
                                    $badgeText = 'Wartet auf Freigabe';
                                }
                            ?>
                                <div class="rounded-xl border <?= $borderClass ?> <?= $bgClass ?> p-3 transition-all hover:shadow-sm">
                                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                        <div class="flex items-center gap-2.5 min-w-0">
                                            <span class="h-7 w-7 rounded-full flex items-center justify-center flex-shrink-0 <?= $isActive ? 'bg-indigo-600 text-white' : ($isOwn ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500') ?>">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                            </span>
                                            <div class="min-w-0">
                                                <div class="text-sm font-semibold text-slate-900 truncate"><?= $block->getFormattedStart() ?> – <?= $block->getFormattedEnd() ?></div>
                                                <div class="text-xs text-slate-500 truncate">
                                                    <?= htmlspecialchars($userMap[$block->getAssignedUserId()] ?? 'Unbekannt') ?>
                                                    · <?= number_format($netHours, 1, ',', '') ?>h
                                                </div>
                                            </div>
                                        </div>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase border <?= $badgeClass ?> flex-shrink-0 self-start sm:self-center"><?= $badgeText ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($filteredBlocks) > 6): ?>
                        <div class="mt-3 pt-3 border-t border-slate-100">
                            <a href="dashboard.php?show_all=1" class="inline-flex items-center gap-2 text-xs font-semibold text-indigo-600 hover:text-indigo-700 transition-colors">
                                <span>Alle <?= count($filteredBlocks) ?> Blöcke anzeigen</span>
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </a>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Column 3: Sidebar -->
            <div class="space-y-6">

                <!-- My Summary Card -->
                <div class="glass-card rounded-3xl p-6">
                    <h2 class="text-sm font-bold text-slate-900 mb-4 font-display">Meine Übersicht</h2>

                    <?php if ($currentUser->isAdmin()): ?>
                        <div class="text-center py-6">
                            <svg class="w-12 h-12 mx-auto text-slate-200 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <p class="text-sm text-slate-500 font-medium">Administrator</p>
                            <p class="text-xs text-slate-400 mt-1">Sie nehmen nicht am Bereitschaftsdienst teil.</p>
                        </div>
                    <?php elseif (empty($myBlocks)): ?>
                        <div class="text-center py-6">
                            <svg class="w-12 h-12 mx-auto text-slate-200 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                            <p class="text-sm text-slate-500 font-medium">Keine zugewiesenen Dienste</p>
                            <p class="text-xs text-slate-400 mt-1">Sie wurden noch nicht für den Bereitschaftsdienst eingeteilt.</p>
                        </div>
                    <?php else: ?>
                        <!-- Stats -->
                        <div class="grid grid-cols-2 gap-3 mb-4">
                            <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 text-center">
                                <div class="text-lg font-bold text-slate-900" data-countup="<?= count($myBlocks) ?>" data-decimals="0"><?= count($myBlocks) ?></div>
                                <div class="text-[10px] text-slate-500 uppercase tracking-wider font-semibold">Blöcke</div>
                            </div>
                            <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 text-center">
                                <div class="text-lg font-bold text-slate-900" data-countup="<?= $totalMyNetHours ?>" data-decimals="1"><?= number_format($totalMyNetHours, 1, ',', '') ?></div>
                                <div class="text-[10px] text-slate-500 uppercase tracking-wider font-semibold">Netto-Std.</div>
                            </div>
                        </div>

                        <!-- Next Block + Progress Bar -->
                        <?php if ($nextMyBlock):
                            $nbHours = ScheduleService::calculateActiveHours(
                                $nextMyBlock->getStartTimestamp(),
                                $nextMyBlock->getEndTimestamp()
                            );
                            $daysUntil = max(0, ceil(($nextMyBlock->getStartTimestamp() - $now) / 86400));
                        ?>
                            <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-200 mb-3">
                                <div class="text-[10px] text-emerald-600 font-bold uppercase tracking-wider">Nächster Dienst</div>
                                <div class="text-sm font-bold text-slate-900 mt-1"><?= $nextMyBlock->getFormattedStart() ?></div>
                                <div class="text-xs text-slate-600">in <?= $daysUntil ?> Tagen · <?= number_format($nbHours, 1, ',', '') ?>h</div>
                                <!-- Progress bar -->
                                <?php if ($activeProgressPct !== null): ?>
                                    <div class="mt-2">
                                        <div class="flex justify-between text-[10px] text-slate-500 mb-0.5">
                                            <span><?= $activeProgressLabel ?></span>
                                            <span><?= $activeProgressPct ?>%</span>
                                        </div>
                                        <div class="h-1.5 w-full rounded-full bg-slate-200 overflow-hidden">
                                            <div class="h-full rounded-full bg-indigo-500 transition-all" style="width: <?= $activeProgressPct ?>%"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- All my blocks (compact list) -->
                        <div class="space-y-1.5">
                            <?php foreach ($myBlocks as $block):
                                $isActive = ($activeBlock && $activeBlock->getId() === $block->getId());
                                $netHours = ScheduleService::calculateActiveHours($block->getStartTimestamp(), $block->getEndTimestamp());
                                $status = $block->getStatus();
                                $colorDot = ($status === 'trade_open') ? 'bg-amber-400' : (($status === 'trade_pending') ? 'bg-violet-400' : ($isActive ? 'bg-indigo-500' : 'bg-emerald-400'));
                            ?>
                                <div class="flex items-center gap-2 p-2 rounded-xl <?= $isActive ? 'bg-indigo-50 border border-indigo-200' : 'bg-white border border-slate-200' ?>">
                                    <span class="h-2 w-2 rounded-full <?= $colorDot ?> flex-shrink-0"></span>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-[11px] font-semibold text-slate-800 truncate"><?= $block->getFormattedStart() ?> – <?= $block->getFormattedEnd() ?></div>
                                        <div class="text-[10px] text-slate-500"><?= number_format($netHours, 1, ',', '') ?>h</div>
                                    </div>
                                    <?php if ($status === 'assigned' && !$currentUser->isAdmin() && $block->getEndTimestamp() > $now): ?>
                                        <form action="dashboard.php" method="POST" class="flex-shrink-0">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="offer_trade">
                                            <input type="hidden" name="block_id" value="<?= htmlspecialchars($block->getId()) ?>">
                                            <button type="submit" class="px-2 py-1 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-[10px] font-bold uppercase transition-all">
                                                Tauschen
                                            </button>
                                        </form>
                                    <?php elseif ($status === 'trade_open'): ?>
                                        <span class="px-2 py-1 rounded-lg bg-amber-50 text-amber-700 text-[10px] font-bold flex-shrink-0">Offen</span>
                                    <?php elseif ($status === 'trade_pending'): ?>
                                        <span class="px-2 py-1 rounded-lg bg-violet-50 text-violet-700 text-[10px] font-bold flex-shrink-0">Wartet</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Dienstauskunft: who was/is on duty? -->
                <div class="glass-card rounded-3xl p-5">
                    <h2 class="text-xs font-bold text-slate-800 mb-3 font-display">Dienstauskunft</h2>
                    <p class="text-[11px] text-slate-500 mb-3">Wer hatte zu einem Zeitpunkt Dienst?</p>
                    <form action="dashboard.php" method="GET" class="space-y-2">
                        <div class="grid grid-cols-2 gap-2">
                            <input type="date" name="aq_date" aria-label="Datum für die Dienstauskunft" value="<?= htmlspecialchars($aqDateInput !== '' ? $aqDateInput : $todayYmd) ?>"
                                   class="w-full px-2 py-2 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                            <input type="time" name="aq_time" aria-label="Uhrzeit für die Dienstauskunft" value="<?= htmlspecialchars($aqTimeInput !== '' ? $aqTimeInput : date('H:i', $now)) ?>"
                                   class="w-full px-2 py-2 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                        </div>
                        <button type="submit" class="w-full py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 text-xs font-bold uppercase rounded-lg transition-all shadow-sm">Auskunft</button>
                    </form>
                    <?php if ($aqError): ?>
                        <p class="mt-3 text-xs text-rose-600"><?= htmlspecialchars($aqError) ?></p>
                    <?php elseif ($aqResult !== null):
                        $aqB = $aqResult['block'];
                        $aqU = $aqResult['user'];
                        $aqTs = $aqResult['ts'];

                        // Phase ermitteln
                        $aqPhase = ScheduleService::resolveCurrentPhase(
                            $aqB->getStartTimestamp(),
                            $aqB->getEndTimestamp(),
                            $aqTs
                        );
                    ?>
                        <div class="mt-3 p-3 rounded-xl bg-indigo-50 border border-indigo-200 flex items-center gap-2 flex-wrap">
                            <?php if ($aqPhase === 'innendienst'): ?>
                                <!-- Bei Innendienst: Nur das Label, kein Name -->
                                <span class="inline-flex items-center px-3 py-1 rounded-lg bg-amber-100 border border-amber-200 text-amber-800 text-sm font-bold uppercase whitespace-nowrap">Innendienst</span>
                            <?php else: ?>
                                <!-- Bei Bereitschaft: Nur der Name -->
                                <div class="text-sm font-bold text-slate-900">
                                    <?= htmlspecialchars($aqU !== null ? $aqU->getName() : 'Nicht zugeordnet') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Mein Konto: eigenes Passwort ändern -->
                <div class="glass-card rounded-3xl p-5">
                    <h2 class="text-xs font-bold text-slate-800 mb-3 font-display"><?= htmlspecialchars(Translator::t('dashboard.password.heading')) ?></h2>
                    <form action="dashboard.php" method="POST" class="space-y-3">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="change_own_password">
                        <div>
                            <label for="current_password" class="block text-[10px] font-semibold uppercase text-slate-500 mb-1"><?= htmlspecialchars(Translator::t('dashboard.password.current')) ?></label>
                            <input type="password" name="current_password" id="current_password" required autocomplete="current-password"
                                   class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                        </div>
                        <div>
                            <label for="new_password" class="block text-[10px] font-semibold uppercase text-slate-500 mb-1"><?= htmlspecialchars(Translator::t('dashboard.password.new')) ?></label>
                            <input type="password" name="new_password" id="new_password" required minlength="12" autocomplete="new-password"
                                   aria-describedby="pw-policy" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                            <p id="pw-policy" class="text-[10px] text-slate-400 mt-1"><?= htmlspecialchars(Translator::t('dashboard.password.policy')) ?></p>
                        </div>
                        <div>
                            <label for="confirm_password" class="block text-[10px] font-semibold uppercase text-slate-500 mb-1"><?= htmlspecialchars(Translator::t('dashboard.password.confirm')) ?></label>
                            <input type="password" name="confirm_password" id="confirm_password" required minlength="12" autocomplete="new-password"
                                   class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                        </div>
                        <button type="submit" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold uppercase rounded-xl transition-all shadow-md"><?= htmlspecialchars(Translator::t('dashboard.password.submit')) ?></button>
                    </form>
                </div>

                <!-- Month Calendar Widget -->
                <div class="glass-card rounded-3xl p-5">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-xs font-bold text-slate-800 font-display"><?= $germanMonths[$calMonth] ?> <?= $calYear ?></h2>
                        <div class="flex items-center gap-1">
                            <a href="?cal_month=<?= $prevMonth ?>&cal_year=<?= $prevYear ?>" class="p-1.5 rounded-lg hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition-all">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                            </a>
                            <a href="?cal_month=<?= $nextMonth ?>&cal_year=<?= $nextYear ?>" class="p-1.5 rounded-lg hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition-all">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </a>
                        </div>
                    </div>
                    <table class="hidden sm:table w-full table-fixed text-center border-collapse">
                        <caption class="sr-only">Diensttage im Monat <?= htmlspecialchars($germanMonths[$calMonth]) ?> <?= $calYear ?></caption>
                        <thead>
                            <tr>
                                <?php $wdays = ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag']; ?>
                                <?php foreach ($wdays as $wd): ?>
                                    <th scope="col" class="text-[10px] text-slate-400 font-semibold py-1"><span aria-hidden="true"><?= substr($wd, 0, 2) ?></span><span class="sr-only"><?= $wd ?></span></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $calendarRows = array_fill(0, $startDow - 1, null); // leading blanks
                            for ($d = 1; $d <= $daysInMonth; $d++) {
                                $calendarRows[] = $d;
                            }
                            foreach (array_chunk($calendarRows, 7) as $week):
                                ?>
                                <tr>
                                    <?php foreach ($week as $dayNumber):
                                        if ($dayNumber === null): ?>
                                        <td></td>
                                        <?php continue;
                                        endif;
                                        $dk = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $dayNumber);
                                        $hasDuty = isset($myDutyDays[$dk]);
                                        $isToday = ($dk === $todayYmd);
                                        if ($isToday && $hasDuty):
                                            $cellClass = 'rounded-full w-7 h-7 mx-auto text-xs bg-indigo-600 text-white font-bold';
                                        elseif ($isToday):
                                            $cellClass = 'rounded-full w-7 h-7 mx-auto text-xs bg-indigo-100 text-indigo-700 font-bold';
                                        elseif ($hasDuty):
                                            $cellClass = 'rounded-full w-7 h-7 mx-auto text-xs bg-emerald-100 text-emerald-800 font-semibold';
                                        else:
                                            $cellClass = 'rounded-full w-7 h-7 mx-auto text-xs text-slate-600 hover:bg-slate-100 transition-all';
                                        endif;
                                    ?>
                                    <td class="py-0.5">
                                        <time datetime="<?= $dk ?>" class="<?= $cellClass ?> flex items-center justify-center"<?= $hasDuty ? ' aria-label="' . (new DateTimeImmutable($dk))->format('j. F Y') . ', Diensttag"' : '' ?>><?= $dayNumber ?></time>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <!-- Mobile: Compact duty-day list -->
                    <div class="sm:hidden">
                        <?php
                        $dutyDaysInMonth = [];
                        foreach ($calDayKeys as $dk) {
                            if (isset($myDutyDays[$dk])) {
                                $dutyDaysInMonth[] = $dk;
                            }
                        }
                        ?>
                        <?php if (empty($dutyDaysInMonth)): ?>
                            <p class="text-xs text-slate-400 text-center py-4">Keine Dienste in diesem Monat</p>
                        <?php else: ?>
                            <div class="space-y-1.5">
                                <?php foreach ($dutyDaysInMonth as $dk):
                                    $dDate = new \DateTimeImmutable($dk);
                                    $isTodayMobile = ($dk === $todayYmd);
                                ?>
                                    <div class="flex items-center gap-3 p-2 rounded-lg <?= $isTodayMobile ? 'bg-indigo-50 border border-indigo-200' : 'bg-emerald-50 border border-emerald-200' ?>">
                                        <span class="text-xs font-bold <?= $isTodayMobile ? 'text-indigo-700' : 'text-emerald-700' ?> w-20 flex-shrink-0">
                                            <?= $dDate->format('d.m.') ?>
                                            <?= ['Mo','Di','Mi','Do','Fr','Sa','So'][(int)$dDate->format('N') - 1] ?>
                                        </span>
                                        <span class="text-[10px] <?= $isTodayMobile ? 'text-indigo-600' : 'text-emerald-600' ?>">
                                            <?= $isTodayMobile ? 'Heute – Ihr Dienst' : 'Ihr Dienst' ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-3 pt-3 border-t border-slate-100 flex items-center gap-4 text-[10px] text-slate-500">
                        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-indigo-600"></span> Heute</span>
                        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-emerald-300"></span> Ihr Dienst</span>
                    </div>
                </div>

                <!-- Quick Access -->
                <div class="glass-card rounded-3xl p-5 space-y-3">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Schnellzugriff</h2>
                    <a href="marketplace.php" class="flex items-center justify-between p-3 rounded-xl bg-white hover:bg-slate-50 transition-all border border-slate-200 shadow-sm">
                        <span class="text-sm text-slate-700 font-medium">Tauschangebote</span>
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                    </a>
                    <?php if ($currentUser->isAdmin()): ?>
                        <a href="admin.php" class="flex items-center justify-between p-3 rounded-xl bg-indigo-50/50 hover:bg-indigo-50 transition-all border border-indigo-100 hover:border-indigo-200 shadow-sm">
                            <span class="text-sm text-indigo-700 font-medium font-semibold">Admin-Konsole</span>
                            <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Dienste-Verteilung (Woche) -->
                <?php
                $weekStart = ScheduleService::getDutyWeekStartForTimestamp($now);
                $weekEnd = $weekStart + (7 * 86400);
                $weekBlocks = array_filter($allBlocks, fn($b) => $b->getStartTimestamp() >= $weekStart && $b->getStartTimestamp() < $weekEnd);
                if (!empty($weekBlocks)):
                    $weekUserHours = [];
                    foreach ($weekBlocks as $wb) {
                        $uid = $wb->getAssignedUserId();
                        $hours = ScheduleService::calculateActiveHours($wb->getStartTimestamp(), $wb->getEndTimestamp());
                        $weekUserHours[$uid] = ($weekUserHours[$uid] ?? 0) + $hours;
                    }
                    $maxHours = max($weekUserHours);
                    if ($maxHours > 0):
                ?>
                <div class="glass-card rounded-3xl p-5">
                    <h2 class="text-xs font-bold text-slate-800 mb-3 font-display">Diese Woche</h2>
                    <div class="space-y-2">
                        <?php foreach ($weekUserHours as $uid => $hours):
                            $name = $userMap[$uid] ?? '?';
                            $pct = round(($hours / $maxHours) * 100);
                            $isOwnWeek = ($uid === $currentUser->getId());
                        ?>
                            <div>
                                <div class="flex justify-between items-center mb-0.5">
                                    <span class="text-[11px] font-semibold <?= $isOwnWeek ? 'text-indigo-700' : 'text-slate-700' ?>"><?= htmlspecialchars($name) ?></span>
                                    <span class="text-[10px] font-bold text-slate-500"><?= number_format($hours, 1, ',', '') ?>h</span>
                                </div>
                                <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-500 <?= $isOwnWeek ? 'bg-indigo-500' : 'bg-slate-300' ?>" style="width: <?= $pct ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
                    endif;
                endif;
                ?>

            </div>

        </div>

    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>


</body>
</html>
