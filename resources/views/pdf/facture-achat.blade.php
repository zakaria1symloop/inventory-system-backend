@php
use App\Helpers\ArabicHelper;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Bon d'Achat - {{ $purchase->reference ?? 'N/A' }}</title>
    <style>
        * {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-size: 9px;
            line-height: 1.3;
            color: #000;
            padding: 10px 15px;
        }
        table { border-collapse: collapse; width: 100%; }
        .header-table td { vertical-align: top; padding: 0; }
        .company-box {
            border: 1.5px solid #000;
            padding: 6px;
            font-size: 8px;
        }
        .company-name {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .facture-title {
            text-align: center;
            background: #1a365d;
            color: #fff;
            padding: 6px;
            margin: 8px 0;
        }
        .facture-title h1 {
            font-size: 16px;
            margin: 0;
        }
        .facture-title .ref {
            font-size: 11px;
        }
        .info-section {
            margin-bottom: 8px;
        }
        .info-box {
            border: 1px solid #000;
            padding: 5px;
            font-size: 8px;
        }
        .info-box h3 {
            font-size: 9px;
            font-weight: bold;
            margin-bottom: 3px;
            background: #e2e8f0;
            padding: 2px 4px;
            margin: -5px -5px 4px -5px;
        }
        table.products th {
            background: #1a365d;
            color: #fff;
            padding: 4px 3px;
            font-size: 8px;
            text-align: center;
            border: 1px solid #000;
        }
        table.products td {
            padding: 3px;
            font-size: 8px;
            border: 1px solid #000;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .totals-box {
            width: 200px;
            margin-left: auto;
            border: 1px solid #000;
        }
        .totals-box td {
            padding: 3px 5px;
            font-size: 8px;
            border-bottom: 1px solid #ddd;
        }
        .totals-box .grand-total {
            background: #1a365d;
            color: #fff;
            font-weight: bold;
            font-size: 10px;
        }
        .totals-box .grand-total td {
            border: none;
        }
        .payment-box {
            border: 1px solid #000;
            padding: 5px;
            margin-top: 8px;
            font-size: 8px;
        }
        .signatures td {
            width: 33%;
            text-align: center;
            padding-top: 25px;
            font-size: 8px;
        }
        .signature-line {
            border-top: 1px solid #000;
            width: 80%;
            margin: 0 auto;
            padding-top: 3px;
        }
        .footer {
            text-align: center;
            font-size: 7px;
            color: #666;
            margin-top: 8px;
            border-top: 1px dashed #000;
            padding-top: 5px;
        }
        .legal-info {
            font-size: 7px;
            color: #333;
        }
        .supplier-highlight {
            background: #f0f4f8;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <table class="header-table">
        <tr>
            <td style="width: 50%;">
                <div class="company-box">
                    @if(!empty($settings['company_logo']) && extension_loaded('gd'))
                    @php
                        $logoPath = storage_path('app/public/' . $settings['company_logo']);
                    @endphp
                    @if(file_exists($logoPath))
                    <div style="margin-bottom: 6px;">
                        <img src="{{ $logoPath }}" style="max-height: 50px; max-width: 120px;" alt="Logo">
                    </div>
                    @endif
                    @endif
                    <div class="company-name">{{ $settings['company_name'] ?? 'RAFIK BISKRA' }}</div>
                    <div>{{ $settings['company_address'] ?? 'Biskra, Algerie' }}</div>
                    <div>Tel: {{ $settings['company_phone'] ?? '' }}</div>
                    @if(!empty($settings['company_email']))
                    <div>Email: {{ $settings['company_email'] }}</div>
                    @endif
                    <div class="legal-info" style="margin-top: 4px;">
                        @if(!empty($settings['company_rc']))<strong>RC:</strong> {{ $settings['company_rc'] }} @endif
                        @if(!empty($settings['company_nif']))<strong>NIF:</strong> {{ $settings['company_nif'] }}@endif
                    </div>
                    <div class="legal-info">
                        @if(!empty($settings['company_ai']))<strong>AI:</strong> {{ $settings['company_ai'] }} @endif
                        @if(!empty($settings['company_nis']))<strong>NIS:</strong> {{ $settings['company_nis'] }}@endif
                    </div>
                </div>
            </td>
            <td style="width: 50%; text-align: right; padding-left: 10px;">
                <div style="font-size: 10px;">
                    <strong>Date:</strong> {{ $purchase->date ? \Carbon\Carbon::parse($purchase->date)->format('d/m/Y') : '-' }}<br>
                    <strong>Entrepot:</strong> {{ $purchase->warehouse->name ?? '-' }}<br>
                    <strong>Recepteur:</strong> {{ $purchase->user->name ?? '-' }}
                </div>
            </td>
        </tr>
    </table>

    <!-- Title -->
    <div class="facture-title">
        <h1>BON D'ACHAT</h1>
        <div class="ref">N° {{ $purchase->reference ?? 'N/A' }}</div>
    </div>

    <!-- Supplier Info -->
    <table class="info-section">
        <tr>
            <td style="width: 60%;">
                <div class="info-box supplier-highlight">
                    <h3>FOURNISSEUR</h3>
                    @if($purchase->supplier)
                        <strong style="font-size: 11px;">{{ ArabicHelper::safe($purchase->supplier->name ?? null, 'Fournisseur') }}</strong><br>
                        <strong>Tel:</strong> {{ $purchase->supplier->phone ?? '-' }}
                        @if(!empty($purchase->supplier->mobile))
                        | <strong>Mobile:</strong> {{ $purchase->supplier->mobile }}
                        @endif
                        <br>
                        <strong>Adresse:</strong> {{ ArabicHelper::safe($purchase->supplier->address ?? null, '-') }}
                        @if(!empty($purchase->supplier->city))
                        , {{ $purchase->supplier->city }}
                        @endif
                        @if(!empty($purchase->supplier->email))
                        <br><strong>Email:</strong> {{ $purchase->supplier->email }}
                        @endif
                        <div class="legal-info" style="margin-top: 4px; border-top: 1px dashed #ccc; padding-top: 4px;">
                            @if(!empty($purchase->supplier->rc))<strong>RC:</strong> {{ $purchase->supplier->rc }} @endif
                            @if(!empty($purchase->supplier->nif))<strong>NIF:</strong> {{ $purchase->supplier->nif }} @endif
                            @if(!empty($purchase->supplier->ai))<br><strong>AI:</strong> {{ $purchase->supplier->ai }} @endif
                            @if(!empty($purchase->supplier->nis))<strong>NIS:</strong> {{ $purchase->supplier->nis }} @endif
                            @if(!empty($purchase->supplier->rib))<br><strong>RIB:</strong> {{ $purchase->supplier->rib }} @endif
                        </div>
                    @else
                        <strong>Fournisseur Direct</strong>
                    @endif
                </div>
            </td>
            <td style="width: 40%; padding-left: 10px;">
                <div class="info-box">
                    <h3>PAIEMENT</h3>
                    @php
                        $paymentStatus = $purchase->payment_status ?? 'unpaid';
                    @endphp
                    <span style="color: {{ $paymentStatus === 'paid' ? 'green' : ($paymentStatus === 'partial' ? 'orange' : 'red') }}; font-weight: bold; font-size: 10px;">
                        @switch($paymentStatus)
                            @case('paid') PAYE @break
                            @case('partial') PARTIEL @break
                            @default NON PAYE
                        @endswitch
                    </span><br>
                    <strong>Paye:</strong> {{ number_format($purchase->paid_amount ?? 0, 2) }} DA<br>
                    <strong>Reste:</strong> <span style="color: {{ ($purchase->due_amount ?? 0) > 0 ? 'red' : 'green' }};">{{ number_format($purchase->due_amount ?? 0, 2) }} DA</span>
                </div>
            </td>
        </tr>
    </table>

    <!-- Products -->
    <table class="products">
        <thead>
            <tr>
                <th style="width: 4%;">N°</th>
                <th style="width: 30%;">Designation</th>
                <th style="width: 10%;">Qte</th>
                <th style="width: 10%;">Unite</th>
                <th style="width: 12%;">Nbre Pcs</th>
                <th style="width: 14%;">P.U</th>
                <th style="width: 16%;">Montant</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalQty = 0;
                $totalPieces = 0;
                $calculatedTotal = 0;
            @endphp
            @foreach($purchase->items ?? [] as $index => $item)
            @php
                $product = $item->product ?? null;
                $productName = $product->name ?? 'Produit';
                $unitBuy = $product->unitBuy ?? null;
                $piecesPerPkg = $product->pieces_per_package ?? ($unitBuy->operation_value ?? 1);
                $qty = $item->quantity ?? 0;
                $totalQty += $qty;
                $itemTotalPieces = $qty * $piecesPerPkg;
                $totalPieces += $itemTotalPieces;
                $unitPrice = $item->unit_price ?? 0;
                $itemDiscount = $item->discount ?? 0;
                $subtotal = $item->subtotal ?? ($qty * $unitPrice - $itemDiscount);
                $calculatedTotal += $subtotal;
            @endphp
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td class="text-left">{{ ArabicHelper::safe($productName, 'Produit') }}</td>
                <td class="text-center" style="font-size: 11px; font-weight: bold; color: #1a56db;">{{ number_format($qty, $qty == floor($qty) ? 0 : 2) }}</td>
                <td class="text-center">{{ number_format($piecesPerPkg, 2) }}</td>
                <td class="text-center" style="font-weight: bold;">{{ number_format($itemTotalPieces, 2) }}</td>
                <td class="text-right">{{ number_format($unitPrice, 2) }}</td>
                <td class="text-right" style="font-weight: bold;">{{ number_format($subtotal, 2) }}</td>
            </tr>
            @endforeach
            <!-- Totals Row -->
            <tr style="background: #e2e8f0; font-weight: bold;">
                <td class="text-center">-</td>
                <td class="text-right"><strong>TOTAL</strong></td>
                <td class="text-center">{{ number_format($totalQty, $totalQty == floor($totalQty) ? 0 : 2) }}</td>
                <td class="text-center">-</td>
                <td class="text-center">{{ number_format($totalPieces, 2) }}</td>
                <td class="text-center">-</td>
                <td class="text-right">{{ number_format($calculatedTotal, 2) }}</td>
            </tr>
        </tbody>
    </table>

    @php
        $totalAmount = $purchase->total_amount ?? 0;
        $purchaseDiscount = $purchase->discount ?? 0;
        $purchaseTax = $purchase->tax ?? 0;
        $purchaseShipping = $purchase->shipping ?? 0;
        $grandTotal = $purchase->grand_total ?? ($totalAmount - $purchaseDiscount + $purchaseTax + $purchaseShipping);
    @endphp

    <!-- Totals -->
    <table class="totals-box">
        <tr>
            <td style="text-align: left;"><strong>Total HT:</strong></td>
            <td style="text-align: right;">{{ number_format($totalAmount, 2) }} DA</td>
        </tr>
        @if($purchaseDiscount > 0)
        <tr>
            <td style="text-align: left;">Remise:</td>
            <td style="text-align: right; color: red;">- {{ number_format($purchaseDiscount, 2) }} DA</td>
        </tr>
        @endif
        @if($purchaseTax > 0)
        <tr>
            <td style="text-align: left;">TVA:</td>
            <td style="text-align: right;">{{ number_format($purchaseTax, 2) }} DA</td>
        </tr>
        @endif
        @if($purchaseShipping > 0)
        <tr>
            <td style="text-align: left;">Transport:</td>
            <td style="text-align: right;">{{ number_format($purchaseShipping, 2) }} DA</td>
        </tr>
        @endif
        <tr class="grand-total">
            <td style="text-align: left;">TOTAL TTC:</td>
            <td style="text-align: right;">{{ number_format($grandTotal, 2) }} DA</td>
        </tr>
    </table>

    @if(!empty($purchase->note))
    <div class="payment-box">
        <strong>Observations:</strong> {{ ArabicHelper::safe($purchase->note, '') }}
    </div>
    @endif

    <!-- Signatures -->
    <table class="signatures" style="margin-top: 15px;">
        <tr>
            <td>
                <div class="signature-line">Signature Fournisseur</div>
            </td>
            <td>
                <div class="signature-line">Cachet et Signature</div>
            </td>
        </tr>
    </table>

    <!-- Footer -->
    <div class="footer">
        Bon d'achat genere le {{ now()->format('d/m/Y H:i') }} | Mode de paiement: Especes / Cheque / Virement
    </div>
</body>
</html>
