<?php
/**
 * Shared navigation bar template.
 * Expects: $currentUser (User object), $activePage (string: 'dashboard'|'marketplace'|'admin')
 *
 * The logout control is a form (POST + CSRF) rather than a plain link, so a
 * third-party site cannot force a logout via <img src="logout.php">.
 */
?>
<?php $logoutForm = static function (string $labelClass, string $extra = ''): void { ?>
    <form action="logout.php" method="POST" class="<?= $extra ?>">
        <?= \Src\Core\Csrf::field() ?>
        <button type="submit" class="<?= $labelClass ?>">Abmelden</button>
    </form>
<?php }; ?>
<!-- Ambiente-Hintergrund (alle App-Seiten): Farb-Blobs + Punktraster, hinter dem Inhalt, ohne Print -->
<div class="fixed inset-0 z-[-1] overflow-hidden pointer-events-none print:hidden" aria-hidden="true">
    <div class="absolute -top-32 -left-32 w-[28rem] h-[28rem] bg-indigo-300 rounded-full filter blur-[130px] opacity-25 anim-drift"></div>
    <div class="absolute -bottom-40 -right-32 w-[30rem] h-[30rem] bg-emerald-200 rounded-full filter blur-[130px] opacity-25 anim-drift-2"></div>
    <div class="absolute top-1/3 left-1/2 -translate-x-1/2 w-[36rem] h-[36rem] bg-violet-200 rounded-full filter blur-[140px] opacity-20"></div>
    <div class="dot-grid absolute inset-0"></div>
</div>
<?php
$isActiveDashboard  = ($activePage === 'dashboard');
$isActiveMarketplace = ($activePage === 'marketplace');
$isActiveAdmin      = ($activePage === 'admin');

$activeClass   = 'text-indigo-700 bg-indigo-50/70 border-b-2 border-indigo-600 px-3 py-2 rounded-lg text-sm font-semibold';
$inactiveClass = 'text-slate-600 hover:text-slate-900 hover:bg-slate-100/50 px-3 py-2 rounded-lg text-sm font-medium transition-all';

$mobileActiveClass   = 'block px-3 py-2 rounded-lg text-sm font-semibold text-indigo-700 bg-indigo-50';
$mobileInactiveClass = 'block px-3 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-50';
?>
<a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-[100] focus:px-4 focus:py-2 focus:bg-indigo-600 focus:text-white focus:rounded-lg">Zum Hauptinhalt</a>
<nav aria-label="Hauptnavigation" class="border-b border-slate-200 bg-white/90 backdrop-blur-md sticky top-0 z-50 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center">
                <div class="flex-shrink-0 flex items-center space-x-2">
                    <div class="p-2 rounded-lg bg-gradient-to-tr from-indigo-600 to-indigo-700 text-white">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <span class="font-display font-bold text-lg text-slate-800">PsychKG Planer</span>
                </div>
                <div class="hidden sm:ml-8 sm:flex sm:space-x-4">
                    <a href="dashboard.php" class="<?= $isActiveDashboard ? $activeClass : $inactiveClass ?>">Dashboard</a>
                    <a href="marketplace.php" class="<?= $isActiveMarketplace ? $activeClass : $inactiveClass ?>">Tauschangebote</a>
                    <?php if ($currentUser->isAdmin()): ?>
                        <a href="admin.php" class="<?= $isActiveAdmin ? $activeClass : $inactiveClass ?>">Admin-Bereich</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex items-center space-x-3">
                <?= $logoutForm('hidden sm:inline-flex px-4 py-2 rounded-lg border border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-800 transition-all shadow-sm') ?>
                <button id="pwa-notify-btn" data-pwa-notify aria-label="Benachrichtigungen" class="hidden sm:inline-flex p-2 rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-slate-800 transition-all shadow-sm" title="Benachrichtigungen">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                </button>
                <button id="dark-mode-btn" data-dark-toggle aria-pressed="<?= \Src\Core\Config::isDarkModeDefault() ? 'true' : 'false' ?>" class="hidden sm:inline-flex p-2 rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-slate-800 transition-all shadow-sm" title="Dunkel-Modus umschalten">
                    <svg id="dark-icon-sun" class="w-4 h-4 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    <svg id="dark-icon-moon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                </button>
                <button id="mobile-menu-btn" aria-label="Menü öffnen" aria-expanded="false" class="sm:hidden p-2 rounded-lg text-slate-600 hover:bg-slate-100 transition-all">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
            </div>
        </div>
        <!-- Mobile Menu -->
        <div id="mobile-menu" class="hidden sm:hidden border-t border-slate-200 bg-white pb-4">
            <div class="pt-3 space-y-1">
                <a href="dashboard.php" class="<?= $isActiveDashboard ? $mobileActiveClass : $mobileInactiveClass ?>">Dashboard</a>
                <a href="marketplace.php" class="<?= $isActiveMarketplace ? $mobileActiveClass : $mobileInactiveClass ?>">Tauschangebote</a>
                <?php if ($currentUser->isAdmin()): ?>
                    <a href="admin.php" class="<?= $isActiveAdmin ? $mobileActiveClass : $mobileInactiveClass ?>">Admin-Bereich</a>
                <?php endif; ?>
                <hr class="my-2 border-slate-100">
                <?= $logoutForm('block w-full text-left px-3 py-2 rounded-lg text-sm text-rose-600 hover:bg-rose-50', 'mt-1') ?>
                <button type="button" data-dark-toggle class="block w-full text-left px-3 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-50">Dunkel-Modus umschalten</button>
            </div>
        </div>
    </div>
</nav>

<!-- Toast Notification System + Dark Mode (Dark-Farben zentral in assets/app.css) -->

<div class="toast-container" id="toast-container"></div>

<script src="assets/js/nav.js" nonce="<?= CSP_NONCE ?>"></script>
<script src="assets/js/pwa.js" nonce="<?= CSP_NONCE ?>"></script>
