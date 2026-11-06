<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A carton count entered on the pack screen, until Packed turns it into an OrderPack. */
class OrderPackDraft extends Model
{
    protected $guarded = ['id'];
}
