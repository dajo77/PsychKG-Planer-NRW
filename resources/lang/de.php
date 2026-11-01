<?php
declare(strict_types=1);

/**
 * Deutsche UI-Texte.
 *
 * Hinweis: Die Extraktion ist bewusst schrittweise (Vorbereitung). Neue Texte
 * sollten hier ergänzt und im Template über Translator::t('key') bezogen
 * werden, statt erneut hartkodiert zu werden.
 */
return [
    // Allgemein
    'app.name' => 'PsychKG Bereitschaftsplaner',
    'app.tagline' => 'Dienstplanungs- und Dokumentationssystem für Ordnungsbehörden NRW',
    'common.back_to_login' => 'Zur Anmeldung',

    // Login
    'login.title' => 'PsychKG Bereitschaftsplaner — Anmelden',
    'login.heading' => 'Bereitschaftsdienst',
    'login.subheading' => 'für Ordnungsbehörden NRW',
    'login.subtitle' => 'Professionelles Dienstplanungs- und Dokumentationssystem.',
    'login.card_title' => 'Anmelden',
    'login.card_subtitle' => 'Mit Ihrem Dienstkonto',
    'login.username' => 'Benutzername',
    'login.password' => 'Kennwort',
    'login.submit' => 'Anmelden',
    'login.username_placeholder' => 'z. B. m.schmidt',
    'login.password_placeholder' => 'Ihr Kennwort',
    'login.show_password' => 'Passwort anzeigen',
    'login.demo_heading' => 'Demo-Zugänge',
    'login.demo_admin' => 'Administrator',
    'login.demo_employee' => 'Mitarbeiter',
    'login.demo_note' => 'Passwörter wurden bei der Installation zufällig generiert.',
    'login.version' => 'Dienstplanungssoftware PsychKG NRW · Version 1.0.0',
    'login.info.logged_out' => 'Sie wurden erfolgreich abgemeldet.',
    'login.info.session_invalid' => 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.',
    'login.error.empty' => 'Bitte füllen Sie alle Felder aus.',
    'login.error.locked' => 'Zu viele Login-Versuche. Bitte warten Sie einige Minuten.',
    'login.error.invalid' => 'Ungültiger Benutzername oder falsches Passwort.',

    // Dashboard
    'dashboard.title' => 'PsychKG Bereitschaftsplaner - Dashboard',
    'dashboard.heading' => 'Dashboard',
    'dashboard.error.unauthorized' => 'Sie haben keine Berechtigung für diesen Bereich.',
    'dashboard.ok.trade_offered' => 'Der Dienst wurde erfolgreich auf dem Marktplatz zum Tausch angeboten.',
    'dashboard.ok.pw_changed' => 'Passwort erfolgreich geändert.',
    'dashboard.err.trade_failed' => 'Fehler beim Anbieten des Dienstes. Ggf. ist er bereits offen oder nicht Ihnen zugewiesen.',
    'dashboard.err.pw_mismatch' => 'Die neuen Passwörter stimmen nicht überein.',
    'dashboard.err.pw_weak' => 'Passwort entspricht nicht den Anforderungen (min. 12 Zeichen, Großbuchstabe, Ziffer, Sonderzeichen).',
    'dashboard.err.pw_wrong' => 'Das aktuelle Passwort ist falsch.',
    'dashboard.password.heading' => 'Mein Konto',
    'dashboard.password.current' => 'Aktuelles Passwort',
    'dashboard.password.new' => 'Neues Passwort (min. 12 Zeichen)',
    'dashboard.password.confirm' => 'Neues Passwort bestätigen',
    'dashboard.password.policy' => 'Mindestens 12 Zeichen, ein Großbuchstabe, eine Ziffer und ein Sonderzeichen.',
    'dashboard.password.submit' => 'Passwort ändern',
    'dashboard.access_denied' => 'Zugriff verweigert.',
];
