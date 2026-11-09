<?php
/**
 * Fehler beim Datenbankzugriff.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Database;

final class DbException extends \RuntimeException {}
