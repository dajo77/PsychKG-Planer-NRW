<?php
require_once __DIR__ . '/../src/bootstrap.php';

use Src\Auth\AuthManager;
use Src\Core\Config;
use Src\Core\Csrf;
use Src\Core\Flash;
use Src\Core\Html;
use Src\I18n\Translator;
use Src\Security\LoginThrottle;
use Src\Service\AuditService;
use Src\Model\AuditEntry;

$auth = new AuthManager();
$auditService = new AuditService();
if ($auth->isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;
$noSuccess = null;
Flash::consume($noSuccess, $error, [], [
    'login_empty' => Translator::t('login.error.empty'),
    'login_locked' => Translator::t('login.error.locked'),
    'login_invalid' => Translator::t('login.error.invalid'),
]);

// Rate limiting: per client IP and per username. Behind the Plesk/nginx proxy
// REMOTE_ADDR is the proxy address, so Config::getClientIp() resolves the
// forwarded client (X-Forwarded-For is only trusted from a trusted proxy).
// LoginThrottle kapselt die dateibasierte Zählung (flock, prune, Monitoring).
$clientIp = Config::getClientIp();
$rateLimitMax = 5;
$rateLimitWindow = 900;
$throttle = new LoginThrottle();
$throttle->maybePrune();

[$attemptCount, $rateLimited] = $throttle->check('ip', $clientIp, $rateLimitMax, $rateLimitWindow);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($rateLimited) {
        Flash::redirect('login.php', null, 'login_locked');
    } else {
        Csrf::requireValid();
        $username = Html::p('username');
        $password = Html::pRaw('password');

        if ($username === '' || $password === '') {
            Flash::redirect('login.php', null, 'login_empty');
        } else {
            $userKey = mb_strtolower($username);
            [, $userRateLimited] = $throttle->check('user', $userKey, $rateLimitMax, $rateLimitWindow);
            if ($userRateLimited) {
                Flash::redirect('login.php', null, 'login_locked');
            }

            if ($auth->login($username, $password)) {
                $throttle->reset('ip', $clientIp);
                $throttle->reset('user', $userKey);
                $auditService->log(AuditEntry::LOGIN_SUCCESS, 'user', $username);
                $_SESSION['just_logged_in'] = true;
                header('Location: dashboard.php');
                exit;
            }
            $throttle->recordFailure('ip', $clientIp, $rateLimitMax, $rateLimitWindow);
            $throttle->recordFailure('user', $userKey, $rateLimitMax, $rateLimitWindow);
            $auditService->log(AuditEntry::LOGIN_FAILED, 'user', $username);
            Flash::redirect('login.php', null, 'login_invalid');
        }
    }
}

