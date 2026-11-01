<?php
require_once __DIR__ . '/../src/bootstrap.php';

use Src\Auth\AuthManager;
use Src\Core\Config;
use Src\Core\Csrf;
use Src\Core\Flash;
use Src\Core\Html;
use Src\Service\ScheduleService;
use Src\Service\ShiftService;
use Src\Service\TradeService;
use Src\Service\BillingService;
use Src\Service\AuditService;
use Src\Service\AbsenceService;
use Src\Service\SnapshotService;
use Src\Model\AuditEntry;

$auth = new AuthManager();
$auth->requireAdmin();

$currentUser = $auth->getRequiredUser();
$scheduleService = new ScheduleService();
$tradeService = new TradeService();
$auditService = new AuditService();
$billingService = new BillingService();
$absenceService = new AbsenceService();
$snapshotService = new SnapshotService();

$error = null;
$success = null;

// Current tab. Was a bare preg_replace('/[^a-z]/') which silently destroyed
// mixed-case input (?tab=Plan became 'lan'), matched no panel and rendered an
// entirely blank admin page. Normalise case first, then whitelist.
const ADMIN_TABS = ['plan', 'trades', 'users', 'billing', 'profile', 'audit', 'nachweise', 'system', 'settings'];
$tab = Html::token(Html::q('tab', 'plan'), ADMIN_TABS, 'plan');

/**
 * Years offered in the schedule generator: the running year up to +5.
 *
 * Planning ahead has to be possible (the year turns over long before the
 * roster for it is due), but the window stays bounded so nobody overwrites
 * historical blocks or plans a nonsense year.
 *
 * @return int[]
 */
function planYearOptions(int $min, int $max): array
{
    return range($min, $max);
}

// Flash messages for Post/Redirect/Get (keys only – resolved via Flash::consume)
$okMessages = [
    'schedule_generated' => 'Dienstplan erfolgreich generiert und zugeordnet. Bestehende Blöcke in diesem Zeitraum wurden überschrieben.',
    'coverage_repaired' => 'Dienstplan auf lückenlose 24/7-Abdeckung gebracht. Zuständigkeiten unverändert, nur Blockgrenzen angeglichen.',
    'repair_failed' => 'Fehler beim Angleichen der Dienstblöcke.',
    'assigned' => 'Dienstblock erfolgreich neu zugeordnet.',
    'trade_approved' => 'Tauschantrag erfolgreich genehmigt. Dienstplan wurde aktualisiert.',
    'trade_rejected' => 'Tauschantrag wurde abgelehnt und die Schicht zurückgesetzt.',
    'schedule_reset' => 'Gesamter Dienstplan wurde erfolgreich zurückgesetzt.',
    'user_added' => 'Mitarbeiter wurde erfolgreich angelegt.',
    'user_updated' => 'Mitarbeiter-Daten wurden erfolgreich aktualisiert.',
    'user_deleted' => 'Mitarbeiter wurde erfolgreich gelöscht. Zugehörige Dienste und Tauschanträge wurden bereinigt.',
    'pw_changed' => 'Passwort erfolgreich geändert.',
    'absence_added' => 'Abwesenheit wurde eingetragen und wird bei künftigen Generierungen berücksichtigt.',
    'absence_deleted' => 'Abwesenheit wurde gelöscht.',
    'snapshot_created' => 'Dienstplan wurde versiegelt (Nachweis).',
    'settings_saved' => 'Einsatzzeiten wurden erfolgreich gespeichert.',
];
$errMessages = [
    'schedule_input' => 'Bitte einen Zeitraum wählen und mindestens einen Mitarbeiter auswählen.',
    'schedule_invalid' => 'Ungültiger Zeitraum ausgewählt.',
    'schedule_year_invalid' => 'Bitte ein gültiges Planungsjahr wählen.',
    'schedule_year_range' => 'Das Planungsjahr liegt nicht im zulässigen Bereich (aktuelles Jahr bis +5).',
    'schedule_failed' => 'Fehler bei der Generierung des Dienstplans.',
    'schedule_period_past' => 'Der ausgewählte Zeitraum liegt bereits in der Vergangenheit. Bitte wählen Sie ein späteres Halbjahr.',
    'assign_failed' => 'Fehler bei der manuellen Zuordnung.',
    'trade_approve_failed' => 'Fehler beim Genehmigen des Tausches.',
    'trade_reject_failed' => 'Fehler beim Ablehnen des Tausches.',
    'reset_failed' => 'Fehler beim Zurücksetzen des Dienstplans.',
    'user_fields' => 'Bitte füllen Sie alle Pflichtfelder aus (Name, Benutzername, Passwort).',
    'pw_weak' => 'Passwort entspricht nicht den Anforderungen (min. 12 Zeichen, Großbuchstabe, Ziffer, Sonderzeichen).',
    'user_email' => 'Bitte eine gültige E-Mail-Adresse eingeben (oder leer lassen).',
    'user_admin_exists' => 'Es existiert bereits ein Administrator – es ist genau ein Administrator vorgesehen.',
    'user_add_failed' => 'Fehler beim Anlegen. Möglicherweise existiert der Benutzername bereits.',
    'user_name_empty' => 'Name und Benutzername dürfen nicht leer sein.',
    'user_dup' => 'Fehler beim Aktualisieren. Der Benutzername ist bereits vergeben.',
    'user_policy' => 'Nicht zulässig: Es muss genau einen Administrator geben – Rollenänderung verworfen.',
    'user_update_failed' => 'Fehler beim Aktualisieren.',
    'user_delete_admin' => 'Der Administrator kann nicht gelöscht werden – es muss genau einen Administrator geben.',
    'user_delete_failed' => 'Fehler beim Löschen. Sie können sich nicht selbst löschen.',
    'pw_mismatch' => 'Die neuen Passwörter stimmen nicht überein.',
    'pw_wrong' => 'Das aktuelle Passwort ist falsch.',
    'absence_need_user' => 'Bitte einen Mitarbeiter wählen.',
    'absence_bad_date' => 'Bitte gültige Datumsangaben wählen.',
    'absence_bad_range' => 'Das Enddatum darf nicht vor dem Startdatum liegen.',
    'absence_bad_reason' => 'Bitte einen gültigen Grund wählen.',
    'absence_save_failed' => 'Speichern fehlgeschlagen.',
    'absence_not_found' => 'Abwesenheit nicht gefunden.',
    'snapshot_bad_month' => 'Bitte einen gültigen Monat wählen.',
    'snapshot_exists' => 'Für diesen Monat existiert bereits eine Versiegelung.',
    'snapshot_save_failed' => 'Speichern fehlgeschlagen.',
    'settings_invalid' => 'Einstellungen konnten nicht gespeichert werden – bitte prüfen Sie die eingegebenen Zeiten.',
];
Flash::consume($success, $error, $okMessages, $errMessages);

