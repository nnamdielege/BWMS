<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Purchase Order {{ $order->order_number }}</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Source+Sans+3:wght@400;600&display=swap');

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Source Sans 3', Arial, sans-serif;
            color: #2c2c2c;
            font-size: 11px;
            line-height: 1.5;
            padding: 30px 40px;
            background: #fff;
        }

        /* ── HEADER ── */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #2c2c2c;
            padding-bottom: 18px;
            margin-bottom: 22px;
        }
        .header-logo {
            width: 90px;
            height: 90px;
            object-fit: contain;
        }
        .header-company {
            text-align: right;
        }
        .header-company h1 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 26px;
            letter-spacing: 1px;
            color: #1a1a1a;
            margin-bottom: 4px;
        }
        .header-company p {
            font-size: 10px;
            color: #666;
            line-height: 1.6;
        }
        .po-title {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 22px;
            font-weight: 600;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #1a1a1a;
            text-align: center;
            margin-bottom: 22px;
        }

        /* ── SECTION LABELS ── */
        h2 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #1a1a1a;
            border-bottom: 1px solid #2c2c2c;
            padding-bottom: 4px;
            margin: 18px 0 8px;
        }

        /* ── TABLES ── */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .info-table td {
            padding: 5px 8px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        .info-table td:first-child {
            width: 160px;
            font-weight: 600;
            background: #f7f7f5;
            color: #444;
        }

        /* Two-column info layout */
        .two-col {
            display: flex;
            gap: 20px;
        }
        .two-col > div {
            flex: 1;
        }

        /* Items table */
        .items-table thead tr {
            background: #2c2c2c;
            color: #fff;
        }
        .items-table th {
            padding: 7px 10px;
            font-weight: 600;
            letter-spacing: 0.5px;
            font-size: 10px;
            text-transform: uppercase;
        }
        .items-table td {
            padding: 6px 10px;
            border-bottom: 1px solid #eee;
        }
        .items-table tbody tr:nth-child(even) td {
            background: #fafaf8;
        }
        .items-table td:nth-child(2),
        .items-table td:nth-child(3),
        .items-table td:nth-child(4),
        .items-table td:nth-child(5) {
            text-align: right;
        }
        .items-table th:nth-child(2),
        .items-table th:nth-child(3),
        .items-table th:nth-child(4),
        .items-table th:nth-child(5) {
            text-align: right;
        }

        /* Summary table */
        .summary-table {
            width: 320px;
            margin-left: auto;
            margin-top: 10px;
        }
        .summary-table td {
            padding: 5px 10px;
            border-bottom: 1px solid #eee;
        }
        .summary-table td:last-child {
            text-align: right;
        }
        .summary-table .total-row td {
            border-top: 2px solid #2c2c2c;
            border-bottom: none;
            font-weight: 600;
            font-size: 13px;
            padding-top: 8px;
        }

        /* Notes */
        .notes-box {
            background: #f7f7f5;
            border-left: 3px solid #2c2c2c;
            padding: 10px 14px;
            font-size: 10.5px;
            color: #444;
            margin-top: 8px;
        }

        /* Footer */
        .footer {
            margin-top: 30px;
            border-top: 1px solid #ddd;
            padding-top: 10px;
            text-align: center;
            font-size: 9px;
            color: #999;
        }
    </style>
</head>
<body>

    <!-- HEADER -->
    <div class="header">
        <img class="header-logo" src="{{ public_path('storage/logo.webp') }}" alt="Logo">
        <div class="header-company">
            <h1>{{ $order->warehouse->name ?? 'Company Name' }}</h1>
            <p>
                {{ $order->warehouse->address ?? 'N/A' }}<br>
                {{ $company->email ?? '' }}<br>
                {{ $company->phone ?? '' }}<br>
                ABN: {{ $company->abn ?? '83 642 565 044' }}<br>
                ACN: {{ $company->acn ?? '642 565 044' }}
            </p>
        </div>
    </div>

    <div class="po-title">Purchase Order</div>

    <!-- TWO COLUMN INFO -->
    <div class="two-col">
        <div>
            <h2>Supplier</h2>
            <table class="info-table">
                <tr><td>Company Name</td><td>{{ $order->supplier->company_name ?? 'N/A' }}</td></tr>
                <tr><td>Contact</td><td>{{ $order->supplier->contact_name ?? 'N/A' }}</td></tr>
                <tr><td>Address</td><td>{{ $order->supplier->address ?? 'N/A' }}</td></tr>
                <tr><td>Email</td><td>{{ $order->supplier->email ?? 'N/A' }}</td></tr>
            </table>
        </div>

        <div>
            <h2>Order Info</h2>
            <table class="info-table">
                <tr><td>PO Number</td><td>{{ $order->order_number }}</td></tr>
                <tr><td>Date</td><td>{{ $order->order_date }}</td></tr>
                <tr><td>Delivery Date</td><td>{{ $order->expected_date }}</td></tr>
            </table>
        </div>
    </div>

    <!-- ITEMS -->
    <h2>Items</h2>
    <table class="items-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>Quantity</th>
                <th>Unit Price</th>
                <th>Discount</th>
                <th>Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
            <tr>
                <td>{{ $item->product->name ?? 'N/A' }}</td>
                <td>{{ $item->quantity }}</td>
                <td>${{ number_format($item->unit_price, 2) }}</td>
                <td>${{ number_format($item->discount ?? 0, 2) }}</td>
                <td>${{ number_format($item->subtotal, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- SUMMARY -->
    <table class="summary-table">
        <tr><td>Subtotal</td><td>{{ number_format($order->subtotal, 2) }}</td></tr>
        @if($order->tax)
            <tr><td>GST</td><td>{{ number_format($order->tax, 2) }}</td></tr>
        @endif
        @if($order->shipping)
            <tr><td>Shipping:</td><td>{{ number_format($order->shipping, 2) }}</td></tr>            
        @endif
        @if($order->discount)
            <tr><td>Discount:</td><td>{{ number_format($order->discount, 2) }}</td></tr>            
        @endif
        <tr class="total-row"><td>Total</td><td>{{ number_format($order->total, 2) }}</td></tr>
    </table>

    <!-- NOTES -->
    @if($order->notes)
    <h2>Notes</h2>
    <div class="notes-box">
        {{ $order->notes }}
    </div>
    @endif

    <!-- FOOTER -->
    <div class="footer">
        Thank you for your business
    </div>

</body>
</html>