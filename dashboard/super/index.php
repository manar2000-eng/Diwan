<?php
/**
 * Presentation Layer - super_admin landing page.
 * Smallest change: the queue lives in hubs.php, this only resolves /dashboard/super/.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/layout.php';

requireRole(['super_admin']);

redirect(url('dashboard/super/hubs.php'));