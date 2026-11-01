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
        <!-- Tab: Nachweise (versiegelte Monatspläne) -->
        <div id="tab-nachweise" role="tabpanel" aria-labelledby="tabbtn-nachweise" tabindex="0" class="tab-content focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 <?= $tab !== 'nachweise' ? 'hidden' : '' ?>">
            <div class="glass-card rounded-3xl p-6 space-y-6">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Nachweise</h2>
                    <p class="text-xs text-slate-600 mt-1">Versiegelt den Dienstplan eines Monats mit Prüfsumme – als Nachweis, wer wann Dienst hatte (z.B. für rechtliche Anfragen).</p>
                </div>
                <form action="admin.php?tab=nachweise" method="POST" class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-end p-4 rounded-2xl bg-white border border-slate-200 shadow-sm" data-confirm="Dienstplan für diesen Monat unwiderruflich versiegeln?">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="snapshot_create">
                    <div>
                        <label for="snapshot-month" class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">Monat versiegeln</label>
                        <input type="month" id="snapshot-month" name="snapshot_month" value="<?= date('Y-m') ?>" required
                               class="px-3 py-2.5 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                    </div>
                    <button type="submit"
                            class="py-2.5 px-6 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold uppercase rounded-xl transition-all shadow-md">
                        Versiegeln
                    </button>
                </form>
                <?php if (empty($allSnapshots)): ?>
                    <p class="text-xs text-slate-400 text-center py-6">Noch keine Versiegelungen vorhanden.</p>
                <?php else: ?>
                    <div class="space-y-2.5">
                        <?php foreach ($allSnapshots as $snap):
                            $valid = $snapshotService->verify($snap);
                        ?>
                            <div class="p-4 rounded-xl bg-white border border-slate-200 shadow-sm">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                    <div>
                                        <div class="text-sm font-bold text-slate-900">
                                            <?= htmlspecialchars((\Src\Service\ScheduleService::getGermanMonthNames()[(int)substr($snap['month'], 5, 2)] ?? $snap['month'])) ?>
                                            <?= htmlspecialchars(substr($snap['month'], 0, 4)) ?>
                                        </div>
                                        <div class="text-[11px] text-slate-500 mt-0.5">
                                            <?= count($snap['blocks'] ?? []) ?> Blöcke · versiegelt am <?= htmlspecialchars($snap['created_at'] ?? '') ?> von <?= htmlspecialchars($snap['created_by_name'] ?? '') ?>
                                        </div>
                                        <div class="text-[10px] font-mono text-slate-400 mt-0.5">SHA-256: <?= htmlspecialchars(substr($snap['hash'] ?? '', 0, 16)) ?>…</div>
                                    </div>
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase flex-shrink-0 self-start sm:self-center <?= $valid ? 'bg-emerald-100 border border-emerald-200 text-emerald-700' : 'bg-rose-100 border border-rose-200 text-rose-700' ?>">
                                        <?= $valid ? 'Prüfsumme gültig' : 'MANIPULIERT?' ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>