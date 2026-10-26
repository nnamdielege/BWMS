<?php

namespace App\Migration\Legacy;

use RuntimeException;

/** Invalid opening balance rows: the whole run is rolled back and nothing is posted. */
class OpeningBalanceRejected extends RuntimeException {}
