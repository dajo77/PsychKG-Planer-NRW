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
// use-Importe gelten nur pro Datei - siehe Kommentar in tab-plan.php.
use Src\Core\Csrf;
?>
        <!-- Tab: Tauschanträge -->
        <div id="tab-trades" role="tabpanel" aria-labelledby="tabbtn-trades" tabindex="0" class="tab-content focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 <?= $tab !== 'trades' ? 'hidden' : '' ?>">
            <div class="glass-card rounded-3xl p-6">
                <h2 class="text-lg font-bold text-slate-900 mb-6">Genehmigung angenommener Tauschanträge</h2>

                <?php if (empty($pendingTrades)): ?>
                    <div class="empty-state">
                        <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <p class="empty-state-title">Keine Tauschanträge</p>
                        <p class="empty-state-text">Sobald ein Kollege einen Dienst übernimmt, erscheint der Vorgang hier.</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <?php foreach ($pendingTrades as $trade): 
                            $block = $scheduleService->getBlockById($trade->getBlockId());
                            if (!$block) continue;
                        ?>
                            <div class="p-5 rounded-2xl bg-white border-2 border-violet-200 shadow-sm space-y-4">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <div class="text-xs font-bold text-violet-600 uppercase tracking-wider">Tauschantrag</div>
                                        <div class="text-sm font-semibold text-slate-900 mt-1"><?= $block->getFormattedStart() ?> – <?= $block->getFormattedEnd() ?></div>
                                    </div>
                                    <span class="px-2 py-0.5 rounded text-[10px] bg-violet-50 border border-violet-200 text-violet-700 font-bold uppercase">
                                        Schwebend
                                    </span>
                                </div>

                                <div class="flex items-center justify-between text-xs p-3 rounded-xl bg-slate-50 border border-slate-100">
                                    <div class="text-slate-600">
                                        Ursprünglich:<br>
                                        <strong class="text-rose-700"><?= htmlspecialchars($userMap[$trade->getRequesterId()] ?? 'Mitarbeiter') ?></strong>
                                    </div>
                                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                                    <div class="text-slate-600 text-right">
                                        Übernehmer:<br>
                                        <strong class="text-emerald-700 font-bold"><?= htmlspecialchars($userMap[$trade->getReceiverId()] ?? 'Mitarbeiter') ?></strong>
                                    </div>
                                </div>

                                <div class="flex gap-2 justify-end">
                                    <form action="admin.php?tab=trades" method="POST">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="reject_trade">
                                        <input type="hidden" name="trade_id" value="<?= htmlspecialchars($trade->getId()) ?>">
                                        <button type="submit" class="py-2 px-4 rounded-xl border border-rose-200 hover:bg-rose-50 text-rose-700 text-xs font-semibold transition-all">
                                            Ablehnen
                                        </button>
                                    </form>
                                    <form action="admin.php?tab=trades" method="POST">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="approve_trade">
                                        <input type="hidden" name="trade_id" value="<?= htmlspecialchars($trade->getId()) ?>">
                                        <button type="submit" class="py-2 px-5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-all shadow-md shadow-emerald-600/10">
                                            Genehmigen
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>