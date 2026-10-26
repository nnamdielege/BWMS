<?php

namespace App\Services\Stock\Exceptions;

/** The idempotency key was already used for a different movement. */
class IdempotencyConflict extends StockException {}
