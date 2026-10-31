<?php
/**
 * Gemeinsamer Print-Layout-Block für die PDF-Export-Seiten.
 * Erwartet: $printTitle (string, optional), $printCss (string, optional).
 */
$printTitle = $printTitle ?? 'PsychKG Bereitschaftsplaner – Druckansicht';
$printCss = $printCss ?? '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($printTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/app.css">
    <?= $printCss ?>
</head>
<body class="bg-white text-slate-900">
