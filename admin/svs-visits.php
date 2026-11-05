<?php

declare(strict_types=1);

// Legacy URL — visitor tracking is now site-wide.
header('Location: visits.php', true, 302);
exit;
