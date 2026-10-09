<?php
/**
 * Presentation Layer - app entry point.
 * Thin redirect so /Diwan lands on the public search page. No logic here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

redirect(url('public/search.php'));