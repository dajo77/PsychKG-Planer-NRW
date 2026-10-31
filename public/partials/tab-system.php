<?php
// Include-only: dieses Partial wird von admin.php gerendert. Ein direkter
// Web-Aufruf hat weder Bootstrap noch $auth/$tab und muss deshalb 404 liefern,
// statt einen Fatal Error oder halbe Ausgabe zu produzieren.
if (!defined('PSYCHKG_ADMIN')) {
    http_response_code(404);
    exit;
}
?>
        <!-- Tab: System (Gesundheitscheck) -->
        <div id="tab-system" role="tabpanel" aria-labelledby="tabbtn-system" tabindex="0" class="tab-content focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 <?= $tab !== 'system' ? 'hidden' : '' ?>">
            <div class="glass-card rounded-3xl p-6 space-y-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Systemstatus</h2>
                    <p class="text-xs text-slate-600 mt-1">Schreibgeschützte Prüfung – hier wird nichts verändert.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 text-slate-500 text-xs font-semibold uppercase">
                                <th class="pb-3 pr-4">Prüfung</th>
                                <th class="pb-3 px-4">Ergebnis</th>
                                <th class="pb-3 pl-4 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($healthChecks as $check): ?>
                                <tr class="hover:bg-slate-50/50">
                                    <td class="py-2.5 pr-4 font-semibold text-slate-900 text-xs"><?= htmlspecialchars($check['label']) ?></td>
                                    <td class="py-2.5 px-4 text-xs text-slate-600 font-mono"><?= htmlspecialchars($check['detail']) ?></td>
                                    <td class="py-2.5 pl-4 text-right">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $check['ok'] ? 'bg-emerald-100 border border-emerald-200 text-emerald-700' : 'bg-rose-100 border border-rose-200 text-rose-700' ?>">
                                            <?= $check['ok'] ? 'OK' : 'Handeln' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>