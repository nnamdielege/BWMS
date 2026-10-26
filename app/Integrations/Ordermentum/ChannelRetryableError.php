<?php

namespace App\Integrations\Ordermentum;

use RuntimeException;

/** A channel call failed in a way worth retrying (5xx). */
class ChannelRetryableError extends RuntimeException {}
