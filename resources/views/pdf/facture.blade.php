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
                    <div class="company-name">{{ ArabicHelper::safe($settings['company_name'] ?? null, 'RAFIK BISKRA') }}</div>
                    <div>{{ ArabicHelper::safe($settings['company_address'] ?? null, 'Biskra, Algerie') }}</div>
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
                    <strong>Entrepot:</strong> {{ ArabicHelper::safe($sale->warehouse->name ?? null, '-') }}<br>
                    <strong>Vendeur:</strong> {{ ArabicHelper::safe($sale->user->name ?? null, '-') }}
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
                        @if(!empty($sale->client->rc) || !empty($sale->client->nif) || !empty($sale->client->ai) || !empty($sale->client->nis) || !empty($sale->client->rib))
                        <div class="legal-info" style="margin-top: 4px; border-top: 1px dashed #ccc; padding-top: 4px;">
                            @if(!empty($sale->client->rc))<strong>RC:</strong> {{ $sale->client->rc }} @endif
                            @if(!empty($sale->client->nif))<strong>NIF:</strong> {{ $sale->client->nif }} @endif
                            @if(!empty($sale->client->ai))<br><strong>AI:</strong> {{ $sale->client->ai }} @endif
                            @if(!empty($sale->client->nis))<strong>NIS:</strong> {{ $sale->client->nis }} @endif
                            @if(!empty($sale->client->rib))<br><strong>RIB:</strong> {{ $sale->client->rib }} @endif
                        </div>
                        @endif
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
                <th style="width: 22%;">Designation</th>
                <th style="width: 7%;">Unite</th>
                <th style="width: 6%;">Qte</th>
                <th style="width: 7%;">Pcs</th>
                <th style="width: 9%;">P.U</th>
                <th style="width: 7%;">Remise</th>
                <th style="width: 10%;">Montant HT</th>
                <th style="width: 5%;">TVA%</th>
                <th style="width: 9%;">TVA</th>
                <th style="width: 11%;">Montant TTC</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalPieces = 0;
                $totalHt = 0;
                $totalTva = 0;
            @endphp
            @foreach($sale->items ?? [] as $index => $item)
            @php
                $product = $item->product ?? null;
                $productName = $product->name ?? 'Produit';
                $piecesPerPkg = $product->pieces_per_package ?? 1;
                $unitShortName = $product->unitSale->short_name ?? 'U';
                $qty = $item->quantity ?? 0;
                $nbPieces = $qty;
                $totalPieces += $nbPieces;
                $unitPrice = $item->unit_price ?? 0;
                $itemDiscount = $item->discount ?? 0;
                // Montant HT = price * qty - discount (before tax)
                $montantHt = ($unitPrice * $qty) - $itemDiscount;
                $totalHt += $montantHt;
                $productTaxPercent = $product->tax_percent ?? 0;
                $lineTva = $montantHt * $productTaxPercent / 100;
                $totalTva += $lineTva;
            @endphp
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td class="text-left">{{ ArabicHelper::safe($productName, 'Produit') }}</td>
                <td class="text-center">
                    {{ ArabicHelper::safe($unitShortName) }}
                    @if($piecesPerPkg > 1)
                    <br><span style="font-size: 7px; color: #666;">({{ $piecesPerPkg }} pcs)</span>
                    @endif
                </td>
                <td class="text-center">
                    @if($piecesPerPkg > 1)
                        @php
                            $cartons = intval(floor($qty / $piecesPerPkg));
                            $extraPieces = $qty % $piecesPerPkg;
                        @endphp
                        @if($extraPieces > 0)
                            {{ $cartons }} + {{ $extraPieces }}ق
                        @else
                            {{ $cartons }}
                        @endif
                    @else
                        {{ number_format($qty, $qty == floor($qty) ? 0 : 2) }}
                    @endif
                </td>
                <td class="text-center" style="font-weight: bold;">{{ number_format($nbPieces, $nbPieces == floor($nbPieces) ? 0 : 2) }}</td>
                <td class="text-right">
                    {{ number_format($unitPrice, 2) }}
                    @if($piecesPerPkg > 1)
                    <br><span style="font-size: 7px; color: #666;">({{ number_format($unitPrice * $piecesPerPkg, 2) }}/crt)</span>
                    @endif
                </td>
                <td class="text-right">{{ number_format($itemDiscount, 2) }}</td>
                <td class="text-right">{{ number_format($montantHt, 2) }}</td>
                <td class="text-center">{{ $productTaxPercent > 0 ? number_format($productTaxPercent, 0) . '%' : '-' }}</td>
                <td class="text-right">{{ $productTaxPercent > 0 ? number_format($lineTva, 2) : '-' }}</td>
                <td class="text-right" style="font-weight: bold;">{{ number_format($montantHt + $lineTva, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @php
        $saleDiscount = $sale->discount ?? 0;
        $saleShipping = $sale->shipping ?? 0;
        $saleTimbre = $sale->timbre ?? 0;
        $grandTotal = $sale->grand_total ?? ($totalHt + $totalTva - $saleDiscount + $saleShipping + $saleTimbre);
    @endphp

    <!-- Totals -->
    <table class="totals-box">
        <tr>
            <td style="text-align: left;"><strong>Total HT:</strong></td>
            <td style="text-align: right;">{{ number_format($totalHt, 2) }} DA</td>
        </tr>
        @if($saleDiscount > 0)
        <tr>
            <td style="text-align: left;">Remise:</td>
            <td style="text-align: right; color: red;">- {{ number_format($saleDiscount, 2) }} DA</td>
        </tr>
        @endif
        <tr>
            <td style="text-align: left;">TVA:</td>
            <td style="text-align: right;">{{ number_format($totalTva, 2) }} DA</td>
        </tr>
        @if($saleShipping > 0)
        <tr>
            <td style="text-align: left;">Livraison:</td>
            <td style="text-align: right;">{{ number_format($saleShipping, 2) }} DA</td>
        </tr>
        @endif
        @if($saleTimbre > 0)
        <tr>
            <td style="text-align: left;">Timbre:</td>
            <td style="text-align: right;">{{ number_format($saleTimbre, 2) }} DA</td>
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