// Ergaenzung zur Bestaetigung: welche Periode wurde tatsaechlich geplant?
// Der ok-Key allein sagt das nicht, und bei mehrjaehriger Planung ist die
// Frage "welches Jahr?" nach dem Klick die erste Frage.
$planYearMin = (int) date('Y');
$planYearMax = $planYearMin + 5;

// Jahr-Persistenz: Query-String > Session > aktuelles Jahr.
// Html::qi() rejects arrays (`?plan_year[]=1`) instead of letting ctype_digit()
// throw a TypeError and turn the request into a 500.
$planYearSelected = Html::qi('plan_year', 0, 2000, 2100);
if ($planYearSelected > 0) {
    $_SESSION['plan_year'] = $planYearSelected;
} elseif (isset($_SESSION['plan_year']) && is_int($_SESSION['plan_year'])) {
    $planYearSelected = $_SESSION['plan_year'];
} else {
    $planYearSelected = (int) date('Y');
}
$planYearSelected = max($planYearMin, min($planYearMax, $planYearSelected));
$_SESSION['plan_year'] = $planYearSelected;

// Halbjahr-Persistenz: nach einer Generierung steht es im Redirect, sonst gilt
// das aktuell laufende Kalenderhalbjahr. Der PDF-Export markiert damit das
// richtige Halbjahr, statt immer "1. HJ" zu aktivieren.
$currentPeriod = (int) date('n') <= 6 ? 'h1' : 'h2';
$sessionPeriod = $_SESSION['plan_period'] ?? $currentPeriod;
$planPeriodSelected = Html::token(
    Html::q('plan_period', is_string($sessionPeriod) ? $sessionPeriod : $currentPeriod),
    ['h1', 'h2'],
    $currentPeriod
);
$_SESSION['plan_period'] = $planPeriodSelected;

if ($success !== null && $plannedYear = Html::qi('plan_year', 0, 2000, 2100)) {
    $plannedPeriod = Html::token(Html::q('plan_period'), ['h1', 'h2'], '');
    $halfLabel = match ($plannedPeriod) {
        'h1' => '1. Halbjahr',
        'h2' => '2. Halbjahr',
        default => null,
    };
    if ($halfLabel !== null) {
        $success .= ' Geplant wurde: ' . $halfLabel . ' ' . $plannedYear . '.';
    }
}

