<?php
/**
 * Server-local configuration (NOT in git – see .gitignore).
 *
 * Copy this file to `config.local.php` (same directory) and adjust the path.
 * Purpose: keep the JSON data files OUTSIDE the document root so they can
 * never be fetched via HTTP (nginx ignores data/.htaccess and web.config).
 *
 * Contains no secrets – only a filesystem path. The file must stay PHP
 * (not .json/.txt) so a direct request executes it (blank page) instead
 * of disclosing its content.
 */
return [
    // Absolute path, writable by the PHP process user.
    'data_dir' => '/var/www/vhosts/dario-jozic.de/private/psychkg-data',
];
