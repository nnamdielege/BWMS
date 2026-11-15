<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approving a PO. The override and its reason only matter when it's over budget; the service
 * decides that. Authorization is done by route `can:` middleware.
 */
class ApprovePurchaseOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'override' => ['boolean'],
            'override_reason' => ['nullable', 'required_if_accepted:override', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['override_reason.required_if_accepted' => 'Give a reason for approving over budget.'];
    }
}
