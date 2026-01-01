<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Purchase Order {{ $order->order_number }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
        }
        h1 {
            text-align: center;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            border: 1px solid #999;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f0f0f0;
        }
    </style>
</head>
<body>
    <h1>PURCHASE ORDER</h1>
    
    <table>
        <tr>
            <td><strong>Order Number:</strong></td>
            <td>{{ $order->order_number }}</td>
        </tr>
        <tr>
            <td><strong>Order Date:</strong></td>
            <td>{{ $order->order_date->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td><strong>Expected Date:</strong></td>
            <td>{{ $order->expected_date ? $order->expected_date->format('d/m/Y') : 'N/A' }}</td>
        </tr>
        <tr>
            <td><strong>Status:</strong></td>
            <td>{{ ucfirst($order->status) }}</td>
        </tr>
    </table>

    <h2>Supplier Information</h2>
    <table>
        <tr>
            <td><strong>Company:</strong></td>
            <td>{{ $order->supplier->company_name ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td><strong>Contact:</strong></td>
            <td>{{ $order->supplier->contact_person ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td><strong>Address:</strong></td>
            <td>{{ $order->supplier->address ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td><strong>City/State/Zip:</strong></td>
            <td>{{ $order->supplier->city ?? '' }} {{ $order->supplier->state ?? '' }} {{ $order->supplier->postal_code ?? '' }}</td>
        </tr>
        <tr>
            <td><strong>Phone:</strong></td>
            <td>{{ $order->supplier->phone ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td><strong>Email:</strong></td>
            <td>{{ $order->supplier->email ?? 'N/A' }}</td>
        </tr>
    </table>

    <h2>Warehouse Information</h2>
    <table>
        <tr>
            <td><strong>Name:</strong></td>
            <td>{{ $order->warehouse->name ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td><strong>Address:</strong></td>
            <td>{{ $order->warehouse->address ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td><strong>City/State/Zip:</strong></td>
            <td>{{ $order->warehouse->city ?? '' }} {{ $order->warehouse->state ?? '' }} {{ $order->warehouse->postal_code ?? '' }}</td>
        </tr>
        <tr>
            <td><strong>Phone:</strong></td>
            <td>{{ $order->warehouse->phone ?? 'N/A' }}</td>
        </tr>
    </table>

    <h2>Order Items</h2>
    <table>
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

    <h2>Order Summary</h2>
    <table style="width: 400px; margin-left: auto;">
        <tr>
            <td><strong>Subtotal:</strong></td>
            <td>${{ number_format($order->subtotal, 2) }}</td>
        </tr>
        @if($order->tax)
            <tr>
                <td><strong>Tax:</strong></td>
                <td>${{ number_format($order->tax, 2) }}</td>
            </tr>
        @endif
        @if($order->shipping)
            <tr>
                <td><strong>Shipping:</strong></td>
                <td>${{ number_format($order->shipping, 2) }}</td>
            </tr>
        @endif
        @if($order->discount)
            <tr>
                <td><strong>Discount:</strong></td>
                <td>-${{ number_format($order->discount, 2) }}</td>
            </tr>
        @endif
        <tr style="border-top: 2px solid #000;">
            <td><strong>TOTAL:</strong></td>
            <td><strong>${{ number_format($order->total, 2) }}</strong></td>
        </tr>
    </table>

    @if($order->notes)
        <h2>Notes</h2>
        <p>{{ $order->notes }}</p>
    @endif

    <hr>
    <p style="font-size: 10px; text-align: center;">Generated on {{ now()->format('d/m/Y H:i') }}</p>
</body>
</html>