// Handle Billing CSV Export before rendering HTML (audit export is handled separately below)
if (Html::q('export') === 'csv' && Html::q('tab') !== 'audit') {
    [$startTs, $endTs] = BillingService::parseBillingRange(
        Html::qDate('start') ?: null,
        Html::qDate('end') ?: null
    );

    $summary = $billingService->generateSummary($startTs, $endTs);
    $periodLabel = date('d.m.Y', $startTs) . ' bis ' . date('d.m.Y', $endTs);
    $csv = $billingService->exportToCsv($summary, $periodLabel);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="PsychKG_Bereitschaft_Abrechnung_' . date('Ymd') . '.csv"');
    echo $csv;
    exit;
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::requireValid();
    $action = Html::p('action');

    if ($action === 'generate_schedule') {
        $period = Html::p('period');
        $employees = (isset($_POST['employees']) && is_array($_POST['employees']))
            ? array_values(array_filter($_POST['employees'], 'is_scalar'))
            : [];
        $redirect = 'admin.php?tab=' . $tab;

        if (empty($period) || empty($employees)) {
            Flash::redirect($redirect, null, 'schedule_input');
        }

        // Planungsjahr. Bewusst nicht aus date('Y') abgeleitet: das Jahr
        // neigt sich dem Ende zu, die Planung fuer 2027 muss jetzt laufen.
        // Begrenzt auf laufendes Jahr bis +5, damit niemand versehentlich
        // Historie ueberschreibt oder ein sinnloses Jahr plant.
        $planYearMin = (int) date('Y');
        $planYearMax = $planYearMin + 5;
        $yearRaw = Html::p('year');
        if (!preg_match('/^\d{4}$/', $yearRaw)) {
            Flash::redirect($redirect, null, 'schedule_year_invalid');
        }
        $planYear = (int) $yearRaw;
        if ($planYear < $planYearMin || $planYear > $planYearMax) {
            Flash::redirect($redirect . '&plan_year=' . $planYearMin, null, 'schedule_year_range');
        }

        if ($period === 'h1') {
            $startDate = $planYear . '-01-01';
            // 0 = "so viele Wochen wie nötig, um das Halbjahr lückenlos
            // abzudecken". Eine feste 26 war nicht immer genug: Für H2 2026
            // (1. Juli ist bereits ein Mittwoch) fehlten dadurch der 31.12.
            // und die ersten Tage des Folgejahres.
            $weeks = 0;
        } elseif ($period === 'h2') {
            $startDate = $planYear . '-07-01';
            $weeks = 0;
        } else {
            Flash::redirect($redirect, null, 'schedule_invalid');
        }

        // Pre-check: refuse fully past periods with a clear message instead of
        // a generic failure from generateWeeks(). The *running* half-year is an
        // exception: planning it in week 3 must start at the current duty week
        // (the part that is already history cannot be re-planned).
        $currentPeriod = (int) date('n') <= 6 ? 'h1' : 'h2';
        $isRunningHalfYear = ((int) $planYear === (int) date('Y')) && $period === $currentPeriod;

        $requestedStart = (int) strtotime($startDate);
        $dutyWeekStart = ScheduleService::getDutyWeekStartForTimestamp(time());
        if ($requestedStart < $dutyWeekStart) {
            if (!$isRunningHalfYear) {
                Flash::redirect($redirect, null, 'schedule_period_past');
            }
            $startDate = date('Y-m-d', $dutyWeekStart);
            $requestedStart = $dutyWeekStart;
        }

        // Same start as generateWeeks(): a date that already is a Wednesday is
        // kept there, so 'next Wednesday' would undercount by one.
        $planStart = ScheduleService::nextDutyStartAfter(
            (new DateTimeImmutable($startDate, new DateTimeZone(Config::getTimezone())))
                ->setTime(0, 0, 0)->getTimestamp()
        );

        if ($scheduleService->generateWeeks($startDate, $weeks, $employees, true)) {
            // Actual number of weeks, for the audit trail.
            $weeksPlanned = ScheduleService::weeksToCover(
                $planStart,
                $period === 'h1'
                    ? (int) strtotime($planYear . '-07-01 00:00:00')
                    : (int) strtotime($planYear . '-12-31 23:59:59')
            );
            $auditService->log(AuditEntry::SCHEDULE_GENERATED, 'schedule', 'auto', [
                'period' => $period,
                'year' => $planYear,
                'weeks' => $weeksPlanned,
                'employees' => count($employees),
            ]);
            Flash::redirect($redirect . '&ok=schedule_generated&plan_year=' . $planYear
                . '&plan_period=' . $period . '&plan_weeks=' . $weeksPlanned);
        }
        Flash::redirect($redirect, null, 'schedule_failed');
    } elseif ($action === 'repair_coverage') {
        // Vorhandene Bloecke auf eine lueckenlose Kette naehen und den Rest
        // des Jahres fairst verlaengern. Zustaendigkeiten der bestehenden
        // Bloecke werden nicht angefasst, nur die Blockgrenzen ruecken nach.
        $redirect = 'admin.php?tab=' . $tab;
        $employees = $auth->getDutyEligibleUsers();
        $allBlocks = $scheduleService->getAllBlocks();
        $last = !empty($allBlocks) ? $allBlocks[count($allBlocks) - 1] : null;
        $targetEnd = 0;
        if ($last !== null) {
            $targetYear = (int)(new DateTimeImmutable('@' . $last->getStartTimestamp()))
                ->setTimezone(new DateTimeZone(Config::getTimezone()))->format('Y');
            // Ziel: der erste Mittwoch 16:00 nach dem 1.1. des Folgejahres.
            // Damit sind der 31.12. und der Jahresanfang durchgehend besetzt,
            // ohne eine ganze Woche zu viel zu planen.
            $targetEnd = ScheduleService::nextDutyStartAfter(
                (int) strtotime(($targetYear + 1) . '-01-01 00:00:00')
            );
        }
        $result = $scheduleService->repairCoverageAndExtend($employees, $targetEnd);
        if (!empty($result['written'])) {
            $auditService->log(AuditEntry::SCHEDULE_REPAIRED, 'schedule', 'coverage', [
                'blocks' => $result['checked'],
                'stitched' => $result['stitched'],
                'extended' => $result['extended'],
                'added_weeks' => $result['added'],
            ]);
            Flash::redirect($redirect, 'coverage_repaired');
        }
        Flash::redirect($redirect, null, 'repair_failed');
    } elseif ($action === 'manual_assign') {
        $blockId = Html::p('block_id');
        $userId = Html::p('user_id');
        $redirect = 'admin.php?tab=' . $tab;
        if ($scheduleService->assignUserToBlock($blockId, $userId)) {
            $auditService->log(AuditEntry::BLOCK_REASSIGNED, 'block', $blockId, ['user_id' => $userId]);
            Flash::redirect($redirect, 'assigned');
        }
        Flash::redirect($redirect, null, 'assign_failed');
    } elseif ($action === 'approve_trade') {
        $tradeId = Html::p('trade_id');
        $redirect = 'admin.php?tab=' . $tab;
        if ($tradeService->approveTrade($tradeId)) {
            $auditService->log(AuditEntry::TRADE_APPROVED, 'trade', $tradeId);
            Flash::redirect($redirect, 'trade_approved');
        }
        Flash::redirect($redirect, null, 'trade_approve_failed');
    } elseif ($action === 'reject_trade') {
        $tradeId = Html::p('trade_id');
        $redirect = 'admin.php?tab=' . $tab;
        if ($tradeService->rejectTrade($tradeId)) {
            $auditService->log(AuditEntry::TRADE_REJECTED, 'trade', $tradeId);
            Flash::redirect($redirect, 'trade_rejected');
        }
        Flash::redirect($redirect, null, 'trade_reject_failed');
    } elseif ($action === 'reset_schedule') {
        $redirect = 'admin.php?tab=' . $tab;
        if ($scheduleService->clearAllBlocks()) {
            $auditService->log(AuditEntry::SCHEDULE_RESET, 'schedule', 'all');
            Flash::redirect($redirect, 'schedule_reset');
        }
        Flash::redirect($redirect, null, 'reset_failed');
    } elseif ($action === 'add_user') {
        $newUsername = Html::p('new_username');
        $newPassword = Html::pRaw('new_password');
        $newName = Html::p('new_name');
        $newRole = Html::p('new_role', 'mitarbeiter');
        $newPhone = Html::p('new_phone');
        $newEmail = Html::p('new_email');
        $redirect = 'admin.php?tab=' . $tab;

        if (empty($newUsername) || empty($newPassword) || empty($newName)) {
            Flash::redirect($redirect, null, 'user_fields');
        } elseif (AuthManager::validatePasswordStrength($newPassword) !== null) {
            Flash::redirect($redirect, null, 'pw_weak');
        } elseif ($newEmail !== '' && filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
            Flash::redirect($redirect, null, 'user_email');
        } elseif ($auth->addUser($newUsername, $newPassword, $newName, $newRole, $newPhone, $newEmail)) {
            $auditService->log(AuditEntry::USER_CREATED, 'user', $newUsername, ['name' => $newName, 'role' => $newRole]);
            Flash::redirect($redirect, 'user_added');
        } elseif (($newRole === 'admin' || $newRole === 'administrator') && $auth->countAdmins() >= 1) {
            Flash::redirect($redirect, null, 'user_admin_exists');
        } else {
            Flash::redirect($redirect, null, 'user_add_failed');
        }
    } elseif ($action === 'update_user') {
        $editUserId = Html::p('edit_user_id');
        $editName = Html::p('edit_name');
        $editUsername = Html::p('edit_username');
        $editRole = Html::p('edit_role', 'mitarbeiter');
        $editPassword = Html::pRaw('edit_password');
        $editPhone = Html::p('edit_phone');
        $editEmail = Html::p('edit_email');

        if (empty($editName) || empty($editUsername)) {
            Flash::redirect('admin.php?tab=' . $tab, null, 'user_name_empty');
        } elseif (!empty($editPassword) && AuthManager::validatePasswordStrength($editPassword) !== null) {
            Flash::redirect('admin.php?tab=' . $tab, null, 'pw_weak');
        } elseif ($editEmail !== '' && filter_var($editEmail, FILTER_VALIDATE_EMAIL) === false) {
            Flash::redirect('admin.php?tab=' . $tab, null, 'user_email');
        } else {
            $pw = !empty($editPassword) ? $editPassword : null;
            if ($auth->updateUser($editUserId, $editName, $editUsername, $editRole, $pw, $editPhone, $editEmail, true)) {
                $auditService->log(AuditEntry::USER_UPDATED, 'user', $editUserId, ['name' => $editName, 'role' => $editRole]);
                Flash::redirect('admin.php?tab=' . $tab, 'user_updated');
            }
            // Distinguish the failure reasons for a helpful message.
            $duplicate = false;
            $policyBlocked = false;
            foreach ($auth->getAllUsers() as $u) {
                if ($u->getId() !== $editUserId
                    && strcasecmp($u->getUsername(), $editUsername) === 0
                ) {
                    $duplicate = true;
                }
                if ($u->getId() === $editUserId) {
                    $wasAdmin = $u->isAdmin();
                    $willBeAdmin = in_array(strtolower(trim($editRole)), ['admin', 'administrator'], true);
                    if (($wasAdmin && !$willBeAdmin) || (!$wasAdmin && $willBeAdmin)) {
                        $policyBlocked = true;
                    }
                }
            }
            if ($duplicate) {
                Flash::redirect('admin.php?tab=' . $tab, null, 'user_dup');
            } elseif ($policyBlocked) {
                Flash::redirect('admin.php?tab=' . $tab, null, 'user_policy');
            } else {
                Flash::redirect('admin.php?tab=' . $tab, null, 'user_update_failed');
            }
        }
    } elseif ($action === 'delete_user') {
        $delUserId = Html::p('delete_user_id');
        $redirect = 'admin.php?tab=' . $tab;
        $delIsAdmin = false;
        foreach ($auth->getAllUsers() as $u) {
            if ($u->getId() === $delUserId && $u->isAdmin()) {
                $delIsAdmin = true;
            }
        }
        if ($delIsAdmin) {
            Flash::redirect($redirect, null, 'user_delete_admin');
        } elseif ($auth->deleteUser($delUserId)) {
            $scheduleService->removeUserAssignments($delUserId);
            // Reassign orphaned blocks to eligible employees (no "Unbekannt" blocks).
            $scheduleService->sanitizeIneligibleAssignments();
            $tradeService->removeUserTrades($delUserId);
            $absenceService->removeForUser($delUserId);
            $auditService->log(AuditEntry::USER_DELETED, 'user', $delUserId);
            Flash::redirect($redirect, 'user_deleted');
        } else {
            Flash::redirect($redirect, null, 'user_delete_failed');
        }
    } elseif ($action === 'change_password') {
        $currentPw = Html::pRaw('current_password');
        $newPw = Html::pRaw('new_password');
        $confirmPw = Html::pRaw('confirm_password');
        $redirect = 'admin.php?tab=' . $tab;

        if ($newPw !== $confirmPw) {
            Flash::redirect($redirect, null, 'pw_mismatch');
        } elseif (AuthManager::validatePasswordStrength($newPw) !== null) {
            Flash::redirect($redirect, null, 'pw_weak');
        } elseif ($auth->changePassword($currentUser->getId(), $currentPw, $newPw)) {
            $auditService->log(AuditEntry::PASSWORD_CHANGED, 'user', $currentUser->getId());
            Flash::redirect($redirect, 'pw_changed');
        } else {
            Flash::redirect($redirect, null, 'pw_wrong');
        }
    } elseif ($action === 'absence_add') {
        $absUserId = Html::p('absence_user_id');
        $absStart = Html::p('absence_start');
        $absEnd = Html::p('absence_end');
        $absReason = Html::p('absence_reason');
        $redirect = 'admin.php?tab=' . $tab;
        $absErr = $absenceService->add($absUserId, $absStart, $absEnd, $absReason);
        if ($absErr === null) {
            $auditService->log(AuditEntry::ABSENCE_ADDED, 'absence', $absUserId, ['start' => $absStart, 'end' => $absEnd, 'reason' => $absReason]);
            Flash::redirect($redirect, 'absence_added');
        } else {
            Flash::redirect($redirect, null, 'absence_' . $absErr);
        }
    } elseif ($action === 'absence_delete') {
        $absId = Html::p('absence_id');
        $redirect = 'admin.php?tab=' . $tab;
        if ($absenceService->delete($absId)) {
            $auditService->log(AuditEntry::ABSENCE_DELETED, 'absence', $absId);
            Flash::redirect($redirect, 'absence_deleted');
        } else {
            Flash::redirect($redirect, null, 'absence_not_found');
        }
    } elseif ($action === 'snapshot_create') {
        $snapMonth = Html::p('snapshot_month');
        $redirect = 'admin.php?tab=' . $tab;
        $snapErr = $snapshotService->create($snapMonth, $currentUser->getId(), $currentUser->getName());
        if ($snapErr === null) {
            $auditService->log(AuditEntry::SNAPSHOT_CREATED, 'snapshot', $snapMonth);
            Flash::redirect($redirect, 'snapshot_created');
        } else {
            Flash::redirect($redirect, null, 'snapshot_' . $snapErr);
        }
    } elseif ($action === 'update_shift_settings') {
        // Einsatzzeiten / Bereitschaftswechsels aus dem Formular (shift[...]).
        $shiftInput = [];
        if (isset($_POST['shift']) && is_array($_POST['shift'])) {
            foreach ($_POST['shift'] as $k => $v) {
                if (is_scalar($v) || $v === null) {
                    $shiftInput[(string) $k] = $v;
                }
            }
        }
        $shiftResult = ShiftService::validateShiftSettings($shiftInput);
        if ($shiftResult['ok']) {
            $saved = ShiftService::saveShiftSettings($shiftResult['settings']);
            if ($saved) {
                Flash::redirect('admin.php?tab=settings', 'settings_saved');
            }
        }
        Flash::redirect('admin.php?tab=settings', null, 'settings_invalid');
    }
}

