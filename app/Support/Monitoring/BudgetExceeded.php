<?php

namespace App\Support\Monitoring;

use RuntimeException;

/**
 * Internal stop signal of FolderSizer (entry or time budget hit, or the run
 * was superseded). Carries no message, so no path can leak through it.
 */
final class BudgetExceeded extends RuntimeException {}
