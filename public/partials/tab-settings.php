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
use Src\Service\ShiftService;
?>
        <!-- Tab: Einstellungen -->
        <div id="tab-settings" role="tabpanel" aria-labelledby="tabbtn-settings" tabindex="0" class="tab-content focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 <?= $tab !== 'settings' ? 'hidden' : '' ?>">
            <div class="glass-card rounded-3xl p-6 space-y-8">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Einsatzzeiten</h2>
                    <p class="text-xs text-slate-600 mt-1">
                        Konfigurieren Sie den Bereitschaftswechsels und die Innendienst-Zeiten der Einsatzgemeinschaft.
                        Standard (NRW): Bereitschaftswechsels Mittwoch 16:00 Uhr, Innendienst Mo–Do 08:00–16:00 Uhr, Freitag 08:00–12:00 Uhr.
                    </p>
                </div>

                <form action="admin.php?tab=settings" method="POST" class="max-w-2xl space-y-6">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="update_shift_settings">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <label for="shift_duty_start_day" class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Bereitschaftswechsels (Wochentag)</label>
                            <select id="shift_duty_start_day" name="shift[duty_start_day]"
                                    class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-xl text-sm text-slate-900 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-600 shadow-sm">
                                <?php foreach (range(1, 7) as $dow): ?>
                                    <option value="<?= $dow ?>" <?= $dow === ShiftService::getDutyStartDay() ? 'selected' : '' ?>>
                                        <?= ShiftService::getDayName($dow) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="shift_duty_start_time" class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Bereitschaftswechsels (Uhrzeit)</label>
                            <input type="text" id="shift_duty_start_time" name="shift[duty_start_time]" value="<?= htmlspecialchars(ShiftService::getDutyStartTime()) ?>"
                                   pattern="^([01][0-9]|2[0-3]):[0-5][0-9]$" required
                                   class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-xl text-sm text-slate-900 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-600 shadow-sm font-mono" />
                            <p class="text-[11px] text-slate-500 mt-2">Bereitschaft beginnt an diesem Wochentag um diese Uhrzeit (Bereitschaftswechsels / Dienstbeginn).</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between p-4 rounded-2xl bg-white border border-slate-200">
                        <div>
                            <div class="text-sm font-bold text-slate-900">Innendienst aktiv</div>
                            <div class="text-[11px] text-slate-500 mt-0.5">Wenn deaktiviert, gilt durchgehende Bereitschaft (24/7) ohne Innendienst-Zeiten.</div>
                        </div>
                        <select name="shift[innendienst_enabled]" id="shift_innendienst_enabled"
                                class="w-24 px-3 py-2.5 bg-white border border-slate-200 rounded-xl text-sm text-slate-900 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-600 shadow-sm">
                            <option value="1" <?= ShiftService::isInnendienstEnabled() ? 'selected' : '' ?>>Ja</option>
                            <option value="0" <?= !ShiftService::isInnendienstEnabled() ? 'selected' : '' ?>>Nein</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                        <div>
                            <label for="shift_innendienst_start" class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Innendienst Beginn</label>
                            <input type="text" id="shift_innendienst_start" name="shift[innendienst_start]" value="<?= htmlspecialchars(ShiftService::getInnendienstStart()) ?>"
                                   pattern="^([01][0-9]|2[0-3]):[0-5][0-9]$" required
                                   class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-xl text-sm text-slate-900 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-600 shadow-sm font-mono" />
                        </div>
                        <div>
                            <label for="shift_innendienst_end" class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Innendienst Ende</label>
                            <input type="text" id="shift_innendienst_end" name="shift[innendienst_end]" value="<?= htmlspecialchars(ShiftService::getInnendienstEnd()) ?>"
                                   pattern="^([01][0-9]|2[0-3]):[0-5][0-9]$" required
                                   class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-xl text-sm text-slate-900 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-600 shadow-sm font-mono" />
                        </div>
                        <div>
                            <label for="shift_innendienst_friday_end" class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Innendienst Ende (Freitag)</label>
                            <input type="text" id="shift_innendienst_friday_end" name="shift[innendienst_friday_end]" value="<?= htmlspecialchars(ShiftService::getInnendienstFridayEnd()) ?>"
                                   pattern="^([01][0-9]|2[0-3]):[0-5][0-9]$" required
                                   class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-xl text-sm text-slate-900 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-600 shadow-sm font-mono" />
                        </div>
                    </div>

                    <div>
                        <span class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Innendienst-Tage</span>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <?php foreach ([1, 2, 3, 4, 5] as $dow): ?>
                                <label class="flex items-center p-3 bg-white border-2 border-slate-200 rounded-xl cursor-pointer hover:border-indigo-300 transition-all has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50">
                                    <input type="checkbox" name="shift[innendienst_office_days][]" value="<?= $dow ?>" class="sr-only"
                                           <?= in_array($dow, ShiftService::getInnendienstOfficeDays(), true) ? 'checked' : '' ?> />
                                    <span class="text-sm font-bold text-slate-800"><?= ShiftService::getDayName($dow) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-[11px] text-slate-500 mt-2">Solche Tage gelten als Innendienst-Tage. Wochenenden und Feiertagen gilt immer durchgehende Bereitschaft.</p>
                    </div>

                    <div class="pt-2">
                        <button type="submit"
                                class="py-3 px-6 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold uppercase rounded-xl transition-all shadow-md shadow-indigo-600/10">
                            Einsatzzeiten speichern
                        </button>
                    </div>
                </form>
            </div>
        </div>