// Retrieve data
$now = time();
$allBlocks = $scheduleService->getAllBlocks();
$allUsers = $auth->getAllUsers();
$employees = $auth->getDutyEligibleUsers();

// Handle Audit CSV export
if ($tab === 'audit' && Html::q('export') === 'csv') {
    $auditEntries = $auditService->getRecent(500);
    $csv = $auditService->exportToCsv($auditEntries);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Audit_Protokoll_' . date('Ymd') . '.csv"');
    echo $csv;
    exit;
}

// Audit entries for Protokoll tab
$auditEntries = $auditService->getRecent(100);

// Map for names
$userMap = [];
foreach ($allUsers as $u) {
    $userMap[$u->getId()] = $u->getName();
}

// Check if we are editing a user (via GET parameter)
$editingUserId = Html::q('edit') ?: null;

// Pending Trades
$pendingTrades = [];
$allTrades = $tradeService->getAllTrades();
foreach ($allTrades as $trade) {
    if ($trade->isPendingApproval()) {
        $pendingTrades[] = $trade;
    }
}

// KPI stats
$employeeCount = count($employees);
$blockCount = count($allBlocks);
$openTradeCount = count(array_filter($allTrades, function($t) { return $t->isOpen(); }));
$pendingCount = count($pendingTrades);

