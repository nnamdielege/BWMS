<?php

namespace App\Integrations\Ordermentum;

use RuntimeException;

/** The order JSON does not match the configured mapping; fix config, then replay. */
class UnmappableOrder extends RuntimeException {}
