<?php
/**
 * Gemeinsamer <head>-Block (CSP-freundlich: nur self, keine Inline-Styles).
 * Erwartet: $pageTitle (string, optional), $extraHead (string, optional).
 */
$pageTitle = $pageTitle ?? 'PsychKG Bereitschaftsplaner';
$extraHead = $extraHead ?? '';
?>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="manifest" href="manifest.json">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>P</text></svg>">
    <link rel="stylesheet" href="assets/fonts.css">
    <meta name="theme-color" content="#4f46e5">
    <?= $extraHead ?>
</head>