// Fairness stats (year view)
$fairYear = Html::qi('fair_year', (int)date('Y'), 2000, 2100);
$fairStats = $billingService->generateYearStats($fairYear);

// Snapshots (Nachweise) + absences
$allSnapshots = $snapshotService->getAll();
$allAbsences = $absenceService->getAll();

// System health checks (read-only)
$healthChecks = [];
$dataDir = \Src\Core\Config::getDataDir();
$healthChecks[] = [
    'label' => 'Datenverzeichnis',
    'ok' => is_dir($dataDir) && is_writable($dataDir),
    'detail' => $dataDir . (str_starts_with($dataDir . DIRECTORY_SEPARATOR, \Src\Core\Config::getBaseDir() . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR) ? ' (IM DOCUMENT-ROOT!)' : ' (außerhalb Document-Root)'),
];
foreach (['users.json' => 'Benutzer', 'schedule.json' => 'Dienstplan', 'trades.json' => 'Tauschbörse', 'audit.json' => 'Protokoll'] as $file => $label) {
    $path = $dataDir . DIRECTORY_SEPARATOR . $file;
    $valid = false;
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $valid = $raw !== false && json_decode($raw, true) !== null;
    }
    $healthChecks[] = ['label' => $label . ' (' . $file . ')', 'ok' => $valid, 'detail' => $valid ? number_format((int)@filesize($path)) . ' Bytes' : 'fehlt oder ungültig'];
}
foreach (['absences.json' => 'Abwesenheiten', 'snapshots.json' => 'Versiegelungen'] as $file => $label) {
    $path = $dataDir . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        $healthChecks[] = ['label' => $label . ' (' . $file . ')', 'ok' => true, 'detail' => 'noch nicht angelegt (wird bei Bedarf erstellt)'];
        continue;
    }
    $raw = @file_get_contents($path);
    $valid = $raw !== false && json_decode($raw, true) !== null;
    $healthChecks[] = ['label' => $label . ' (' . $file . ')', 'ok' => $valid, 'detail' => $valid ? 'ok' : 'UNGÜLTIG – prüfen!'];
}
$healthChecks[] = [
    'label' => 'install.php',
    'ok' => !is_file(__DIR__ . '/install.php'),
    'detail' => is_file(__DIR__ . '/install.php') ? 'existiert noch – nach Installation löschen!' : 'entfernt',
];
$healthChecks[] = [
    'label' => 'Server-Verbindung (config.local.php)',
    'ok' => is_file(\Src\Core\Config::getBaseDir() . DIRECTORY_SEPARATOR . 'config.local.php'),
    'detail' => is_file(\Src\Core\Config::getBaseDir() . DIRECTORY_SEPARATOR . 'config.local.php') ? 'aktiv' : 'nicht vorhanden (Fallback: ./data)',
];
$backupDir = dirname($dataDir) . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'psychkg';
$backupFiles = is_dir($backupDir) ? glob($backupDir . DIRECTORY_SEPARATOR . 'psychkg-data-*.tar.gz') : [];
$healthChecks[] = [
    'label' => 'Datensicherung',
    'ok' => !empty($backupFiles),
    'detail' => !empty($backupFiles) ? count($backupFiles) . ' Snapshots, neuester: ' . basename(max($backupFiles)) : 'kein Backup gefunden!',
];
$healthChecks[] = [
    'label' => 'Session-Cookie',
    'ok' => (bool)ini_get('session.cookie_httponly'),
    'detail' => 'httponly=' . var_export((bool)ini_get('session.cookie_httponly'), true)
        . ' secure=' . var_export(\Src\Core\Config::isHttps(), true) . ' (HTTPS erkannt)'
        . ' samesite=' . (ini_get('session.cookie_samesite') ?: '–'),
];
$healthChecks[] = [
    'label' => 'PHP-Version',
    'ok' => version_compare(PHP_VERSION, '8.1', '>='),
    'detail' => PHP_VERSION,
];
if (!empty($allBlocks)) {
    $lastEnd = $allBlocks[count($allBlocks) - 1]->getEndTimestamp();
    $daysLeft = (int)ceil(($lastEnd - $now) / 86400);
    $healthChecks[] = [
        'label' => 'Planabdeckung',
        'ok' => $daysLeft > 14,
        'detail' => $daysLeft >= 0 ? "Plan reicht noch $daysLeft Tage" : 'Plan ist abgelaufen!',
    ];
}
// 24/7-Gewaehrleistung: Luecken zwischen Bloecken heissen, dass das System
// in diesem Zeitfenster "keinen Dienst" meldet. Das war jahrelang der Fall
// (Mi 08:00-16:00) und ist jetzt sichtbar pruefbar.
$coverageGaps = ScheduleService::findCoverageGaps();
$gapHours = 0.0;
foreach ($coverageGaps as $gap) {
    $gapHours += $gap['hours'];
}
$healthChecks[] = [
    'label' => 'Lückenlose 24/7-Abdeckung',
    'ok' => empty($coverageGaps),
    'detail' => empty($coverageGaps)
        ? count($allBlocks) . ' Blöcke, keine Lücke'
        : sprintf(
            '%d Lücke(n), %.0f h unbesetzt%s',
            count($coverageGaps),
            $gapHours,
            !empty($coverageGaps) ? ' (größte: ' . (new DateTimeImmutable('@' . $coverageGaps[0]['from']))
                ->setTimezone(new DateTimeZone(Config::getTimezone()))->format('d.m.Y H:i') . ')' : ''
        ),
];
// Jahreswechsel: der 31.12. und der Start des Folgejahres müssen besetzt sein.
$lastBlockEnd = !empty($allBlocks) ? $allBlocks[count($allBlocks) - 1]->getEndTimestamp() : 0;
// Jahr, in dem der Plan ENDET – so wird der Jahreswechsel geprueft, den der
// Plan wirklich abdecken muss (nicht zwingend das Jahr des letzten Starts).
$endYear = $lastBlockEnd > 0
    ? (int)(new DateTimeImmutable('@' . $lastBlockEnd))
        ->setTimezone(new DateTimeZone(Config::getTimezone()))->format('Y')
    : 0;
