@php
use App\Helpers\ArabicHelper;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Bon de Commande - {{ $order->reference ?? 'N/A' }}</title>
    <style>
        * {
            font-family: 'DejaVu Sans', Arial, sans-serif;
        }
        body {
            font-size: 12px;
            line-height: 1.6;
            color: #333;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header h1 {
            font-size: 24px;
            margin: 0;
            color: #1a56db;
        }
        .header p {
            margin: 5px 0;
            color: #666;
        }
        .info-section {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        .info-box {
            display: table-cell;
            width: 48%;
            vertical-align: top;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .info-box h3 {
            margin: 0 0 10px 0;
            padding-bottom: 5px;
            border-bottom: 1px solid #eee;
            color: #1a56db;
            font-size: 14px;
        }
        .info-box p {
            margin: 3px 0;
        }
        .info-label {
            color: #666;
            font-size: 11px;
        }
        .reference-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        .reference-box .ref {
            font-size: 20px;
            font-weight: bold;
            color: #1a56db;
        }
        .reference-box .date {
            color: #666;
            font-size: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th {
            background: #1a56db;
            color: white;
            padding: 10px 8px;
            text-align: left;
            font-weight: bold;
        }
        td {
            padding: 10px 8px;
            border-bottom: 1px solid #eee;
        }
        tr:nth-child(even) {
            background: #f9fafb;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .totals {
            width: 300px;
            margin-left: auto;
            margin-right: 0;
        }
        .totals td {
            padding: 8px;
        }
        .totals .label {
            text-align: left;
            color: #666;
        }
        .totals .value {
            text-align: right;
            font-weight: bold;
        }
        .totals .grand-total {
            background: #1a56db;
            color: white;
        }
        .totals .grand-total td {
            font-size: 16px;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        .signatures {
            display: table;
            width: 100%;
            margin-top: 30px;
        }
        .signature-box {
            display: table-cell;
            width: 45%;
            text-align: center;
            padding: 20px;
        }
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 50px;
            padding-top: 5px;
        }
        .notes {
            background: #fffbeb;
            border: 1px solid #fbbf24;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .notes h4 {
            margin: 0 0 5px 0;
            color: #92400e;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: bold;
        }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-confirmed { background: #dbeafe; color: #1e40af; }
        .status-delivered { background: #d1fae5; color: #065f46; }
        .barcode {
            text-align: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        @if(!empty($settings['company_logo']) && extension_loaded('gd'))
        @php
            $logoPath = storage_path('app/public/' . $settings['company_logo']);
        @endphp
        @if(file_exists($logoPath))
        <div style="margin-bottom: 10px;">
            <img src="{{ $logoPath }}" style="max-height: 60px; max-width: 150px;" alt="Logo">
        </div>
        @endif
        @endif
        <h1>{{ $settings['company_name'] ?? config('app.name', 'Rafik Biskra') }}</h1>
        <p>{{ $settings['company_address'] ?? 'Biskra, Algerie' }}</p>
        <p>Tel: {{ $settings['company_phone'] ?? '' }}</p>
    </div>

    <div class="reference-box">
        <div class="ref">Bon de Commande: {{ $order->reference ?? 'N/A' }}</div>
        <div class="date">Date: {{ $order->date ? \Carbon\Carbon::parse($order->date)->format('d/m/Y') : '-' }}</div>
        @php
            $orderStatus = $order->status ?? 'pending';
        @endphp
        <span class="status-badge status-{{ $orderStatus }}">
            @switch($orderStatus)
                @case('pending') En attente @break
                @case('confirmed') Confirme @break
                @case('assigned') Assigne @break
                @case('delivered') Livre @break
                @case('partial') Livraison partielle @break
                @case('cancelled') Annule @break
                @default {{ $orderStatus }}
            @endswitch
        </span>
    </div>

    <div class="info-section">
        <div class="info-box" style="margin-right: 2%;">
            <h3>Information Client</h3>
            <p><strong>{{ ArabicHelper::safe($order->client->name ?? null, '-') }}</strong></p>
            <p><span class="info-label">Tel:</span> {{ $order->client->phone ?? '-' }}</p>
            <p><span class="info-label">Adresse:</span> {{ ArabicHelper::safe($order->client->address ?? null, '-') }}</p>
        </div>
        <div class="info-box" style="margin-left: 2%;">
            <h3>Information Commande</h3>
            <p><span class="info-label">Entrepot:</span> {{ $order->warehouse->name ?? '-' }}</p>
            <p><span class="info-label">Vendeur:</span> {{ $order->seller->name ?? '-' }}</p>
            @if($order->trip)
            <p><span class="info-label">Tournee:</span> {{ $order->trip->reference ?? '-' }}</p>
            @endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 30%;">Produit</th>
                <th style="width: 12%;" class="text-center">Unite (Pcs)</th>
                <th style="width: 10%;" class="text-center">Qte</th>
                <th style="width: 13%;" class="text-center">Prix/Pce</th>
                <th style="width: 10%;" class="text-center">Remise</th>
                <th style="width: 20%;" class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items ?? [] as $index => $item)
            @php
                $product = $item->product ?? null;
                $productName = $product->name ?? 'Produit supprime';
                $productBarcode = $product->barcode ?? null;
                $piecesPerPkg = $product->pieces_per_package ?? 1;
                $unitShortName = $product->unitSale->short_name ?? 'U';
                $qty = $item->quantity_confirmed ?? $item->quantity_ordered ?? 0;
                $unitPrice = $item->unit_price ?? 0;
                $itemDiscount = $item->discount ?? 0;
                // Use stored subtotal (includes pieces_per_package in calculation)
                $subtotal = $item->subtotal ?? (($unitPrice * $piecesPerPkg * $qty) - $itemDiscount);
            @endphp
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td>
                    <strong>{{ ArabicHelper::safe($productName, 'Produit') }}</strong>
                    @if($productBarcode)
                    <br><small style="color: #666;">{{ $productBarcode }}</small>
                    @endif
                </td>
                <td class="text-center">
                    {{ $unitShortName }}
                    @if($piecesPerPkg > 1)
                    <br><small style="color: #666;">({{ $piecesPerPkg }} pcs)</small>
                    @endif
                </td>
                <td class="text-center">{{ number_format($qty, $qty == floor($qty) ? 0 : 2) }}</td>
                <td class="text-center">{{ number_format($unitPrice, 2) }}</td>
                <td class="text-center">{{ number_format($itemDiscount, 2) }}</td>
                <td class="text-right">{{ number_format($subtotal, 2) }} DA</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @php
        $totalAmount = $order->total_amount ?? 0;
        $orderDiscount = $order->discount ?? 0;
        $orderTax = $order->tax ?? 0;
        $grandTotal = $order->grand_total ?? ($totalAmount - $orderDiscount + $orderTax);
    @endphp

    <table class="totals">
        <tr>
            <td class="label">Sous-total:</td>
            <td class="value">{{ number_format($totalAmount, 2) }} DA</td>
        </tr>
        @if($orderDiscount > 0)
        <tr>
            <td class="label">Remise:</td>
            <td class="value" style="color: #dc2626;">- {{ number_format($orderDiscount, 2) }} DA</td>
        </tr>
        @endif
        @if($orderTax > 0)
        <tr>
            <td class="label">TVA:</td>
            <td class="value">{{ number_format($orderTax, 2) }} DA</td>
        </tr>
        @endif
        <tr class="grand-total">
            <td class="label">Total:</td>
            <td class="value">{{ number_format($grandTotal, 2) }} DA</td>
        </tr>
    </table>

    @if(!empty($order->notes))
    <div class="notes">
        <h4>Notes:</h4>
        <p>{{ ArabicHelper::safe($order->notes, '') }}</p>
    </div>
    @endif

    <div class="footer">
        <div class="signatures">
            <div class="signature-box">
                <div class="signature-line">Signature Client</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">Signature Livreur</div>
            </div>
        </div>
    </div>

    <div class="barcode">
        <p style="font-family: monospace; font-size: 14px; letter-spacing: 3px;">{{ $order->reference ?? '' }}</p>
    </div>

    <p style="text-align: center; color: #999; font-size: 10px; margin-top: 20px;">
        Document genere le {{ now()->format('d/m/Y H:i') }}
    </p>
</body>
</html>
