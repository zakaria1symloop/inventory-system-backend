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
                </div>
            </td>
            <td style="width: 50%; text-align: right; padding-left: 10px;">
                <div style="font-size: 10px;">
                    <strong>Date:</strong> {{ $purchase->date ? \Carbon\Carbon::parse($purchase->date)->format('d/m/Y') : '-' }}<br>
                    <strong>Entrepot:</strong> {{ ArabicHelper::safe($purchase->warehouse->name ?? null, '-') }}<br>
                    <strong>Recepteur:</strong> {{ ArabicHelper::safe($purchase->user->name ?? null, '-') }}
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
                <th style="width: 20%;">Designation</th>
                <th style="width: 7%;">Qte</th>
                <th style="width: 7%;">Unite</th>
                <th style="width: 8%;">Pcs</th>
                <th style="width: 9%;">P.U</th>
                <th style="width: 10%;">Montant HT</th>
                <th style="width: 5%;">TVA%</th>
                <th style="width: 9%;">TVA</th>
                <th style="width: 11%;">Montant TTC</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalQty = 0;
                $totalPieces = 0;
                $totalHt = 0;
                $totalTva = 0;
            @endphp
            @foreach($purchase->items ?? [] as $index => $item)
            @php
                $product = $item->product ?? null;
                $productName = $product->name ?? 'Produit';
                $unitBuy = $product->unitBuy ?? null;
                $piecesPerPkg = $product->pieces_per_package ?? ($unitBuy->operation_value ?? 1);
                $qty = $item->quantity ?? 0;
                $totalQty += $qty;
                $itemTotalPieces = $qty;
                $totalPieces += $itemTotalPieces;
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
                <td class="text-center" style="font-size: 11px; font-weight: bold; color: #1a56db;">
                    @if($piecesPerPkg > 1)
                        @php
                            $cartons = intval(floor($qty / $piecesPerPkg));
                            $extraPieces = $qty % $piecesPerPkg;
                        @endphp
                        {{ $cartons }}@if($extraPieces > 0) + {{ $extraPieces }}ق@endif
                    @else
                        {{ number_format($qty, $qty == floor($qty) ? 0 : 2) }}
                    @endif
                </td>
                <td class="text-center">{{ number_format($piecesPerPkg, 2) }}</td>
                <td class="text-center" style="font-weight: bold;">{{ number_format($itemTotalPieces, 2) }}</td>
                <td class="text-right">
                    {{ number_format($unitPrice, 2) }}
                    @if($piecesPerPkg > 1)
                    <br><span style="font-size: 7px; color: #666;">({{ number_format($unitPrice * $piecesPerPkg, 2) }}/crt)</span>
                    @endif
                </td>
                <td class="text-right">{{ number_format($montantHt, 2) }}</td>
                <td class="text-center">{{ $productTaxPercent > 0 ? number_format($productTaxPercent, 0) . '%' : '-' }}</td>
                <td class="text-right">{{ $productTaxPercent > 0 ? number_format($lineTva, 2) : '-' }}</td>
                <td class="text-right" style="font-weight: bold;">{{ number_format($montantHt + $lineTva, 2) }}</td>
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
                <td class="text-right">{{ number_format($totalHt, 2) }}</td>
                <td class="text-center">-</td>
                <td class="text-right">{{ $totalTva > 0 ? number_format($totalTva, 2) : '-' }}</td>
                <td class="text-right">{{ number_format($totalHt + $totalTva, 2) }}</td>
            </tr>
        </tbody>
    </table>

    @php
        $purchaseDiscount = $purchase->discount ?? 0;
        $purchaseShipping = $purchase->shipping ?? 0;
        $purchaseTimbre = $purchase->timbre ?? 0;
        $grandTotal = $purchase->grand_total ?? ($totalHt + $totalTva - $purchaseDiscount + $purchaseShipping + $purchaseTimbre);
    @endphp

    <!-- Totals -->
    <table class="totals-box">
        <tr>
            <td style="text-align: left;"><strong>Total HT:</strong></td>
            <td style="text-align: right;">{{ number_format($totalHt, 2) }} DA</td>
        </tr>
        @if($purchaseDiscount > 0)
        <tr>
            <td style="text-align: left;">Remise:</td>
            <td style="text-align: right; color: red;">- {{ number_format($purchaseDiscount, 2) }} DA</td>
        </tr>
        @endif
        <tr>
            <td style="text-align: left;">TVA:</td>
            <td style="text-align: right;">{{ number_format($totalTva, 2) }} DA</td>
        </tr>
        @if($purchaseShipping > 0)
        <tr>
            <td style="text-align: left;">Transport:</td>
            <td style="text-align: right;">{{ number_format($purchaseShipping, 2) }} DA</td>
        </tr>
        @endif
        @if($purchaseTimbre > 0)
        <tr>
            <td style="text-align: left;">Timbre:</td>
            <td style="text-align: right;">{{ number_format($purchaseTimbre, 2) }} DA</td>
        </tr>
        @endif
        <tr class="grand-total">
            <td style="text-align: left;">TOTAL TTC:</td>
            <td style="text-align: right;">{{ number_format($grandTotal, 2) }} DA</td>
        </tr>
    </table>

    <!-- Debt Information -->
    @if($purchase->supplier)
    @php
        // Calculate debt information
        $oldDebt = $purchase->supplier->balance - $purchase->grand_total + ($purchase->paid_amount ?? 0);
        $paidForThisPurchase = $purchase->paid_amount ?? 0;
        $newTotalDebt = $purchase->supplier->balance;
    @endphp
    <table style="width: 250px; margin-left: auto; border: 1px solid #000; margin-top: 10px; margin-bottom: 10px;">
        <tr style="background: #f5f5f5;">
            <td style="padding: 5px; text-align: left; border-bottom: 1px solid #ddd; font-size: 8px;"><strong>Dette Ancienne:</strong></td>
            <td style="padding: 5px; text-align: right; border-bottom: 1px solid #ddd; font-size: 8px; color: {{ $oldDebt > 0 ? 'red' : 'green' }};"><strong>{{ number_format($oldDebt, 2) }} DA</strong></td>
        </tr>
        <tr style="background: #f5f5f5;">
            <td style="padding: 5px; text-align: left; border-bottom: 1px solid #ddd; font-size: 8px;"><strong>Cet Achat:</strong></td>
            <td style="padding: 5px; text-align: right; border-bottom: 1px solid #ddd; font-size: 8px; color: red;"><strong>{{ number_format($purchase->grand_total, 2) }} DA</strong></td>
        </tr>
        @if($paidForThisPurchase > 0)
        <tr style="background: #e8f5e9;">
            <td style="padding: 5px; text-align: left; border-bottom: 1px solid #ddd; font-size: 8px;">Paiement:</td>
            <td style="padding: 5px; text-align: right; border-bottom: 1px solid #ddd; font-size: 8px; color: green;"><strong>- {{ number_format($paidForThisPurchase, 2) }} DA</strong></td>
        </tr>
        @endif
        <tr style="background: #1a365d; color: #fff; font-weight: bold;">
            <td style="padding: 6px; text-align: left; font-size: 9px;">DETTE TOTALE:</td>
            <td style="padding: 6px; text-align: right; font-size: 9px;">{{ number_format($newTotalDebt, 2) }} DA</td>
        </tr>
    </table>
    @endif

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
