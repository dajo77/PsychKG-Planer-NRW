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
        <!-- Tab: Mitarbeiter -->
        <div id="tab-users" role="tabpanel" aria-labelledby="tabbtn-users" tabindex="0" class="tab-content focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 <?= $tab !== 'users' ? 'hidden' : '' ?>">
            <div class="glass-card rounded-3xl p-6 space-y-6">
                <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Mitarbeiterverwaltung</h2>
                        <p class="text-xs text-slate-600 mt-1">Mitarbeiter anlegen, bearbeiten oder entfernen.</p>
                    </div>
                </div>

                <!-- User Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 text-slate-500 text-xs font-semibold uppercase">
                                <th class="pb-3 pr-4">Name</th>
                                <th class="pb-3 px-4">Benutzername</th>
                                <th class="pb-3 px-4">Rolle</th>
                                <th class="pb-3 pl-4 text-right">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($allUsers as $user): ?>
                                <?php if ($editingUserId === $user->getId()): ?>
                                    <!-- Inline Edit Row -->
                                    <tr class="bg-indigo-50/40">
                                        <td colspan="4" class="py-4 px-4">
                                            <form action="admin.php?tab=users" method="POST" class="space-y-3">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="action" value="update_user">
                                                <input type="hidden" name="edit_user_id" value="<?= $user->getId() ?>">
                                                <div class="text-xs font-bold text-indigo-600 uppercase tracking-wider mb-2">Mitarbeiter bearbeiten</div>
                                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                                                    <div>
                                                        <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">Vollständiger Name</label>
                                                        <input type="text" name="edit_name" value="<?= htmlspecialchars($user->getName()) ?>" required
                                                               class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                                                    </div>
                                                    <div>
                                                        <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">Benutzername</label>
                                                        <input type="text" name="edit_username" value="<?= htmlspecialchars($user->getUsername()) ?>" required
                                                               class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                                                    </div>
                                                    <div>
                                                        <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">Neues Passwort <span class="text-slate-400">(leer = unverändert)</span></label>
                                                        <input type="password" name="edit_password" placeholder="Unverändert lassen…"
                                                               class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                                                    </div>
                                                    <div>
                                                        <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">Rolle</label>
                                                        <select name="edit_role"
                                                                class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm">
                                                            <option value="mitarbeiter" <?= $user->getRole() === 'mitarbeiter' ? 'selected' : '' ?>>Mitarbeiter</option>
                                                            <option value="admin" <?= $user->getRole() === 'admin' ? 'selected' : '' ?>>Administrator</option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">Telefon (dienstlich)</label>
                                                        <input type="tel" name="edit_phone" value="<?= htmlspecialchars($user->getPhone()) ?>"
                                                               class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                                                    </div>
                                                    <div>
                                                        <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">E-Mail</label>
                                                        <input type="email" name="edit_email" value="<?= htmlspecialchars($user->getEmail()) ?>"
                                                               class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                                                    </div>
                                                </div>
                                                <div class="flex gap-2 justify-end pt-1">
                                                    <a href="admin.php?tab=users" class="py-2 px-4 rounded-lg border border-slate-200 text-slate-600 text-xs font-semibold hover:bg-slate-50 transition-all">Abbrechen</a>
                                                    <button type="submit" class="py-2 px-5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold transition-all shadow-md">Speichern</button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <!-- Normal Display Row -->
                                    <tr class="hover:bg-slate-50/50">
                                        <td class="py-3.5 pr-4 font-semibold text-slate-900">
                                            <?= htmlspecialchars($user->getName()) ?>
                                            <?php if ($user->getId() === $currentUser->getId()): ?>
                                                <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] bg-indigo-100 text-indigo-700 font-bold uppercase">Sie</span>
                                            <?php endif; ?>
                                            <?php if ($user->getPhone() !== ''): ?>
                                                <div class="text-[11px] font-normal text-slate-500"><?= htmlspecialchars($user->getPhone()) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-slate-600 font-mono text-xs"><?= htmlspecialchars($user->getUsername()) ?></td>
                                        <td class="py-3.5 px-4">
                                            <?php if ($user->getRole() === 'admin'): ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] bg-indigo-50 border border-indigo-200 text-indigo-700 font-semibold">Administrator</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] bg-slate-100 border border-slate-200 text-slate-600 font-semibold">Mitarbeiter</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 pl-4 text-right">
                                            <div class="flex justify-end gap-2">
                                                <a href="admin.php?edit=<?= $user->getId() ?>&tab=users"
                                                   class="py-1.5 px-3 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600 text-xs font-semibold transition-all">
                                                    Bearbeiten
                                                </a>
                                                <?php if ($user->getId() !== $currentUser->getId()): ?>
                                                    <form action="admin.php?tab=users" method="POST" class="inline" data-confirm="Mitarbeiter <?= \Src\Core\Html::js($user->getName()) ?> wirklich endgültig löschen?">
                                                        <?= Csrf::field() ?>
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="delete_user_id" value="<?= $user->getId() ?>">
                                                        <button type="submit" class="py-1.5 px-3 rounded-lg border border-rose-200 hover:bg-rose-50 text-rose-600 text-xs font-semibold transition-all">
                                                            Löschen
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Add New User Form -->
                <div class="mt-4 p-5 rounded-2xl bg-white border border-slate-200 shadow-sm">
                    <div class="text-xs font-bold text-emerald-600 uppercase tracking-wider mb-4">Neuen Mitarbeiter anlegen</div>
                    <form action="admin.php?tab=users" method="POST">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="add_user">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div>
                                <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">Vollständiger Name *</label>
                                <input type="text" name="new_name" required placeholder="z.B. Maria Beispiel"
                                       class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">Benutzername *</label>
                                <input type="text" name="new_username" required placeholder="z.B. m.beispiel"
                                       class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">Passwort *</label>
                                <input type="password" name="new_password" required placeholder="Mind. 8 Zeichen" minlength="8"
                                       class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">Rolle</label>
                                <select name="new_role"
                                        class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm">
                                    <option value="mitarbeiter" selected>Mitarbeiter</option>
                                    <option value="admin">Administrator</option>
                                </select>
                                <p class="text-[10px] text-slate-400 mt-1">Hinweis: Es ist genau ein Administrator vorgesehen.</p>
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">Telefon (dienstlich)</label>
                                <input type="tel" name="new_phone" placeholder="z.B. 0211 123456"
                                       class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">E-Mail</label>
                                <input type="email" name="new_email" placeholder="optional"
                                       class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                            </div>
                            <div class="flex items-end sm:col-span-2">
                                <button type="submit"
                                        class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold uppercase rounded-lg transition-all shadow-md">
                                    Anlegen
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <!-- Absences: vacation/sick leave respected by future rotations -->
                <div class="mt-4 p-5 rounded-2xl bg-white border border-slate-200 shadow-sm">
                    <div class="text-xs font-bold text-indigo-600 uppercase tracking-wider mb-1">Abwesenheiten (Urlaub / Krank)</div>
                    <p class="text-[11px] text-slate-500 mb-4">Abwesende werden bei künftigen Plan-Generierungen automatisch übersprungen. Bereits erstellte Blöcke bleiben unverändert.</p>
                    <form action="admin.php?tab=users" method="POST" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 mb-4">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="absence_add">
                        <div>
                            <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">Mitarbeiter</label>
                            <select name="absence_user_id" class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm">
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= $emp->getId() ?>"><?= htmlspecialchars($emp->getName()) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">Von</label>
                            <input type="date" name="absence_start" required class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">Bis</label>
                            <input type="date" name="absence_end" required class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm" />
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold uppercase text-slate-500 mb-1">Grund</label>
                            <select name="absence_reason" class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-lg text-slate-900 text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm">
                                <?php foreach (\Src\Service\AbsenceService::REASONS as $key => $label): ?>
                                    <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold uppercase rounded-lg transition-all shadow-md">Eintragen</button>
                        </div>
                    </form>
                    <?php if (empty($allAbsences)): ?>
                        <div class="empty-state py-6">
                            <svg class="empty-state-icon" style="width:2rem;height:2rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <p class="empty-state-title" style="font-size:0.75rem;">Keine Abwesenheiten</p>
                            <p class="empty-state-text" style="font-size:0.75rem;">Abwesende werden bei Plan-Generierungen übersprungen.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-1.5">
                            <?php foreach ($allAbsences as $abs):
                                $absName = $userMap[$abs['user_id'] ?? ''] ?? 'Unbekannt';
                            ?>
                                <div class="flex items-center gap-3 p-2 rounded-xl bg-slate-50 border border-slate-200">
                                    <div class="flex-1 min-w-0 text-xs">
                                        <strong class="text-slate-900"><?= htmlspecialchars($absName) ?></strong>
                                        <span class="text-slate-500"><?= date('d.m.Y', strtotime($abs['start'])) ?> – <?= date('d.m.Y', strtotime($abs['end'])) ?></span>
                                        <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-bold uppercase bg-indigo-50 border border-indigo-200 text-indigo-700"><?= htmlspecialchars(\Src\Service\AbsenceService::reasonLabel($abs['reason'] ?? '')) ?></span>
                                    </div>
                                    <form action="admin.php?tab=users" method="POST" class="flex-shrink-0" data-confirm="Abwesenheit wirklich löschen?">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="absence_delete">
                                        <input type="hidden" name="absence_id" value="<?= htmlspecialchars($abs['id'] ?? '') ?>">
                                        <button type="submit" class="px-2.5 py-1 rounded-lg border border-rose-200 hover:bg-rose-50 text-rose-600 text-[10px] font-bold uppercase transition-all">Löschen</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>