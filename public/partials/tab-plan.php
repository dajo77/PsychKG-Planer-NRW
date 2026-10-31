<?php
// Include-only: dieses Partial wird von admin.php gerendert. Ein direkter
// Web-Aufruf hat weder Bootstrap noch $auth/$tab und muss deshalb 404 liefern,
// statt einen Fatal Error oder halbe Ausgabe zu produzieren.
if (!defined('PSYCHKG_ADMIN')) {
    http_response_code(404);
    exit;
}
?>
<?php
// Partials werden per include() eingebunden: use-Importe aus admin.php gelten
// hier NICHT. Ohne diese Zeilen stirbt das Rendering mit
// "Class Csrf not found" und alle folgenden Tab-Panels fehlen im DOM.
use Src\Core\Csrf;
use Src\Service\ScheduleService;
?>
        <!-- Tab: Dienstplan -->
        <div id="tab-plan" role="tabpanel" aria-labelledby="tabbtn-plan" tabindex="0" class="tab-content focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 <?= $tab !== 'plan' ? 'hidden' : '' ?> space-y-8">

            <!-- Generator + Schnellübersicht -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <!-- Automated Scheduler (2/3) -->
                <div class="lg:col-span-2 glass-card rounded-3xl p-6 space-y-6">
                    <h2 class="text-lg font-bold text-slate-900">Dienstplan-Generierung</h2>
                    
                    <form action="admin.php" method="POST" class="space-y-4" data-confirm="Bestehende Blöcke in diesem Zeitraum werden überschrieben. Fortfahren?">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="generate_schedule">

                        <div>
                            <label for="plan_year" class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Planungsjahr</label>