$dec31Covered = $lastBlockEnd > 0 && $endYear > 0
    && ScheduleService::isCovered((int) strtotime($endYear . '-12-31 12:00:00'));
if ($lastBlockEnd > 0) {
    $healthChecks[] = [
        'label' => 'Jahreswechsel besetzt',
        'ok' => $dec31Covered,
        'detail' => $dec31Covered
            ? '31.12. und Jahresanfang durchgehend besetzt'
            : '31.12. bzw. Jahresanfang NICHT besetzt – Dienstplan verlängern!',
    ];
}
// Audit fill level: oldest entries are trimmed beyond the limit – warn in time.
$auditCount = count($auditService->getRecent(\Src\Service\AuditService::LIMIT));
$healthChecks[] = [
    'label' => 'Protokoll-Füllstand',
    'ok' => $auditCount < 900,
    'detail' => $auditCount . ' / ' . \Src\Service\AuditService::LIMIT . ' Einträge'
        . ($auditCount >= 900 ? ' – bitte CSV-Export sichern, älteste Einträge werden gekürzt!' : ''),
];

// Setup billing dates (validated; tampered values snap back to the defaults)
[$billingStartTs, $billingEndTs] = BillingService::parseBillingRange(
    Html::qDate('start') ?: null,
    Html::qDate('end') ?: null
);
$billingStartInput = date('Y-m-d', $billingStartTs);
$billingEndInput = date('Y-m-d', $billingEndTs);

$billingSummary = $billingService->generateSummary($billingStartTs, $billingEndTs);
$billingHasActivity = BillingService::summaryHasActivity($billingSummary);


// Pending trade count for badge on tab ($tab is defined at the top for PRG redirects)

