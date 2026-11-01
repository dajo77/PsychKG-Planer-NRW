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
        <!-- Tab: Mein Konto -->
        <div id="tab-profile" role="tabpanel" aria-labelledby="tabbtn-profile" tabindex="0" class="tab-content focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 <?= $tab !== 'profile' ? 'hidden' : '' ?>">
            <div class="glass-card rounded-3xl p-6 max-w-lg">
                <h2 class="text-lg font-bold text-slate-900 mb-2">Passwort ändern</h2>
                <p class="text-xs text-slate-500 mb-6">Ändern Sie Ihr persönliches Anmeldepasswort.</p>

                <form action="admin.php?tab=profile" method="POST" class="space-y-4">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="change_password">

                    <div>
                        <label for="profile-current-password" class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1">Aktuelles Passwort</label>
                        <input type="password" id="profile-current-password" name="current_password" required
                               class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-slate-900 text-sm focus:ring-2 focus:ring-indigo-600 focus:border-transparent transition-all shadow-sm" />
                    </div>

                    <div>
                        <label for="profile-new-password" class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1">Neues Passwort</label>
                        <input type="password" id="profile-new-password" name="new_password" required minlength="12" placeholder="Mind. 12 Zeichen, inkl. Sonderzeichen"
                               class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-slate-900 text-sm focus:ring-2 focus:ring-indigo-600 focus:border-transparent transition-all shadow-sm" />
                    </div>

                    <div>
                        <label for="profile-confirm-password" class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1">Neues Passwort bestätigen</label>
                        <input type="password" id="profile-confirm-password" name="confirm_password" required minlength="12"
                               class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-slate-900 text-sm focus:ring-2 focus:ring-indigo-600 focus:border-transparent transition-all shadow-sm" />
                    </div>

                    <div class="pt-2">
                        <button type="submit"
                                class="py-3 px-6 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold uppercase rounded-xl transition-all shadow-md shadow-indigo-600/10">
                            Passwort ändern
                        </button>
                    </div>
                </form>
            </div>
        </div>