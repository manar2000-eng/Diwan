<?php
/**
 * Presentation Layer - hub_admin landing page.
 * The MVP work starts at branches.php; this only resolves /dashboard/hub/.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/layout.php';

requireRole(['hub_admin']);

redirect(url('dashboard/hub/branches.php'));