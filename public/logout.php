<?php
/**
 * Presentation Layer - session teardown, then POST-redirect-GET to login.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

logoutUser();

redirect(url('public/login.php'));