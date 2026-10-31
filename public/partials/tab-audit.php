<?php
// Include-only: dieses Partial wird von admin.php gerendert. Ein direkter
// Web-Aufruf hat weder Bootstrap noch $auth/$tab und muss deshalb 404 liefern,
// statt einen Fatal Error oder halbe Ausgabe zu produzieren.
if (!defined('PSYCHKG_ADMIN')) {
    http_response_code(404);
    exit;
}
?>
        <!-- Tab: Protokoll -->
        <div id="tab-audit" role="tabpanel" aria-labelledby="tabbtn-audit" tabindex="0" class="tab-content focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 <?= $tab !== 'audit' ? 'hidden' : '' ?>">
            <div class="glass-card rounded-3xl p-6">
                <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-4 mb-6">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Aktivitätsprotokoll</h2>
                        <p class="text-xs text-slate-500 mt-1">Letzte 100 Systemereignisse</p>
                    </div>
                    <a href="admin.php?tab=audit&export=csv"
                       class="inline-flex items-center py-2.5 px-5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold uppercase transition-all shadow-md">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        CSV exportieren
                    </a>
                </div>

                <?php if (empty($auditEntries)): ?>
                    <div class="text-center py-16">
                        <svg class="w-20 h-20 mx-auto text-slate-200 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                        <p class="text-sm text-slate-500 font-medium">Noch keine Aktivitäten protokolliert.</p>
                        <p class="text-xs text-slate-400 mt-1">Aktionen im System werden hier automatisch erfasst.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm border-collapse">
                            <thead>
                                <tr class="border-b border-slate-200 text-slate-500 text-xs font-semibold uppercase">
                                    <th class="pb-3 pr-4">Zeitpunkt</th>
                                    <th class="pb-3 px-4">Mitarbeiter</th>
                                    <th class="pb-3 px-4">Aktion</th>
                                    <th class="pb-3 pl-4">Details</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($auditEntries as $entry): ?>
                                    <tr class="hover:bg-slate-50/50">
                                        <td class="py-3 pr-4 text-xs text-slate-500 whitespace-nowrap">
                                            <?= date('d.m.Y', $entry->getTimestamp()) ?>
                                            <span class="text-slate-400"><?= date('H:i', $entry->getTimestamp()) ?></span>
                                        </td>
                                        <td class="py-3 px-4 font-semibold text-slate-900 text-xs">
                                            <?= htmlspecialchars($entry->getUserName()) ?>
                                        </td>
                                        <td class="py-3 px-4">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-semibold uppercase
                                                <?php
                                                $a = $entry->getAction();
                                                if (in_array($a, ['user_created', 'schedule_generated'])) echo 'bg-emerald-50 border border-emerald-200 text-emerald-800';
                                                elseif (in_array($a, ['user_deleted', 'trade_rejected', 'schedule_reset'])) echo 'bg-rose-50 border border-rose-200 text-rose-800';
                                                elseif (in_array($a, ['trade_approved'])) echo 'bg-indigo-50 border border-indigo-200 text-indigo-800';
                                                elseif (in_array($a, ['password_changed'])) echo 'bg-amber-50 border border-amber-200 text-amber-800';
                                                else echo 'bg-slate-100 border border-slate-200 text-slate-700';
                                                ?>
                                            "><?= $entry->getActionLabel() ?></span>
                                        </td>
                                        <td class="py-3 pl-4 text-xs text-slate-500">
                                            <?= htmlspecialchars($entry->getDetailsSummary()) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>