$msg = $_GET['msg'] ?? '';
$infoMessage = null;
if ($msg === 'logged_out') $infoMessage = Translator::t('login.info.logged_out');
elseif ($msg === 'session_invalid') $infoMessage = Translator::t('login.info.session_invalid');
?>
<!DOCTYPE html>
<html lang="de" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(Translator::t('login.title')) ?></title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 192 192'><rect width='192' height='192' rx='40' fill='%234f46e5'/><text x='96' y='130' font-size='120' text-anchor='middle' fill='white' font-family='system-ui,sans-serif' font-weight='700'>P</text></svg>">
    <link rel="stylesheet" href="assets/fonts.css">
    <meta name="theme-color" content="#312e81">
    <script src="assets/js/dark-mode.js" nonce="<?= CSP_NONCE ?>" defer></script>
    <script src="assets/js/login.js" nonce="<?= CSP_NONCE ?>" defer></script>
    <style>
        @keyframes loginIn { from { opacity: 0; transform: translateY(16px) scale(0.985); } to { opacity: 1; transform: translateY(0) scale(1); } }
        @keyframes floatOrb { 0%, 100% { transform: translate(0, 0) scale(1); } 33% { transform: translate(20px, -30px) scale(1.05); } 66% { transform: translate(-15px, 20px) scale(0.95); } }
        @keyframes shimmer { 0% { background-position: -200% center; } 100% { background-position: 200% center; } }
        .anim-login { animation: loginIn 0.7s cubic-bezier(0.22, 1, 0.36, 1) both; }
        .anim-delay-1 { animation-delay: 0.1s; }
        .anim-delay-2 { animation-delay: 0.2s; }
        .anim-delay-3 { animation-delay: 0.3s; }
        .glass-pro {
            background: rgba(255, 255, 255, 0.82);
            backdrop-filter: blur(28px) saturate(1.2);
            -webkit-backdrop-filter: blur(28px) saturate(1.2);
            border: 1px solid rgba(226, 232, 240, 0.45);
            box-shadow:
                0 0 0 1px rgba(255,255,255,0.05) inset,
                0 40px 100px -20px rgba(15, 23, 42, 0.12),
                0 8px 30px rgba(99, 102, 241, 0.08);
        }
        .dark .glass-pro {
            background: rgba(30, 41, 59, 0.75);
            border-color: rgba(71, 85, 105, 0.35);
            box-shadow:
                0 0 0 1px rgba(255,255,255,0.02) inset,
                0 40px 100px -20px rgba(0, 0, 0, 0.5),
                0 8px 30px rgba(129, 140, 248, 0.1);
        }
        .btn-pro {
            position: relative; overflow: hidden;
            background: linear-gradient(135deg, #4f46e5 0%, #3730a3 60%, #312e81 100%);
            box-shadow: 0 10px 30px rgba(79, 70, 229, 0.25), 0 2px 8px rgba(79, 70, 229, 0.15);
        }
        .btn-pro:hover {
            background: linear-gradient(135deg, #4338ca 0%, #312e81 100%);
            box-shadow: 0 16px 40px rgba(79, 70, 229, 0.35), 0 4px 12px rgba(79, 70, 229, 0.2);
            transform: translateY(-2px);
        }
        .btn-pro:active { transform: translateY(0); }
        .btn-pro::after {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(120deg, transparent 30%, rgba(255,255,255,0.18) 50%, transparent 70%);
            animation: shimmer 2.5s ease infinite;
            background-size: 200% 200%;
        }
        .field-pro:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
        }
        @media (prefers-reduced-motion: reduce) { .anim-login, .anim-delay-1, .anim-delay-2, .anim-delay-3 { animation: none; } }
    </style>
</head>

<body class="min-h-screen bg-gradient-to-br from-slate-950 via-[#161a2e] to-indigo-950 text-slate-100 relative overflow-hidden selection:bg-indigo-400/40">

    <!-- Animierter Hintergrund: große, langsam bewegte Orbs -->
    <div class="fixed inset-0 pointer-events-none z-0 overflow-hidden" aria-hidden="true">
        <div class="absolute top-[-15%] left-[-10%] w-[70vw] h-[70vw] rounded-full bg-gradient-to-br from-indigo-700/40 via-violet-700/30 to-transparent opacity-80 blur-3xl" style="animation: floatOrb 18s ease-in-out infinite;"></div>
        <div class="absolute bottom-[-20%] right-[-15%] w-[80vw] h-[80vw] rounded-full bg-gradient-to-tl from-emerald-700/25 via-indigo-800/20 to-transparent opacity-70 blur-3xl" style="animation: floatOrb 22s ease-in-out infinite reverse;"></div>
        <div class="absolute top-[30%] right-[20%] w-[40vw] h-[40vw] rounded-full bg-gradient-to-r from-violet-600/20 to-indigo-500/15 opacity-60 blur-2xl" style="animation: floatOrb 15s ease-in-out infinite 4s;"></div>
        <!-- Subtile Punkt-Matrix -->
        <svg class="absolute inset-0 w-full h-full opacity-[0.035]" xmlns="http://www.w3.org/2000/svg">
            <defs><pattern id="dots" width="32" height="32" patternUnits="userSpaceOnUse"><circle cx="1" cy="1" r="0.8" fill="#818cf8"/></pattern></defs>
            <rect width="100%" height="100%" fill="url(#dots)"/>
        </svg>
    </div>

    <!-- Hauptinhalt -->
    <main class="relative z-10 min-h-screen flex items-center justify-center px-5 sm:px-8 lg:px-12">
        <div class="w-full max-w-[440px] anim-login">

            <!-- Branding Header -->
            <div class="text-center mb-8 anim-delay-1">
                <a href="#" class="inline-flex items-center gap-3 group" aria-label="PsychKG Bereitschaftsplaner">
                    <span class="flex items-center justify-center w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 via-violet-600 to-indigo-700 text-white shadow-[0_8px_30px_rgba(79,70,229,0.35)] group-hover:shadow-[0_12px_40px_rgba(79,70,229,0.45)] transition-all duration-300 group-hover:-translate-y-0.5">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </span>
                    <span class="text-2xl font-extrabold tracking-tight text-white leading-none">
                        Psych<span class="text-indigo-300">KG</span>
                    </span>
                </a>
                <h1 class="text-3xl font-extrabold text-white mt-5 tracking-tight leading-[1.15]">
                    <?= htmlspecialchars(Translator::t('login.heading')) ?>
                    <span class="block text-indigo-200/90 text-[0.95em] font-bold"><?= htmlspecialchars(Translator::t('login.subheading')) ?></span>
                </h1>
                <p class="text-slate-400 text-sm mt-3 max-w-xs mx-auto"><?= htmlspecialchars(Translator::t('login.subtitle')) ?></p>
            </div>

            <!-- Login Card -->
            <article class="glass-pro rounded-[28px] p-7 sm:p-9 anim-delay-2">
                <header class="mb-6">
                    <h2 class="text-center text-lg font-extrabold text-slate-900 tracking-tight"><?= htmlspecialchars(Translator::t('login.card_title')) ?></h2>
                    <p class="text-center text-xs text-slate-400 mt-1"><?= htmlspecialchars(Translator::t('login.card_subtitle')) ?></p>
                </header>

                <?php if ($error): ?>
                    <div class="mb-5 p-4 rounded-2xl bg-rose-50/90 border border-rose-200/80 text-rose-700 text-sm flex items-start gap-2.5 shadow-[inset_0_1px_2px_rgba(244,63,94,0.05)]" role="alert" aria-live="assertive">
                        <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-rose-500" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01"/></svg>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($infoMessage): ?>
                    <div class="mb-5 p-4 rounded-2xl bg-indigo-50/90 border border-indigo-200/80 text-indigo-700 text-sm flex items-start gap-2.5 shadow-[inset_0_1px_2px_rgba(99,102,241,0.05)]" role="status" aria-live="polite">
                        <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-indigo-500" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?= htmlspecialchars($infoMessage) ?></span>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="POST" class="space-y-4" novalidate>
                    <?= Csrf::field() ?>

                    <div>
                        <label for="username" class="block text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500 mb-2"><?= htmlspecialchars(Translator::t('login.username')) ?></label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400 pointer-events-none" aria-hidden="true">
                                <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            </span>
                            <input type="text" name="username" id="username" required autofocus autocomplete="username"
                                class="field-pro w-full pl-11 pr-4 py-3.5 bg-slate-50/80 border border-slate-200/80 rounded-2xl text-base text-slate-800 placeholder:text-slate-400 focus:border-indigo-400 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 transition-all shadow-[inset_0_1px_3px_rgba(15,23,42,0.03)]"
                                placeholder="<?= htmlspecialchars(Translator::t('login.username_placeholder')) ?>" />
                        </div>
                    </div>

                    <div>
                        <label for="password" class="block text-xs font-extrabold uppercase tracking-[0.08em] text-slate-500 mb-2"><?= htmlspecialchars(Translator::t('login.password')) ?></label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400 pointer-events-none" aria-hidden="true">
                                <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            </span>
                            <input type="password" name="password" id="password" required autocomplete="current-password"
                                class="field-pro w-full pl-11 pr-11 py-3.5 bg-slate-50/80 border border-slate-200/80 rounded-2xl text-base text-slate-800 placeholder:text-slate-400 focus:border-indigo-400 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 transition-all shadow-[inset_0_1px_3px_rgba(15,23,42,0.03)]"
                                placeholder="<?= htmlspecialchars(Translator::t('login.password_placeholder')) ?>" />
                            <button type="button" id="pw-toggle" aria-label="<?= htmlspecialchars(Translator::t('login.show_password')) ?>"
                                class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-indigo-500 transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-pro w-full py-3.5 rounded-2xl text-white font-extrabold text-base tracking-tight shadow-lg transition-all duration-200 active:scale-[0.99] mt-2">
                        <?= htmlspecialchars(Translator::t('login.submit')) ?>
                    </button>
                </form>

                <?php if (Config::SHOW_DEMO_CREDENTIALS): ?>
                    <div class="mt-8 pt-6 border-t border-slate-200/60 dark:border-slate-700/40">
                        <span class="block text-center text-xs font-extrabold uppercase tracking-[0.15em] text-slate-400 mb-4"><?= htmlspecialchars(Translator::t('login.demo_heading')) ?></span>
                        <div class="flex flex-wrap justify-center gap-2.5 text-xs">
                            <button type="button" data-demo-username="admin"
                                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-indigo-200/60 bg-indigo-50/50 hover:bg-indigo-100 transition-all shadow-sm hover:shadow-md hover:-translate-y-0.5 active:translate-y-0">
                                <span class="h-7 w-7 rounded-full bg-gradient-to-br from-indigo-500 to-violet-600 text-white text-xs font-extrabold flex items-center justify-center shadow-sm">A</span>
                                <span class="font-bold text-indigo-700"><?= htmlspecialchars(Translator::t('login.demo_admin')) ?></span>
                            </button>
                            <button type="button" data-demo-username="m.schmidt"
                                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-emerald-200/60 bg-emerald-50/50 hover:bg-emerald-100 transition-all shadow-sm hover:shadow-md hover:-translate-y-0.5 active:translate-y-0">
                                <span class="h-7 w-7 rounded-full bg-gradient-to-br from-emerald-500 to-emerald-700 text-white text-xs font-extrabold flex items-center justify-center shadow-sm">M</span>
                                <span class="font-bold text-emerald-700"><?= htmlspecialchars(Translator::t('login.demo_employee')) ?></span>
                            </button>
                        </div>
                        <p class="text-xs text-slate-400 text-center mt-3"><?= htmlspecialchars(Translator::t('login.demo_note')) ?></p>
                    </div>
                <?php endif; ?>
            </article>

            <!-- Trust Footer -->
            <div class="mt-7 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-xs text-slate-400/80 font-medium anim-delay-3">
                <span class="inline-flex items-center gap-1.5"><svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg> 256-bit TLS</span>
                <span class="inline-flex items-center gap-1.5"><svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> DSGVO-konform</span>
                <span class="inline-flex items-center gap-1.5"><svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Daten in Deutschland</span>
            </div>

            <p class="text-center text-xs text-slate-500/60 mt-5 font-medium anim-delay-3"><?= htmlspecialchars(Translator::t('login.version')) ?></p>
        </div>
    </main>
</body>
</html>
