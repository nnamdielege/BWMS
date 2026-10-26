<x-mail::message>
# Purchase order {{ $po->number }}

Hello {{ $po->supplier->contact_name ?: $po->supplier->name }},

@if ($messageText)
{{ $messageText }}

@endif
Please find purchase order **{{ $po->number }}** attached
@if ($po->expected_date) (required by {{ $po->expected_date->format('d/m/Y') }})@endif.

Total (inc. GST): **${{ number_format((float) $po->total, 2) }}**

Please quote the PO number on your delivery docket and invoice.

Thanks,<br>
{{ $companyName }}
</x-mail::message>
