<?php
/**
 * ICS Calendar Export - generates .ics file for Google Calendar / Outlook.
 * Times are emitted in UTC (Z suffix) so importing calendars interpret
 * them unambiguously; lines are folded per RFC 5545.
 */
require_once __DIR__ . '/../src/bootstrap.php';

use Src\Auth\AuthManager;
use Src\Service\ScheduleService;

/**
 * RFC 5545 line folding (75 octets, multibyte-safe when mbstring is present).
 */
function icsFoldLine(string $line): string
{
    $max = 75;
    if (strlen($line) <= $max) {
        return $line;
    }
    $useMb = function_exists('mb_strlen') && function_exists('mb_substr');
    $chars = $useMb ? mb_strlen($line, 'UTF-8') : strlen($line);
    $out = '';
    $chunk = '';
    $bytes = 0;
    for ($i = 0; $i < $chars; $i++) {
        $ch = $useMb ? mb_substr($line, $i, 1, 'UTF-8') : $line[$i];
        $blen = strlen($ch);
        if ($bytes + $blen > $max) {
            $out .= $chunk . "\r\n ";
            $chunk = '';
            $bytes = 1; // leading space of the continuation line
        }
        $chunk .= $ch;
        $bytes += $blen;
    }
    return $out . $chunk;
}

$auth = new AuthManager();
$auth->requireLogin();

$currentUser = $auth->getRequiredUser();
$scheduleService = new ScheduleService();

$allBlocks = $scheduleService->getAllBlocks();

// Admin kann optional das gesamte Team exportieren (Team-Agenda).
$exportScope = 'me';
if ($auth->isAdmin() && (string)($_GET['scope'] ?? '') === 'team') {
    $exportScope = 'team';
}

$userBlocks = [];
foreach ($allBlocks as $block) {
    if ($exportScope === 'team') {
        $userBlocks[] = $block;
    } elseif ($block->getAssignedUserId() === $currentUser->getId()) {
        $userBlocks[] = $block;
    }
}

// If no user blocks, show empty calendar (no fallback to all blocks)
$exportBlocks = $userBlocks;

// Generate ICS content
$lines = [];
$lines[] = 'BEGIN:VCALENDAR';
$lines[] = 'VERSION:2.0';
$lines[] = 'PRODID:-//PsychKG NRW//Bereitschaftsdienstplaner//DE';
$lines[] = 'CALSCALE:GREGORIAN';
$lines[] = 'METHOD:PUBLISH';
$calName = $exportScope === 'team' ? 'PsychKG Team Bereitschaft' : 'PsychKG Bereitschaftsdienst';
$lines[] = 'X-WR-CALNAME:' . $calName;
$lines[] = 'X-WR-TIMEZONE:Europe/Berlin';

foreach ($exportBlocks as $block) {
    $start = new \DateTimeImmutable('@' . $block->getStartTimestamp());
    // DTEND = reale Bereitschaftsende (letztes Intervall), nicht Container-Ende
    // (Mi 16:00) – bei Feiertag auf dem Schluss-Montag kann die Bereitschaft bis 16:00
    // reichen. Der Netto-Bereitschafts-Ende entspricht dem letzten Intervall.
    $intervals = ScheduleService::calculateIntervals($block->getStartTimestamp(), $block->getEndTimestamp());
    $netEnd = count($intervals) > 0 ? $intervals[count($intervals) - 1]['end'] : $block->getEndTimestamp();
    $end = new \DateTimeImmutable('@' . $netEnd);
    $utc = new \DateTimeZone('UTC');
    
    $netHours = ScheduleService::calculateActiveHours($block->getStartTimestamp(), $block->getEndTimestamp());
    // Im Team-Export wird der zuständige Mitarbeiter im Event-Titel angezeigt.
    if ($exportScope === 'team' && $block->getAssignedUserId() !== null) {
        $blockUserName = null;
        foreach ($auth->getAllUsers() as $u) {
            if ($u->getId() === $block->getAssignedUserId()) { $blockUserName = $u->getName(); break; }
        }
        $userName = $blockUserName ?? $currentUser->getName();
    } else {
        $userName = $currentUser->getName();
    }

    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z');
    $lines[] = 'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z');
    $lines[] = 'SUMMARY:Bereitschaftsdienst (' . number_format($netHours, 1, ',', '') . 'h)';
    $lines[] = 'DESCRIPTION:Bereitschaftsdienst für PsychKG NRW\nNetto: ' . number_format($netHours, 1, ',', '') . ' Stunden\nMitarbeiter: ' . $userName;
    $lines[] = 'CATEGORIES:Bereitschaftsdienst';
    $lines[] = 'UID:' . $block->getId() . '@psychkg-planer';
    $lines[] = 'STATUS:CONFIRMED';
    $lines[] = 'TRANSP:OPAQUE';
    $lines[] = 'END:VEVENT';
}

$lines[] = 'END:VCALENDAR';

// Output as download (folded per RFC 5545)
$userNameForFile = $exportScope === 'team' ? 'team' : preg_replace('/[^a-zA-Z0-9_-]/', '_', $currentUser->getUsername());
$filename = 'PsychKG_Bereitschaft_' . $userNameForFile . '_' . date('Ymd') . '.ics';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
echo implode("\r\n", array_map('icsFoldLine', $lines));
exit;
