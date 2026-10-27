<?php

namespace App\Integrations\Portal;

use RuntimeException;

/** Connection failure or 5xx from the portal: the job retries. */
class PortalUnavailable extends RuntimeException {}
