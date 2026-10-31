<?php
namespace Src\Core;

/**
 * Shared CSV cell sanitising.
 *
 * Neutralises spreadsheet formula injection (OWASP): cells starting with
 * = + - @ (or tab/CR) are prefixed with a single quote so Excel treats them
 * as text. Applied to all user-controlled text cells before fputcsv().
 */
final class Csv
{
    public static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