<select name="year" id="plan_year"
        class="w-full sm:w-56 px-3 py-2.5 bg-white border border-slate-200 rounded-xl text-sm text-slate-900 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-600 shadow-sm"
        >
                                <?php foreach (planYearOptions($planYearMin, $planYearMax) as $optYear): ?>
                                    <option value="<?= $optYear ?>" <?= $optYear === $planYearSelected ? 'selected' : '' ?>>
                                        <?= $optYear ?><?= $optYear === $planYearMin ? ' (aktuelles Jahr)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-[11px] text-slate-500 mt-2">
                                Wählen Sie das Jahr, für das der Dienstplan erstellt werden soll.
                                Bereits bestehende Blöcke <strong>nur im gewählten Zeitraum</strong> werden überschrieben –
                                andere Jahre bleiben unberührt.
                            </p>
                        </div>

                        <div>
                            <span class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Halbjahr</span>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="flex items-center p-3 bg-white border-2 border-slate-200 rounded-xl cursor-pointer hover:border-indigo-300 transition-all has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50">
                                    <input type="radio" name="period" value="h1" class="sr-only" <?= $planPeriodSelected === 'h1' ? 'checked' : '' ?>>
                                    <div class="text-center w-full">
                                        <div class="text-sm font-bold text-slate-800">1. Halbjahr</div>
                                        <div class="text-[10px] text-slate-500 mt-0.5">01.01. – 30.06. <span data-plan-year-label><?= $planYearSelected ?></span></div>
                                        <div class="text-[10px] text-slate-400 mt-0.5">26 Wochen</div>
                                    </div>
                                </label>
                                <label class="flex items-center p-3 bg-white border-2 border-slate-200 rounded-xl cursor-pointer hover:border-indigo-300 transition-all has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50">
                                    <input type="radio" name="period" value="h2" class="sr-only" <?= $planPeriodSelected === 'h2' ? 'checked' : '' ?>>
                                    <div class="text-center w-full">
                                        <div class="text-sm font-bold text-slate-800">2. Halbjahr</div>
                                        <div class="text-[10px] text-slate-500 mt-0.5">01.07. – 31.12. <span data-plan-year-label><?= $planYearSelected ?></span></div>
                                        <div class="text-[10px] text-slate-400 mt-0.5">26 Wochen</div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Mitarbeiter-Pool (Rotations-Verteilung)</label>
                            <div class="space-y-2 p-3 bg-white border border-slate-200 rounded-xl max-h-36 overflow-y-auto shadow-sm">
                                <?php foreach ($employees as $employee): ?>
                                    <label class="flex items-center space-x-3 text-sm text-slate-700 cursor-pointer hover:text-slate-900">
                                        <input type="checkbox" name="employees[]" value="<?= $employee->getId() ?>" checked
                                               class="rounded border-slate-300 bg-white text-indigo-600 focus:ring-indigo-500 focus:ring-offset-0" />
                                        <span><?= htmlspecialchars($employee->getName()) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if (!empty($coverageGaps)): ?>
                        <!-- Reparatur-Reparaturs: nur sichtbar, wenn wirklich Luecken da sind. -->
                        <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200">
                            <div class="text-xs font-bold text-amber-800 uppercase tracking-wider mb-1">Abdeckungslücke erkannt</div>
                            <p class="text-[11px] text-amber-900 mb-3">
                                <?= count($coverageGaps) ?> Lücke(n) zwischen Dienstblöcken — zusammen
                                <?= number_format($gapHours, 0, ',', '.') ?> Stunden, in denen das System
                                „keinen aktiven Dienst" meldet. Typischerweise der Mittwoch 08:00–16:00,
                                der bei älteren Plänen zwischen zwei Blöcken offen blieb.
                            </p>
                            <form action="admin.php?tab=plan" method="POST" data-confirm="Blockgrenzen angleichen? Zuständigkeiten der Mitarbeiter bleiben unverändert.">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="repair_coverage">
                                <button type="submit"
                                        class="py-2 px-4 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold uppercase rounded-lg transition-all shadow-sm">
                                    Abdeckung reparieren
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>

                        <div class="flex justify-between items-center pt-2">
                            <button type="button" data-confirm-reset="true"
                                    class="py-2 px-3 border border-rose-200 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-bold uppercase rounded-lg transition-all shadow-sm">
                                Dienstplan leeren
                            </button>
                            <button type="submit"
                                    class="py-3 px-6 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold uppercase rounded-xl transition-all shadow-md shadow-indigo-600/10">
                                Automatisch Zuweisen
                            </button>
                        </div>
                    </form>

                    <form id="reset-form" action="admin.php" method="POST" class="hidden">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="reset_schedule">
                    </form>
                </div>

                <!-- Schnellübersicht (1/3) -->
                <div class="space-y-4">
                    <div class="glass-card rounded-3xl p-5">
                        <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-3 font-display">Nächste Blöcke</h3>
                        <?php
                        $upcomingForPreview = array_values(array_filter($allBlocks, fn($b) => $b->getStartTimestamp() >= $now));
                        $upcomingForPreview = array_slice($upcomingForPreview, 0, 5);
                        ?>
                        <?php if (empty($upcomingForPreview)): ?>
                            <p class="text-xs text-slate-400 text-center py-4">Keine Blöcke geplant</p>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach ($upcomingForPreview as $b):
                                    $bHours = ScheduleService::calculateActiveHours($b->getStartTimestamp(), $b->getEndTimestamp());
                                    $bName = $userMap[$b->getAssignedUserId()] ?? '?';
                                    $daysUntil = (int)ceil(($b->getStartTimestamp() - $now) / 86400);
                                    $dotColor = match($b->getStatus()) {
                                        'trade_open' => 'bg-amber-400',
                                        'trade_pending' => 'bg-violet-400',
                                        default => 'bg-emerald-400',
                                    };
                                ?>
                                    <div class="flex items-center gap-2.5 p-2 rounded-xl bg-white border border-slate-200 shadow-sm">
                                        <span class="h-2 w-2 rounded-full <?= $dotColor ?> flex-shrink-0"></span>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-[11px] font-bold text-slate-900 truncate"><?= htmlspecialchars($bName) ?></div>
                                            <div class="text-[10px] text-slate-500">
                                                <?= date('d.m.', $b->getStartTimestamp()) ?> – <?= date('d.m.', $b->getEndTimestamp()) ?>
                                                · in <?= $daysUntil ?> <?= $daysUntil === 1 ? 'Tag' : 'Tagen' ?>
                                            </div>
                                        </div>
                                        <span class="text-[10px] font-bold text-slate-500 flex-shrink-0"><?= number_format($bHours, 1, ',', '') ?>h</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="glass-card rounded-3xl p-5">
                        <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-3 font-display">Statistik</h3>
                        <div class="space-y-2.5">
                            <div class="flex justify-between items-center">
                                <span class="text-xs text-slate-500">Mitarbeiter im Pool</span>
                                <span class="text-xs font-bold text-indigo-600"><?= $employeeCount ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-xs text-slate-500">Offene Tausche</span>
                                <span class="text-xs font-bold <?= $openTradeCount > 0 ? 'text-amber-600' : 'text-slate-400' ?>"><?= $openTradeCount ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-xs text-slate-500">Ausstehend</span>
                                <span class="text-xs font-bold <?= $pendingCount > 0 ? 'text-violet-600' : 'text-slate-400' ?>"><?= $pendingCount ?></span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        <!-- Manueller Editor (full width, compact cards) -->
        <div class="glass-card rounded-3xl p-6">
            <h2 class="text-lg font-bold text-slate-900 mb-4">Manueller Editor</h2>

            <?php if (empty($allBlocks)): ?>
                <div class="text-center py-12">
                    <svg class="w-16 h-16 mx-auto text-slate-200 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <p class="text-sm text-slate-500 font-medium">Keine Dienstplanblöcke vorhanden.</p>
                    <p class="text-xs text-slate-400 mt-1">Nutzen Sie den Generator oben, um den ersten Dienstplan zu erstellen.</p>
                </div>
            <?php else: ?>
                <?php
                $upcomingBlocks = array_filter($allBlocks, fn($b) => $b->getEndTimestamp() >= $now);
                $pastBlocks = array_filter($allBlocks, fn($b) => $b->getEndTimestamp() < $now);
                ?>
                <?php if (!empty($upcomingBlocks)): ?>
                    <div class="mb-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">Kommende Blöcke</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                            <?php foreach ($upcomingBlocks as $block):
                                $netHours = ScheduleService::calculateActiveHours($block->getStartTimestamp(), $block->getEndTimestamp());
                                $isOwn = $block->getAssignedUserId() === $currentUser->getId();
                                $borderClass = match(true) {
                                    $block->getStatus() === 'trade_open' => 'border-amber-300 bg-amber-50/30',
                                    $block->getStatus() === 'trade_pending' => 'border-violet-300 bg-violet-50/30',
                                    $isOwn => 'border-emerald-300 bg-emerald-50/30',
                                    default => 'border-slate-200 bg-white',
                                };
                            ?>
                                <div class="rounded-xl border-2 <?= $borderClass ?> p-3 transition-all hover:shadow-sm">
                                    <div class="flex items-start justify-between gap-2 mb-2">
                                        <div class="text-xs font-bold text-slate-900 leading-tight">
                                            <?= $block->getFormattedStart() ?>
                                            <span class="text-slate-400 font-normal">–</span><br>
                                            <?= $block->getFormattedEnd() ?>
                                        </div>
                                        <span class="px-1.5 py-0.5 rounded text-[8px] font-bold uppercase flex-shrink-0
                                            <?php
                                            $hoursBadgeClass = match($block->getStatus()) {
                                                'trade_open' => 'bg-amber-100 text-amber-700',
                                                'trade_pending' => 'bg-violet-100 text-violet-700',
                                                default => 'bg-slate-100 text-slate-600',
                                            };
                                            echo $hoursBadgeClass;
                                            ?>"><?= number_format($netHours, 1, ',', '') ?>h</span>
                                    </div>
                                    <form action="admin.php?tab=plan" method="POST" class="flex items-center gap-1.5" data-confirm="Zuweisung ändern?">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="manual_assign">
                                        <input type="hidden" name="block_id" value="<?= htmlspecialchars($block->getId()) ?>">
                                        <select name="user_id"
                                                class="flex-1 min-w-0 py-1 px-2 bg-white border border-slate-200 rounded-lg text-slate-800 text-[11px] focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm">
                                            <?php foreach ($employees as $emp): ?>
                                                <option value="<?= $emp->getId() ?>" <?= $block->getAssignedUserId() === $emp->getId() ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($emp->getName()) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="px-2.5 py-1 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-[10px] font-bold uppercase transition-all shadow-sm flex-shrink-0">OK</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($pastBlocks)): ?>
                    <details class="group">
                        <summary class="text-xs font-semibold uppercase tracking-wider text-slate-400 cursor-pointer hover:text-slate-600 transition-all select-none mb-3">
                            Vergangene Blöcke (<?= count($pastBlocks) ?>)
                        </summary>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2">
                            <?php foreach ($pastBlocks as $block):
                                $netHours = ScheduleService::calculateActiveHours($block->getStartTimestamp(), $block->getEndTimestamp());
                            ?>
                                <div class="rounded-lg border border-slate-100 bg-slate-50/50 p-2.5 opacity-60">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-[10px] text-slate-500 font-semibold">
                                            <?= date('d.m.', $block->getStartTimestamp()) ?> – <?= date('d.m.', $block->getEndTimestamp()) ?>
                                            · <?= htmlspecialchars($userMap[$block->getAssignedUserId()] ?? '?') ?>
                                        </span>
                                        <span class="text-[10px] text-slate-400"><?= number_format($netHours, 1, ',', '') ?>h</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Month Calendar Widget -->
        <div class="glass-card rounded-3xl p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-xs font-bold text-slate-800 font-display"><?= $germanMonths[$calMonth] ?> <?= $calYear ?></h2>
                <div class="flex items-center gap-1">
                    <a href="?tab=plan&cal_month=<?= $prevMonth ?>&cal_year=<?= $prevYear ?>" class="p-1.5 rounded-lg hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    </a>
                    <a href="?tab=plan&cal_month=<?= $nextMonth ?>&cal_year=<?= $nextYear ?>" class="p-1.5 rounded-lg hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                    </a>
                </div>
            </div>
            <div class="hidden sm:grid grid-cols-7 gap-0 text-center">
                <?php $wdays = ['Mo','Di','Mi','Do','Fr','Sa','So']; ?>
                <?php foreach ($wdays as $wd): ?>
                    <div class="text-[10px] text-slate-400 font-semibold py-1"><?= $wd ?></div>
                <?php endforeach; ?>
                <?php for ($i = 1; $i < $calStartDow; $i++): ?>
                    <div></div>
                <?php endfor; ?>
                <?php for ($d = 1; $d <= $calDaysInMonth; $d++):
                    $dk = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $d);
                    $hasDuty = isset($allDutyDays[$dk]);
                    $isToday = ($dk === $calTodayYmd);
                    if ($isToday && $hasDuty):
                        $cellClass = 'rounded-full w-7 h-7 flex items-center justify-center mx-auto text-xs bg-indigo-600 text-white font-bold';
                    elseif ($isToday):
                        $cellClass = 'rounded-full w-7 h-7 flex items-center justify-center mx-auto text-xs bg-indigo-100 text-indigo-700 font-bold';
                    elseif ($hasDuty):
                        $cellClass = 'rounded-full w-7 h-7 flex items-center justify-center mx-auto text-xs bg-emerald-100 text-emerald-800 font-semibold';
                    else:
                        $cellClass = 'rounded-full w-7 h-7 flex items-center justify-center mx-auto text-xs text-slate-600 hover:bg-slate-100 transition-all';
                    endif;
                ?>
                    <div class="py-0.5"><span class="<?= $cellClass ?> <?= $hasDuty ? 'cursor-pointer hover:ring-2 hover:ring-indigo-300' : '' ?>" data-day-detail="<?= $dk ?>"><?= $d ?></span></div>
                <?php endfor; ?>
            </div>
            <div class="sm:hidden">
                <?php
                $dutyDaysInMonth = [];
                foreach ($calDayKeys as $dk) {
                    if (isset($allDutyDays[$dk])) {
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
                            $isTodayMobile = ($dk === $calTodayYmd);
                        ?>
                            <div class="flex items-center gap-3 p-2 rounded-lg <?= $isTodayMobile ? 'bg-indigo-50 border border-indigo-200' : 'bg-emerald-50 border border-emerald-200' ?>">
                                <span class="text-xs font-bold <?= $isTodayMobile ? 'text-indigo-700' : 'text-emerald-700' ?> w-20 flex-shrink-0">
                                    <?= $dDate->format('d.m.') ?>
                                    <?= ['Mo','Di','Mi','Do','Fr','Sa','So'][(int)$dDate->format('N') - 1] ?>
                                </span>
                                <span class="text-[10px] <?= $isTodayMobile ? 'text-indigo-600' : 'text-emerald-600' ?>">
                                    <?= $isTodayMobile ? 'Heute – Dienst' : 'Dienst' ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="mt-3 pt-3 border-t border-slate-100 flex items-center gap-4 text-[10px] text-slate-500">
                <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-indigo-600"></span> Heute</span>
                <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-emerald-300"></span> Dienst</span>
            </div>
        </div>

    </div> <!-- /tab-plan -->