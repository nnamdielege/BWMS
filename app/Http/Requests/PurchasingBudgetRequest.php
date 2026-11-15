<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/** One week's purchasing budget. Authorization is done by route `can:` middleware. */
class PurchasingBudgetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'week_start' => ['required', 'date_format:Y-m-d'],
            'budget' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $v) {
                $date = $this->input('week_start');
                if (! $v->errors()->has('week_start') && Carbon::parse($date)->dayOfWeekIso !== 1) {
                    $v->errors()->add('week_start', 'A budget week starts on a Monday.');
                }
            },
        ];
    }
}
