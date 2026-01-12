@php
use App\Helpers\ArabicHelper;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Bon de Commande - {{ $purchase->reference ?? 'N/A' }}</title>
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
        .commande-title {
            text-align: center;
            background: #065f46;
            color: #fff;
            padding: 8px;
            margin: 8px 0;
        }
        .commande-title h1 {
            font-size: 18px;
            margin: 0;
        }
        .commande-title .ref {
            font-size: 12px;
            margin-top: 3px;
        }
        .info-section {
            margin-bottom: 8px;
        }
        .info-box {
            border: 1px solid #000;
            padding: 6px;
            font-size: 9px;
        }
        .info-box h3 {
            font-size: 10px;
            font-weight: bold;
            margin-bottom: 4px;
            background: #d1fae5;
            color: #065f46;
            padding: 3px 5px;
            margin: -6px -6px 5px -6px;
        }
        table.products th {
            background: #065f46;
            color: #fff;
            padding: 6px 4px;
            font-size: 9px;
            text-align: center;
            border: 1px solid #000;
        }
        table.products td {
            padding: 5px 4px;
            font-size: 9px;
            border: 1px solid #000;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .totals-box {
            width: 220px;
            margin-left: auto;
            border: 2px solid #065f46;
        }
        .totals-box td {
            padding: 4px 6px;
            font-size: 9px;
            border-bottom: 1px solid #ddd;
        }
        .totals-box .grand-total {
            background: #065f46;
            color: #fff;
            font-weight: bold;
            font-size: 11px;
        }
        .totals-box .grand-total td {
            border: none;
        }
        .notes-box {
            border: 1px solid #fbbf24;
            background: #fffbeb;
            padding: 6px;
            margin-top: 10px;
            font-size: 9px;
        }
        .signatures td {
            width: 33%;
            text-align: center;
            padding-top: 30px;
            font-size: 9px;
        }
        .signature-line {
            border-top: 1px solid #000;
            width: 85%;
            margin: 0 auto;
            padding-top: 4px;
        }
        .footer {
            text-align: center;
            font-size: 8px;
            color: #666;
            margin-top: 10px;
            border-top: 1px dashed #000;
            padding-top: 6px;
        }
        .legal-info {
            font-size: 7px;
            color: #333;
        }
        .supplier-box {
            background: #f0fdf4;
            border: 2px solid #065f46;
        }
        .conditions-box {
            border: 1px solid #ddd;
            padding: 6px;
            margin-top: 10px;
            font-size: 8px;
            background: #f9fafb;
        }
        .conditions-box h4 {
            font-weight: bold;
            margin-bottom: 4px;
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
                </div>
            </td>
            <td style="width: 50%; text-align: right; padding-left: 10px;">
                <div style="font-size: 10px;">
                    <strong>Date de Commande:</strong> {{ $purchase->date ? \Carbon\Carbon::parse($purchase->date)->format('d/m/Y') : now()->format('d/m/Y') }}<br>
                    <strong>Entrepot:</strong> {{ $purchase->warehouse->name ?? '-' }}<br>
                    <strong>Demandeur:</strong> {{ $purchase->user->name ?? '-' }}
                </div>
            </td>
        </tr>
    </table>

    <!-- Title -->
    <div class="commande-title">
        <h1>BON DE COMMANDE</h1>
        <div class="ref">N° {{ $purchase->reference ?? 'N/A' }}</div>
    </div>

    <!-- Supplier Info -->
    <table class="info-section">
        <tr>
            <td style="width: 100%;">
                <div class="info-box supplier-box">
                    <h3>FOURNISSEUR / DESTINATAIRE</h3>
                    @if($purchase->supplier)
                        <table style="width: 100%; border: none;">
                            <tr>
                                <td style="width: 50%; border: none; vertical-align: top;">
                                    <strong style="font-size: 12px;">{{ ArabicHelper::safe($purchase->supplier->name ?? null, 'Fournisseur') }}</strong><br><br>
                                    <strong>Telephone:</strong> {{ $purchase->supplier->phone ?? '-' }}<br>
                                    @if(!empty($purchase->supplier->mobile))
                                    <strong>Mobile:</strong> {{ $purchase->supplier->mobile }}<br>
                                    @endif
                                    @if(!empty($purchase->supplier->email))
                                    <strong>Email:</strong> {{ $purchase->supplier->email }}<br>
                                    @endif
                                </td>
                                <td style="width: 50%; border: none; vertical-align: top;">
                                    <strong>Adresse:</strong><br>
                                    {{ ArabicHelper::safe($purchase->supplier->address ?? null, '-') }}
                                    @if(!empty($purchase->supplier->city))
                                    <br>{{ $purchase->supplier->city }}
                                    @endif
                                    <br><br>
                                    <div class="legal-info">
                                        @if(!empty($purchase->supplier->rc))<strong>RC:</strong> {{ $purchase->supplier->rc }}<br>@endif
                                        @if(!empty($purchase->supplier->nif))<strong>NIF:</strong> {{ $purchase->supplier->nif }}<br>@endif
                                        @if(!empty($purchase->supplier->ai))<strong>AI:</strong> {{ $purchase->supplier->ai }}<br>@endif
                                        @if(!empty($purchase->supplier->nis))<strong>NIS:</strong> {{ $purchase->supplier->nis }}<br>@endif
                                        @if(!empty($purchase->supplier->rib))<strong>RIB:</strong> {{ $purchase->supplier->rib }}@endif
                                    </div>
                                </td>
                            </tr>
                        </table>
                    @else
                        <strong>Fournisseur Direct</strong>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <!-- Products -->
    <table class="products">
        <thead>
            <tr>
                <th style="width: 4%;">N°</th>
                <th style="width: 32%;">Designation</th>
                <th style="width: 10%;">Qte</th>
                <th style="width: 10%;">Unite</th>
                <th style="width: 12%;">Nbre Pcs</th>
                <th style="width: 14%;">P.U</th>
                <th style="width: 14%;">Montant</th>
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
                // Use stored subtotal (includes pieces_per_package in calculation)
                $subtotal = $item->subtotal ?? (($unitPrice * $piecesPerPkg * $qty) - $itemDiscount);
                $calculatedTotal += $subtotal;
            @endphp
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td class="text-left">{{ ArabicHelper::safe($productName, 'Produit') }}</td>
                <td class="text-center" style="font-size: 12px; font-weight: bold; color: #065f46;">{{ number_format($qty, $qty == floor($qty) ? 0 : 2) }}</td>
                <td class="text-center">{{ number_format($piecesPerPkg, 2) }}</td>
                <td class="text-center" style="font-weight: bold;">{{ number_format($itemTotalPieces, 2) }}</td>
                <td class="text-right">{{ number_format($unitPrice, 2) }}</td>
                <td class="text-right" style="font-weight: bold;">{{ number_format($subtotal, 2) }}</td>
            </tr>
            @endforeach
            <!-- Totals Row -->
            <tr style="background: #d1fae5; font-weight: bold;">
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
        $totalAmount = $purchase->total_amount ?? $calculatedTotal;
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

    <!-- Conditions -->
    <div class="conditions-box">
        <h4>CONDITIONS DE LIVRAISON:</h4>
        <div>- Delai de livraison: A convenir</div>
        <div>- Lieu de livraison: {{ $purchase->warehouse->name ?? 'Entrepot principal' }}</div>
        <div>- Mode de paiement: A la livraison / Cheque / Virement</div>
    </div>

    @if(!empty($purchase->note))
    <div class="notes-box">
        <strong>Notes / Instructions:</strong> {{ ArabicHelper::safe($purchase->note, '') }}
    </div>
    @endif

    <!-- Signatures -->
    <table class="signatures" style="margin-top: 20px;">
        <tr>
            <td>
                <div class="signature-line">Demandeur</div>
            </td>
            <td>
                <div class="signature-line">Responsable Achat</div>
            </td>
            <td>
                <div class="signature-line">Fournisseur (Acceptation)</div>
            </td>
        </tr>
    </table>

    <!-- Footer -->
    <div class="footer">
        Bon de Commande genere le {{ now()->format('d/m/Y H:i') }} | Ce document fait office de commande officielle
    </div>
</body>
</html>
