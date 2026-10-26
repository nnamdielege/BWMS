<?php

namespace App\Services\Stock\Exceptions;

use RuntimeException;

/** Base class for ledger rule violations; messages are safe to show to users. */
class StockException extends RuntimeException {}
