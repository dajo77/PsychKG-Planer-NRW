<?php
// Include-only: dieses Partial wird von admin.php gerendert. Ein direkter
// Web-Aufruf hat weder Bootstrap noch $auth/$tab und muss deshalb 404 liefern,
// statt einen Fatal Error oder halbe Ausgabe zu produzieren.
if (!defined('PSYCHKG_ADMIN')) {
    http_response_code(404);
    exit;
}
?>
        <!-- Tab: Abrechnung -->
        <div id="tab-billing" role="tabpanel" aria-labelledby="tabbtn-billing" tabindex="0" class="tab-content focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 <?= $tab !== 'billing' ? 'hidden' : '' ?>">
            <div class="glass-card rounded-3xl p-6 space-y-6">
                <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Dienstzeit-Abrechnung</h2>
                        <p class="text-xs text-slate-600 mt-1">Bereitschaftsstunden unter Ausschluss von Innendienst und NRW-Feiertagen.</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="admin.php?export=csv&start=<?= $billingStartInput ?>&end=<?= $billingEndInput ?>" 
                           class="inline-flex items-center py-2.5 px-5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold uppercase transition-all shadow-md">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            CSV herunterladen
                        </a>
                        <a href="export_pdf.php?start=<?= $billingStartInput ?>&end=<?= $billingEndInput ?>" 
                           target="_blank"
                           class="inline-flex items-center py-2.5 px-5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold uppercase transition-all shadow-md">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                            PDF exportieren
                        </a>
                    </div>
                </div>

                <!-- Date Filter Form -->
                <form action="admin.php" method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-4 p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
                    <input type="hidden" name="tab" value="billing">
                    <div>
                        <label for="billing-start" class="block text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Von Datum</label>
                        <input type="date" id="billing-start" name="start" value="<?= htmlspecialchars($billingStartInput) ?>"
                               class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                    </div>
                    <div>
                        <label for="billing-end" class="block text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Bis Datum</label>
                        <input type="date" id="billing-end" name="end" value="<?= htmlspecialchars($billingEndInput) ?>"
                               class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                    </div>
                    <div class="flex items-end">
                        <button type="submit" 
                                class="w-full py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 text-xs font-bold uppercase rounded-lg transition-all shadow-sm">
                            Berechnen
                        </button>
                    </div>
                </form>

                <!-- Render Billing Summary Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 text-slate-500 text-xs font-semibold uppercase">
                                <th class="pb-3 pr-4">Mitarbeiter</th>
                                <th class="pb-3 px-4 text-center">Geleistete Dienste</th>
                                <th class="pb-3 px-4 text-right">Bereitschaftsstunden (Netto)</th>
                                <th class="pb-3 pl-4">Zugeordnete Blöcke</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (!$billingHasActivity): ?>
                                <tr>
                                    <td colspan="4" class="py-12 text-center">
                                        <svg class="w-16 h-16 mx-auto text-slate-200 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        <p class="text-sm text-slate-500 font-medium">Keine aktiven Dienste im gewählten Zeitraum.</p>
                                        <p class="text-xs text-slate-400 mt-1">Passen Sie den Datumsbereich an oder erstellen Sie einen Dienstplan.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($billingSummary as $data): 
                                    $user = $data['user'];
                                ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="py-4 pr-4 font-semibold text-slate-900">
                                            <?= htmlspecialchars($user->getName()) ?>
                                        </td>
                                        <td class="py-4 px-4 text-center font-bold text-indigo-600">
                                            <?= $data['blocks_count'] ?>
                                        </td>
                                        <td class="py-4 px-4 text-right font-mono font-bold text-emerald-700">
                                            <?= number_format($data['total_hours'], 1, ',', '') ?> Std.
                                        </td>
                                        <td class="py-4 pl-4 text-xs text-slate-500">
                                            <div class="flex flex-wrap gap-1.5">
                                                <?php if (empty($data['details'])): ?>
                                                    <span class="text-slate-300">—</span>
                                                <?php else: ?>
                                                    <?php foreach ($data['details'] as $detail): ?>
                                                        <span class="px-2 py-1 rounded bg-slate-50 border border-slate-200 text-[10px] text-slate-600 font-semibold shadow-sm">
                                                            <?= date('d.m.', $detail['start_ts']) ?> – <?= date('d.m.', $detail['end_ts']) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Jahres-Fairness: AO-Belastung pro Mitarbeiter -->
                <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-sm">
                    <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-3 mb-4">
                        <div>
                            <div class="text-xs font-bold text-indigo-600 uppercase tracking-wider">Jahres-Fairness <?= $fairYear ?></div>
                            <p class="text-[11px] text-slate-500 mt-0.5">Blöcke, Netto-Stunden, Wochenend- und Feiertagstage je Mitarbeiter.</p>
                        </div>
                        <form action="admin.php" method="GET" class="flex items-center gap-2">
                            <input type="hidden" name="tab" value="billing">
                            <input type="number" name="fair_year" value="<?= $fairYear ?>" min="2000" max="2100"
                                   class="w-24 px-3 py-2 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 text-xs font-bold uppercase transition-all shadow-sm">Jahr</button>
                        </form>
                    </div>
                    <?php
                    // Max-Werte für die Balkendarstellung (Duty-Rotation / Jahreslast)
                    $maxFairHours = 0.0;
                    foreach ($fairStats as $fairRow) {
                        if ((float)($fairRow['hours'] ?? 0) > $maxFairHours) {
                            $maxFairHours = (float)$fairRow['hours'];
                        }
                    }
                    ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm border-collapse">
                            <thead>
                                <tr class="border-b border-slate-200 text-slate-500 text-xs font-semibold uppercase">
                                    <th class="pb-3 pr-4">Mitarbeiter</th>
                                    <th class="pb-3 px-4 text-center">Blöcke</th>
                                    <th class="pb-3 px-4 text-right">Stunden</th>
                                    <th class="pb-3 px-4 text-center">Dienste (Tage)</th>
                                    <th class="pb-3 px-4 text-center">davon WE</th>
                                    <th class="pb-3 pl-4 text-center">davon Feiertag</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($fairStats as $row):
                                    $fu = $row['user'];
                                ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="py-3 pr-4 font-semibold text-slate-900 text-xs"><?= htmlspecialchars($fu->getName()) ?></td>
                                        <td class="py-3 px-4 text-center font-bold text-indigo-600 text-xs"><?= $row['blocks'] ?></td>
                                        <td class="py-3 px-4 text-right font-mono font-bold text-emerald-700 text-xs">
                                            <?= number_format($row['hours'], 1, ',', '') ?> Std.
                                            <div class="mt-1 h-1.5 w-full max-w-[12rem] bg-slate-100 rounded-full overflow-hidden ml-auto">
                                                <div class="h-full bg-gradient-to-r from-emerald-400 via-emerald-500 to-emerald-600 rounded-full transition-all duration-500"
                                                     style="width: <?= $maxFairHours > 0 ? min(100, round(($row['hours'] / $maxFairHours) * 100)) : 0 ?>%"></div>
                                            </div>
                                        </td>
                                        <td class="py-3 px-4 text-center text-xs text-slate-600"><?= $row['days'] ?></td>
                                        <td class="py-3 px-4 text-center text-xs text-slate-600"><?= $row['weekend_days'] ?></td>
                                        <td class="py-3 pl-4 text-center text-xs text-slate-600"><?= $row['holiday_days'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Heatmap: Belastung nach Wochentag (Jahres-Fairness) -->
                    <?php
                    $heatMax = 1;
                    foreach ($fairStats as $row) {
                        foreach ($row['weekday_counts'] ?? [] as $c) {
                            $heatMax = max($heatMax, (int)$c);
                        }
                    }
                    ?>
                    <div class="mt-6 p-4 rounded-xl bg-slate-50 border border-slate-200">
                        <div class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3">Belastungs-Heatmap · Jahres-Fairness <?= (int)$fairYear ?></div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-[10px] border-collapse">
                                <thead>
                                    <tr>
                                        <th class="py-2 pr-3 font-semibold text-slate-500">Mitarbeiter</th>
                                        <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $d): ?>
                                            <th class="py-2 px-1 text-center font-semibold text-slate-500"><?= $d ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($fairStats as $row):
                                        $fu = $row['user'];
                                    ?>
                                    <tr>
                                        <td class="py-1.5 pr-3 font-semibold text-slate-700 truncate max-w-[10rem]"><?= htmlspecialchars($fu->getName()) ?></td>
                                        <?php foreach ($row['weekday_counts'] ?? array_fill(0, 7, 0) as $c):
                                            $intensity = $heatMax > 0 ? $c / $heatMax : 0;
                                            $alpha = round(0.12 + 0.75 * $intensity, 2);
                                        ?>
                                        <td class="py-1.5 px-1 text-center">
                                            <div class="w-8 h-8 mx-auto rounded-md flex items-center justify-center font-bold"
                                                 style="background: rgba(79, 70, 229, <?= ((int)($alpha*100)/100) ?>); color: <?= $intensity > 0.55 ? '#fff' : '#312e81' ?>;"
                                                 title="<?= (int)$c ?> Block<?= $c === 1 ? '' : 's' ?>">
                                                <?= (int)$c ?>
                                            </div>
                                        </td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>