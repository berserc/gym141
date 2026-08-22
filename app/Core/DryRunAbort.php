<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Wird beim Probelauf des Imports geworfen, damit die Transaktion
 * zurueckgerollt wird, die gesammelte Zusammenfassung aber erhalten bleibt.
 */
final class DryRunAbort extends RuntimeException
{
}
