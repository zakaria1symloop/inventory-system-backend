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
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 8px;
            font-weight: bold;
        }
        .status-draft { background: #e5e7eb; color: #374151; }
        .status-sent { background: #dbeafe; color: #1d4ed8; }
        .status-confirmed { background: #d1fae5; color: #065f46; }
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
                    <strong>Date de Commande:</strong> {{ $order->date ? \Carbon\Carbon::parse($order->date)->format('d/m/Y') : now()->format('d/m/Y') }}<br>
                    @if($order->expected_delivery_date)
                    <strong>Livraison Prevue:</strong> {{ \Carbon\Carbon::parse($order->expected_delivery_date)->format('d/m/Y') }}<br>
                    @endif
                    <strong>Entrepot:</strong> {{ $order->warehouse->name ?? '-' }}<br>
                    <strong>Demandeur:</strong> {{ $order->user->name ?? '-' }}<br>
                    <span class="status-badge status-{{ $order->status }}">
                        @switch($order->status)
                            @case('draft') Brouillon @break
                            @case('sent') Envoye @break
                            @case('confirmed') Confirme @break
                            @case('received') Recu @break
                            @case('cancelled') Annule @break
                            @default {{ $order->status }}
                        @endswitch
                    </span>
                </div>
            </td>
        </tr>
    </table>

    <!-- Title -->
    <div class="commande-title">
        <h1>BON DE COMMANDE</h1>
        <div class="ref">N° {{ $order->reference ?? 'N/A' }}</div>
    </div>

    <!-- Supplier Info -->
    <table class="info-section">
        <tr>
            <td style="width: 100%;">
                <div class="info-box supplier-box">
                    <h3>FOURNISSEUR / DESTINATAIRE</h3>
                    @if($order->supplier)
                        <table style="width: 100%; border: none;">
                            <tr>
                                <td style="width: 50%; border: none; vertical-align: top;">
                                    <strong style="font-size: 12px;">{{ ArabicHelper::safe($order->supplier->name ?? null, 'Fournisseur') }}</strong><br><br>
                                    <strong>Telephone:</strong> {{ $order->supplier->phone ?? '-' }}<br>
                                    @if(!empty($order->supplier->mobile))
                                    <strong>Mobile:</strong> {{ $order->supplier->mobile }}<br>
                                    @endif
                                    @if(!empty($order->supplier->email))
                                    <strong>Email:</strong> {{ $order->supplier->email }}<br>
                                    @endif
                                </td>
                                <td style="width: 50%; border: none; vertical-align: top;">
                                    <strong>Adresse:</strong><br>
                                    {{ ArabicHelper::safe($order->supplier->address ?? null, '-') }}
                                    @if(!empty($order->supplier->city))
                                    <br>{{ $order->supplier->city }}
                                    @endif
                                    <br><br>
                                    <div class="legal-info">
                                        @if(!empty($order->supplier->rc))<strong>RC:</strong> {{ $order->supplier->rc }}<br>@endif
                                        @if(!empty($order->supplier->nif))<strong>NIF:</strong> {{ $order->supplier->nif }}<br>@endif
                                        @if(!empty($order->supplier->ai))<strong>AI:</strong> {{ $order->supplier->ai }}<br>@endif
                                        @if(!empty($order->supplier->nis))<strong>NIS:</strong> {{ $order->supplier->nis }}<br>@endif
                                        @if(!empty($order->supplier->rib))<strong>RIB:</strong> {{ $order->supplier->rib }}@endif
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
                <th style="width: 5%;">N°</th>
                <th style="width: 35%;">Designation</th>
                <th style="width: 10%;">Qte</th>
                <th style="width: 10%;">Unite</th>
                <th style="width: 10%;">Pcs/U</th>
                <th style="width: 14%;">P.U</th>
                <th style="width: 16%;">Montant</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalQty = 0;
                $calculatedTotal = 0;
            @endphp
            @foreach($order->items ?? [] as $index => $item)
            @php
                $product = $item->product ?? null;
                $productName = $product->name ?? 'Produit';
                $unitBuy = $product->unitBuy ?? null;
                $unitName = $unitBuy->short_name ?? 'U';
                $piecesPerPackage = $product->pieces_per_package ?? 1;
                $qty = $item->quantity ?? 0;
                $totalQty += $qty;
                $unitPrice = $item->unit_price ?? 0;
                $itemDiscount = $item->discount ?? 0;
                // Use stored subtotal (includes pieces_per_package in calculation)
                $subtotal = $item->subtotal ?? (($unitPrice * $piecesPerPackage * $qty) - $itemDiscount);
                $calculatedTotal += $subtotal;
            @endphp
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td class="text-left">{{ ArabicHelper::safe($productName, 'Produit') }}</td>
                <td class="text-center" style="font-size: 12px; font-weight: bold; color: #065f46;">{{ number_format($qty, $qty == floor($qty) ? 0 : 2) }}</td>
                <td class="text-center">{{ $unitName }}</td>
                <td class="text-center" style="color: #666;">{{ $piecesPerPackage > 1 ? $piecesPerPackage : '-' }}</td>
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
                <td class="text-center">-</td>
                <td class="text-center">-</td>
                <td class="text-right">{{ number_format($calculatedTotal, 2) }}</td>
            </tr>
        </tbody>
    </table>

    @php
        $totalAmount = $order->total_amount ?? $calculatedTotal;
        $orderDiscount = $order->discount ?? 0;
        $orderTax = $order->tax ?? 0;
        $orderShipping = $order->shipping ?? 0;
        $grandTotal = $order->grand_total ?? ($totalAmount - $orderDiscount + $orderTax + $orderShipping);
    @endphp

    <!-- Totals -->
    <table class="totals-box">
        <tr>
            <td style="text-align: left;"><strong>Total HT:</strong></td>
            <td style="text-align: right;">{{ number_format($totalAmount, 2) }} DA</td>
        </tr>
        @if($orderDiscount > 0)
        <tr>
            <td style="text-align: left;">Remise:</td>
            <td style="text-align: right; color: red;">- {{ number_format($orderDiscount, 2) }} DA</td>
        </tr>
        @endif
        @if($orderTax > 0)
        <tr>
            <td style="text-align: left;">TVA:</td>
            <td style="text-align: right;">{{ number_format($orderTax, 2) }} DA</td>
        </tr>
        @endif
        @if($orderShipping > 0)
        <tr>
            <td style="text-align: left;">Transport:</td>
            <td style="text-align: right;">{{ number_format($orderShipping, 2) }} DA</td>
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
        @if(!empty($order->terms))
            {!! nl2br(e($order->terms)) !!}
        @else
            <div>- Delai de livraison: A convenir</div>
            <div>- Lieu de livraison: {{ $order->warehouse->name ?? 'Entrepot principal' }}</div>
            <div>- Mode de paiement: A la livraison / Cheque / Virement</div>
        @endif
    </div>

    @if(!empty($order->note))
    <div class="notes-box">
        <strong>Notes / Instructions:</strong> {{ ArabicHelper::safe($order->note, '') }}
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