// Calendar widget data
$calMonth = Html::qi('cal_month', (int)date('n'), 1, 12);
$calYear  = Html::qi('cal_year', (int)date('Y'), 2000, 2100);
$prevMonth = $calMonth - 1; $prevYear = $calYear;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $calMonth + 1; $nextYear = $calYear;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }
$calFirstDayTs = mktime(0, 0, 0, $calMonth, 1, $calYear);
$calDaysInMonth = (int)date('j', mktime(0, 0, 0, $calMonth + 1, 0, $calYear));
$calStartDow = (int)date('N', $calFirstDayTs);
$calDayKeys = [];
for ($d = 1; $d <= $calDaysInMonth; $d++) {
    $calDayKeys[] = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $d);
}
$allDutyDays = [];
$dayBlocksMap = [];
foreach ($allBlocks as $block) {
    foreach ($calDayKeys as $dk) {
        if (ScheduleService::blockOverlapsDate($block->getStartTimestamp(), $block->getEndTimestamp(), $dk)) {
            $allDutyDays[$dk] = true;
            if (!isset($dayBlocksMap[$dk])) {
                $dayBlocksMap[$dk] = [];
            }
            $dayBlocksMap[$dk][] = [
                'id' => $block->getId(),
                'start' => date('d.m.Y H:i', $block->getStartTimestamp()),
                'end' => date('d.m.Y H:i', $block->getEndTimestamp()),
                'start_ts' => $block->getStartTimestamp(),
                'end_ts' => $block->getEndTimestamp(),
                'user' => $userMap[$block->getAssignedUserId()] ?? 'Unbekannt',
                'status' => $block->getStatus(),
                'hours' => number_format(ScheduleService::calculateActiveHours($block->getStartTimestamp(), $block->getEndTimestamp()), 1, ',', ''),
            ];
        }
    }
}
$calTodayYmd = date('Y-m-d');
$germanMonths = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
$calTabParam = 'plan'; // always plan tab for the calendar
?>
<!DOCTYPE html>
<html lang="de" class="h-full bg-slate-50 text-slate-800">
<?php $pageTitle = 'PsychKG Bereitschaftsplaner - Admin Console'; require __DIR__ . '/../templates/head.php'; ?>
<body class="min-h-full flex flex-col bg-slate-50 text-slate-800">


    <?php $activePage = 'admin'; require __DIR__ . '/../templates/nav.php'; ?>

    <!-- Main Content Area -->
    <main id="main-content" class="flex-grow max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
        
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-4">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-slate-900">Admin Konsole</h1>
                <p class="text-slate-600 mt-1 text-sm">Dienstplanerstellung, Tauschfreigabe und Abrechnung</p>
            </div>
            <div class="flex items-center gap-2">
                <?php
                // Jahr für PDF-Export: gewählte Planung (falls gesetzt), sonst aktuelles Jahr
                $pdfYear = $planYearSelected;
                // Halbjahr stammt aus der tatsächlich gewählten Planungsperiode.
                // Vorher wurde es aus dem Datum "01.01.<Jahr>" abgeleitet und war
                // damit immer "1. HJ".
                $pdfIsH1 = $planPeriodSelected === 'h1';
                ?>
                <a href="export_schedule_pdf.php?start=<?= $pdfYear ?>-01-01&end=<?= $pdfYear ?>-06-30"
                   target="_blank"
                   class="<?= $pdfIsH1 ? 'bg-indigo-600 hover:bg-indigo-700 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?> inline-flex items-center py-2.5 px-4 rounded-xl text-xs font-bold uppercase transition-all shadow-sm">
                    1. HJ
                </a>
                <a href="export_schedule_pdf.php?start=<?= $pdfYear ?>-07-01&end=<?= $pdfYear ?>-12-31"
                   target="_blank"
                   class="<?= !$pdfIsH1 ? 'bg-indigo-600 hover:bg-indigo-700 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?> inline-flex items-center py-2.5 px-4 rounded-xl text-xs font-bold uppercase transition-all shadow-sm">
                    2. HJ
                </a>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
            <div role="alert" aria-live="polite" class="php-alert php-alert-success p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-start space-x-2">
                <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div role="alert" aria-live="polite" class="php-alert php-alert-error p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm flex items-start space-x-2">
                <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- KPI Dashboard -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 anim-stagger">
            <div class="kpi-card">
                <div class="text-2xl font-bold text-slate-900" data-countup="<?= $employeeCount ?>" data-decimals="0"><?= $employeeCount ?></div>
                <div class="text-[10px] text-slate-500 uppercase tracking-wider font-semibold mt-0.5">Mitarbeiter</div>
            </div>
            <div class="kpi-card">
                <div class="text-2xl font-bold text-indigo-600" data-countup="<?= $blockCount ?>" data-decimals="0"><?= $blockCount ?></div>
                <div class="text-[10px] text-slate-500 uppercase tracking-wider font-semibold mt-0.5">Dienstblöcke</div>
            </div>
            <div class="kpi-card">
                <div class="text-2xl font-bold text-amber-600" data-countup="<?= $openTradeCount ?>" data-decimals="0"><?= $openTradeCount ?></div>
                <div class="text-[10px] text-slate-500 uppercase tracking-wider font-semibold mt-0.5">Offene Tauschangebote</div>
            </div>
            <div class="kpi-card">
                <div class="text-2xl font-bold text-violet-600" data-countup="<?= $pendingCount ?>" data-decimals="0"><?= $pendingCount ?></div>
                <div class="text-[10px] text-slate-500 uppercase tracking-wider font-semibold mt-0.5">Ausstehende Freigaben</div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="border-b border-slate-200">
            <div class="flex gap-1 -mb-px overflow-x-auto" role="tablist" aria-label="Admin-Bereiche">
                <button id="tabbtn-plan" data-tab="tab-plan" role="tab" aria-controls="tab-plan" tabindex="<?= $tab === 'plan' ? '0' : '-1' ?>" aria-selected="<?= $tab === 'plan' ? 'true' : 'false' ?>" class="tab-btn px-3 sm:px-4 py-2.5 sm:py-3 text-xs sm:text-sm font-semibold border-b-2 whitespace-nowrap transition-all <?= $tab === 'plan' ? 'text-indigo-700 border-indigo-600 bg-indigo-50/70' : 'text-slate-500 border-transparent hover:text-slate-700' ?>">
                    <svg class="w-4 h-4 inline mr-1.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    Dienstplan
                </button>
                <button id="tabbtn-trades" data-tab="tab-trades" role="tab" aria-controls="tab-trades" tabindex="<?= $tab === 'trades' ? '0' : '-1' ?>" aria-selected="<?= $tab === 'trades' ? 'true' : 'false' ?>" class="tab-btn px-3 sm:px-4 py-2.5 sm:py-3 text-xs sm:text-sm font-semibold border-b-2 whitespace-nowrap transition-all <?= $tab === 'trades' ? 'text-indigo-700 border-indigo-600 bg-indigo-50/70' : 'text-slate-500 border-transparent hover:text-slate-700' ?>">
                    <svg class="w-4 h-4 inline mr-1.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                    Tauschanträge
                    <?php if ($pendingCount > 0): ?>
                        <span class="ml-1.5 px-1.5 py-0.5 rounded-full text-[10px] bg-violet-100 text-violet-700 font-bold"><?= $pendingCount ?></span>
                    <?php endif; ?>
                </button>
                <button id="tabbtn-users" data-tab="tab-users" role="tab" aria-controls="tab-users" tabindex="<?= $tab === 'users' ? '0' : '-1' ?>" aria-selected="<?= $tab === 'users' ? 'true' : 'false' ?>" class="tab-btn px-3 sm:px-4 py-2.5 sm:py-3 text-xs sm:text-sm font-semibold border-b-2 whitespace-nowrap transition-all <?= $tab === 'users' ? 'text-indigo-700 border-indigo-600 bg-indigo-50/70' : 'text-slate-500 border-transparent hover:text-slate-700' ?>">
                    <svg class="w-4 h-4 inline mr-1.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    Mitarbeiter
                </button>
                <button id="tabbtn-billing" data-tab="tab-billing" role="tab" aria-controls="tab-billing" tabindex="<?= $tab === 'billing' ? '0' : '-1' ?>" aria-selected="<?= $tab === 'billing' ? 'true' : 'false' ?>" class="tab-btn px-3 sm:px-4 py-2.5 sm:py-3 text-xs sm:text-sm font-semibold border-b-2 whitespace-nowrap transition-all <?= $tab === 'billing' ? 'text-indigo-700 border-indigo-600 bg-indigo-50/70' : 'text-slate-500 border-transparent hover:text-slate-700' ?>">
                    <svg class="w-4 h-4 inline mr-1.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                    Abrechnung
                </button>
                <button id="tabbtn-profile" data-tab="tab-profile" role="tab" aria-controls="tab-profile" tabindex="<?= $tab === 'profile' ? '0' : '-1' ?>" aria-selected="<?= $tab === 'profile' ? 'true' : 'false' ?>" class="tab-btn px-3 sm:px-4 py-2.5 sm:py-3 text-xs sm:text-sm font-semibold border-b-2 whitespace-nowrap transition-all <?= $tab === 'profile' ? 'text-indigo-700 border-indigo-600 bg-indigo-50/70' : 'text-slate-500 border-transparent hover:text-slate-700' ?>">
                    <svg class="w-4 h-4 inline mr-1.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    Mein Konto
                </button>
                <button id="tabbtn-audit" data-tab="tab-audit" role="tab" aria-controls="tab-audit" tabindex="<?= $tab === 'audit' ? '0' : '-1' ?>" aria-selected="<?= $tab === 'audit' ? 'true' : 'false' ?>" class="tab-btn px-3 sm:px-4 py-2.5 sm:py-3 text-xs sm:text-sm font-semibold border-b-2 whitespace-nowrap transition-all <?= $tab === 'audit' ? 'text-indigo-700 border-indigo-600 bg-indigo-50/70' : 'text-slate-500 border-transparent hover:text-slate-700' ?>">
                    <svg class="w-4 h-4 inline mr-1.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
                    Protokoll
                </button>
                <button id="tabbtn-nachweise" data-tab="tab-nachweise" role="tab" aria-controls="tab-nachweise" tabindex="<?= $tab === 'nachweise' ? '0' : '-1' ?>" aria-selected="<?= $tab === 'nachweise' ? 'true' : 'false' ?>" class="tab-btn px-3 sm:px-4 py-2.5 sm:py-3 text-xs sm:text-sm font-semibold border-b-2 whitespace-nowrap transition-all <?= $tab === 'nachweise' ? 'text-indigo-700 border-indigo-600 bg-indigo-50/70' : 'text-slate-500 border-transparent hover:text-slate-700' ?>">
                    <svg class="w-4 h-4 inline mr-1.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                    Nachweise
                </button>
                <button id="tabbtn-settings" data-tab="tab-settings" role="tab" aria-controls="tab-settings" tabindex="<?= $tab === 'settings' ? '0' : '-1' ?>" aria-selected="<?= $tab === 'settings' ? 'true' : 'false' ?>" class="tab-btn px-3 sm:px-4 py-2.5 sm:py-3 text-xs sm:text-sm font-semibold border-b-2 whitespace-nowrap transition-all <?= $tab === 'settings' ? 'text-indigo-700 border-indigo-600 bg-indigo-50/70' : 'text-slate-500 border-transparent hover:text-slate-700' ?>">
                    <svg class="w-4 h-4 inline mr-1.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.166 1.42-2.106 2.523-2.507l2.16-1.58a1.5 1.5 0 011.995.576c.11.562.33 1.112.576 1.495l1.06 2.092a1.5 1.5 0 01-.54 1.863l-2.16 1.58c-.963.735-1.957 1.475-2.523 2.507l-2.16 1.58a1.5 1.5 0 01-1.995.576 1.5 1.5 0 01-.576 1.495l-1.06 2.092a1.5 1.5 0 01-.54.54l-2.16-1.58c-1.103-.772-2.097-1.512-2.523-2.507l-2.16-1.58a1.5 1.5 0 01-.54-1.863l1.06-2.092a1.5 1.5 0 01.576-1.495l2.16-1.58z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    Einstellungen
                </button>
                <button id="tabbtn-system" data-tab="tab-system" role="tab" aria-controls="tab-system" tabindex="<?= $tab === 'system' ? '0' : '-1' ?>" aria-selected="<?= $tab === 'system' ? 'true' : 'false' ?>" class="tab-btn px-3 sm:px-4 py-2.5 sm:py-3 text-xs sm:text-sm font-semibold border-b-2 whitespace-nowrap transition-all <?= $tab === 'system' ? 'text-indigo-700 border-indigo-600 bg-indigo-50/70' : 'text-slate-500 border-transparent hover:text-slate-700' ?>">
                    <svg class="w-4 h-4 inline mr-1.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"></path></svg>
                    System
                </button>
            </div>
        </div>

