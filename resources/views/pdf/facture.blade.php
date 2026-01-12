@php
use App\Helpers\ArabicHelper;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Facture - {{ $sale->reference ?? 'N/A' }}</title>
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
            background: #000;
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
            background: #eee;
            padding: 2px 4px;
            margin: -5px -5px 4px -5px;
        }
        table.products th {
            background: #333;
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
            background: #333;
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
                    @if(!empty($settings['company_rib']))
                    <div class="legal-info">
                        <strong>RIB:</strong> {{ $settings['company_rib'] }}
                    </div>
                    @endif
                </div>
            </td>
            <td style="width: 50%; text-align: right; padding-left: 10px;">
                <div style="font-size: 10px;">
                    <strong>Date:</strong> {{ $sale->date ? \Carbon\Carbon::parse($sale->date)->format('d/m/Y') : '-' }}<br>
                    <strong>Entrepot:</strong> {{ $sale->warehouse->name ?? '-' }}<br>
                    <strong>Vendeur:</strong> {{ $sale->user->name ?? '-' }}
                </div>
            </td>
        </tr>
    </table>

    <!-- Title -->
    <div class="facture-title">
        <h1>FACTURE</h1>
        <div class="ref">N° {{ $sale->reference ?? 'N/A' }}</div>
    </div>

    <!-- Client Info -->
    <table class="info-section">
        <tr>
            <td style="width: 60%;">
                <div class="info-box">
                    <h3>CLIENT</h3>
                    @if($sale->client)
                        <strong>{{ ArabicHelper::safe($sale->client->name ?? null, 'Client') }}</strong><br>
                        Tel: {{ $sale->client->phone ?? '-' }}<br>
                        Adresse: {{ ArabicHelper::safe($sale->client->address ?? null, '-') }}
                    @else
                        <strong>Client Comptoir</strong>
                    @endif
                </div>
            </td>
            <td style="width: 40%; padding-left: 10px;">
                <div class="info-box">
                    <h3>PAIEMENT</h3>
                    @php
                        $paymentStatus = $sale->payment_status ?? 'unpaid';
                    @endphp
                    <span style="color: {{ $paymentStatus === 'paid' ? 'green' : ($paymentStatus === 'partial' ? 'orange' : 'red') }};">
                        @switch($paymentStatus)
                            @case('paid') PAYE @break
                            @case('partial') PARTIEL @break
                            @default NON PAYE
                        @endswitch
                    </span><br>
                    Paye: {{ number_format($sale->paid_amount ?? 0, 2) }} DA<br>
                    Reste: {{ number_format($sale->due_amount ?? 0, 2) }} DA
                </div>
            </td>
        </tr>
    </table>

    <!-- Products -->
    <table class="products">
        <thead>
            <tr>
                <th style="width: 4%;">N°</th>
                <th style="width: 36%;">Designation</th>
                <th style="width: 14%;">Unite (Pcs)</th>
                <th style="width: 8%;">Qte</th>
                <th style="width: 12%;">P.U</th>
                <th style="width: 10%;">Remise</th>
                <th style="width: 16%;">Montant</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->items ?? [] as $index => $item)
            @php
                $product = $item->product ?? null;
                $productName = $product->name ?? 'Produit';
                $piecesPerPkg = $product->pieces_per_package ?? 1;
                $unitShortName = $product->unitSale->short_name ?? 'U';
                $qty = $item->quantity ?? 0;
                $unitPrice = $item->unit_price ?? 0;
                $itemDiscount = $item->discount ?? 0;
                // Use the stored subtotal which includes pieces_per_package calculation
                $lineTotal = $item->subtotal ?? (($unitPrice * $piecesPerPkg * $qty) - $itemDiscount);
            @endphp
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td class="text-left">{{ ArabicHelper::safe($productName, 'Produit') }}</td>
                <td class="text-center">
                    {{ $unitShortName }}
                    @if($piecesPerPkg > 1)
                    <br><span style="font-size: 7px; color: #666;">({{ $piecesPerPkg }} pcs)</span>
                    @endif
                </td>
                <td class="text-center">{{ number_format($qty, $qty == floor($qty) ? 0 : 2) }}</td>
                <td class="text-right">{{ number_format($unitPrice, 2) }}</td>
                <td class="text-right">{{ number_format($itemDiscount, 2) }}</td>
                <td class="text-right">{{ number_format($lineTotal, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @php
        // Use stored totals from database (already includes pieces_per_package)
        $calculatedTotal = $sale->total_amount ?? 0;
        $saleDiscount = $sale->discount ?? 0;
        $saleTax = $sale->tax ?? 0;
        $saleShipping = $sale->shipping ?? 0;
        $grandTotal = $sale->grand_total ?? ($calculatedTotal - $saleDiscount + $saleTax + $saleShipping);
    @endphp

    <!-- Totals -->
    <table class="totals-box">
        <tr>
            <td style="text-align: left;"><strong>Total HT:</strong></td>
            <td style="text-align: right;">{{ number_format($calculatedTotal, 2) }} DA</td>
        </tr>
        @if($saleDiscount > 0)
        <tr>
            <td style="text-align: left;">Remise:</td>
            <td style="text-align: right; color: red;">- {{ number_format($saleDiscount, 2) }} DA</td>
        </tr>
        @endif
        @if($saleTax > 0)
        <tr>
            <td style="text-align: left;">TVA:</td>
            <td style="text-align: right;">{{ number_format($saleTax, 2) }} DA</td>
        </tr>
        @endif
        @if($saleShipping > 0)
        <tr>
            <td style="text-align: left;">Livraison:</td>
            <td style="text-align: right;">{{ number_format($saleShipping, 2) }} DA</td>
        </tr>
        @endif
        <tr class="grand-total">
            <td style="text-align: left;">TOTAL TTC:</td>
            <td style="text-align: right;">{{ number_format($grandTotal, 2) }} DA</td>
        </tr>
    </table>

    @if(!empty($sale->note))
    <div class="payment-box">
        <strong>Observations:</strong> {{ ArabicHelper::safe($sale->note, '') }}
    </div>
    @endif

    <!-- Payment History -->
    @if($sale->payments && count($sale->payments) > 0)
    <div style="margin-top: 10px;">
        <table class="products">
            <thead>
                <tr>
                    <th colspan="4" style="background: #555; text-align: left; padding-left: 8px;">HISTORIQUE DES PAIEMENTS</th>
                </tr>
                <tr>
                    <th style="width: 25%;">Date</th>
                    <th style="width: 25%;">Mode</th>
                    <th style="width: 25%;">Montant</th>
                    <th style="width: 25%;">Reference</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sale->payments as $payment)
                @php
                    $paymentMethod = $payment->payment_method ?? 'other';
                    $paymentAmount = $payment->amount ?? 0;
                    $paymentDate = $payment->date ?? now();
                    $paymentRef = $payment->reference ?? '-';
                @endphp
                <tr>
                    <td class="text-center">{{ \Carbon\Carbon::parse($paymentDate)->format('d/m/Y') }}</td>
                    <td class="text-center">
                        @switch($paymentMethod)
                            @case('cash') <strong style="color: green;">Especes</strong> @break
                            @case('bank') <strong style="color: blue;">Virement</strong> @break
                            @case('check') <strong style="color: orange;">Cheque</strong> @break
                            @default {{ $paymentMethod }}
                        @endswitch
                    </td>
                    <td class="text-right" style="color: green; font-weight: bold;">{{ number_format($paymentAmount, 2) }} DA</td>
                    <td class="text-center" style="font-size: 7px;">{{ $paymentRef }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <!-- Signatures -->
    <table class="signatures" style="margin-top: 15px;">
        <tr>
            <td>
                <div class="signature-line">Signature Client</div>
            </td>
            <td>
                <div class="signature-line">Cachet et Signature</div>
            </td>
        </tr>
    </table>

    <!-- Footer -->
    <div class="footer">
        Facture generee le {{ now()->format('d/m/Y H:i') }} | Mode de paiement: Especes / Cheque / Virement
    </div>
</body>
</html>