<?php
// Marks this request as an admin render. The tab partials check this constant
// and return 404 when they are requested directly over HTTP.
define('PSYCHKG_ADMIN', true);
?>
<?php include __DIR__ . '/partials/tab-plan.php'; ?>
<?php include __DIR__ . '/partials/tab-trades.php'; ?>
<?php include __DIR__ . '/partials/tab-users.php'; ?>
<?php include __DIR__ . '/partials/tab-billing.php'; ?>
<?php include __DIR__ . '/partials/tab-profile.php'; ?>
<?php include __DIR__ . '/partials/tab-audit.php'; ?>
<?php include __DIR__ . '/partials/tab-nachweise.php'; ?>
<?php include __DIR__ . '/partials/tab-system.php'; ?>
<?php include __DIR__ . '/partials/tab-settings.php'; ?>

    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>


    <!-- Day Detail Modal -->
    <div id="day-modal" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="day-modal-title">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" data-day-modal-close="true"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-md bg-white rounded-2xl shadow-2xl border border-slate-200 p-6 max-h-[80vh] overflow-y-auto" id="day-modal-panel">
            <div class="flex items-center justify-between mb-4">
                <h3 id="day-modal-title" class="text-lg font-bold text-slate-900"></h3>
                <button data-day-modal-close="true" class="p-1.5 rounded-lg hover:bg-slate-100 text-slate-400 hover:text-slate-600 transition-all" aria-label="Schliessen">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div id="day-modal-content" class="space-y-3"></div>
        </div>
    </div>

<script src="assets/js/admin.js" nonce="<?= CSP_NONCE ?>"></script>

<div id="day-modal-data" hidden><?= json_encode($dayBlocksMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?></div>
<script src="assets/js/admin-daymodal.js" nonce="<?= CSP_NONCE ?>"></script>
</body>
</html>
