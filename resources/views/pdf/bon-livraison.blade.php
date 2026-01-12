@php
use App\Helpers\ArabicHelper;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Bon de Livraison - {{ $sale->reference ?? 'N/A' }}</title>
    <style>
        * {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            margin: 0;
            padding: 0;
        }
        body {
            font-size: 11px;
            line-height: 1.4;
            color: #000;
            padding: 15px;
        }
        .header-table {
            width: 100%;
            margin-bottom: 15px;
        }
        .company-info {
            border: 2px solid #000;
            padding: 10px;
            width: 48%;
        }
        .company-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        .company-details {
            font-size: 10px;
            line-height: 1.5;
        }
        .bl-title {
            text-align: center;
            margin: 15px 0;
            background: #000;
            color: #fff;
            padding: 10px;
        }
        .bl-title h1 {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 3px;
        }
        .bl-ref {
            font-size: 14px;
            font-weight: bold;
        }
        .info-section {
            width: 100%;
            margin-bottom: 15px;
        }
        .info-box {
            border: 1px solid #000;
            padding: 10px;
            margin-bottom: 10px;
        }
        .info-box h3 {
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 8px;
            text-decoration: underline;
            background: #e5e5e5;
            padding: 3px 5px;
            margin: -10px -10px 8px -10px;
        }
        .info-row {
            margin: 5px 0;
        }
        .info-label {
            font-weight: bold;
            display: inline-block;
            width: 80px;
        }
        table.products {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        table.products th {
            background: #333;
            color: #fff;
            border: 1px solid #000;
            padding: 10px 5px;
            text-align: center;
            font-weight: bold;
            font-size: 11px;
        }
        table.products td {
            border: 1px solid #000;
            padding: 8px 5px;
            font-size: 11px;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .qty-cell {
            font-size: 16px;
            font-weight: bold;
            text-align: center;
        }
        .unit-info {
            font-size: 9px;
            color: #666;
        }
        .total-row {
            background: #e5e5e5;
            font-weight: bold;
        }
        .signatures {
            width: 100%;
            margin-top: 30px;
        }
        .check-section {
            border: 1px solid #000;
            padding: 10px;
            margin-bottom: 15px;
        }
        .check-section h4 {
            font-weight: bold;
            margin-bottom: 10px;
        }
        .check-row {
            margin: 8px 0;
        }
        .checkbox {
            display: inline-block;
            width: 15px;
            height: 15px;
            border: 1px solid #000;
            margin-right: 8px;
            vertical-align: middle;
        }
        .footer-info {
            margin-top: 20px;
            text-align: center;
            font-size: 9px;
            color: #666;
            border-top: 1px dashed #000;
            padding-top: 10px;
        }
        .important-note {
            background: #fffbeb;
            border: 2px solid #f59e0b;
            padding: 10px;
            margin-bottom: 15px;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <!-- Header with Company Info -->
    <table class="header-table">
        <tr>
            <td class="company-info" style="vertical-align: top;">
                @if(!empty($settings['company_logo']) && extension_loaded('gd'))
                @php
                    $logoPath = storage_path('app/public/' . $settings['company_logo']);
                @endphp
                @if(file_exists($logoPath))
                <div style="margin-bottom: 8px;">
                    <img src="{{ $logoPath }}" style="max-height: 50px; max-width: 120px;" alt="Logo">
                </div>
                @endif
                @endif
                <div class="company-name">{{ $settings['company_name'] ?? 'RAFIK BISKRA' }}</div>
                <div class="company-details">
                    <strong>Adresse:</strong> {{ $settings['company_address'] ?? 'Biskra, Algerie' }}<br>
                    <strong>Tel:</strong> {{ $settings['company_phone'] ?? '' }}
                    @if(!empty($settings['company_email']))
                    <br><strong>Email:</strong> {{ $settings['company_email'] }}
                    @endif
                </div>
                <div style="font-size: 8px; margin-top: 5px; color: #333;">
                    @if(!empty($settings['company_rc']))<strong>RC:</strong> {{ $settings['company_rc'] }} @endif
                    @if(!empty($settings['company_nif']))<strong>NIF:</strong> {{ $settings['company_nif'] }}@endif
                </div>
                <div style="font-size: 8px; color: #333;">
                    @if(!empty($settings['company_ai']))<strong>AI:</strong> {{ $settings['company_ai'] }} @endif
                    @if(!empty($settings['company_nis']))<strong>NIS:</strong> {{ $settings['company_nis'] }}@endif
                </div>
                @if(!empty($settings['company_rib']))
                <div style="font-size: 8px; color: #333;">
                    <strong>RIB:</strong> {{ $settings['company_rib'] }}
                </div>
                @endif
            </td>
            <td style="width: 4%;"></td>
            <td style="vertical-align: top; text-align: right; width: 48%;">
                <div style="font-size: 12px;">
                    <strong>Date:</strong> {{ $sale->date ? \Carbon\Carbon::parse($sale->date)->format('d/m/Y') : '-' }}<br>
                    <strong>Heure:</strong> {{ now()->format('H:i') }}<br>
                    <strong>Entrepot:</strong> {{ $sale->warehouse->name ?? '-' }}
                </div>
            </td>
        </tr>
    </table>

    <!-- BL Title -->
    <div class="bl-title">
        <h1>BON DE LIVRAISON</h1>
        <div class="bl-ref">N° {{ $sale->reference ?? 'N/A' }}</div>
    </div>

    <!-- Client and Delivery Info -->
    <table style="width: 100%; margin-bottom: 15px;">
        <tr>
            <td style="width: 48%; vertical-align: top;">
                <div class="info-box">
                    <h3>DESTINATAIRE</h3>
                    @if($sale->client)
                        <div class="info-row"><span class="info-label">Nom:</span> {{ ArabicHelper::safe($sale->client->name ?? null, '-') }}</div>
                        <div class="info-row"><span class="info-label">Tel:</span> {{ $sale->client->phone ?? '-' }}</div>
                        <div class="info-row"><span class="info-label">Adresse:</span> {{ ArabicHelper::safe($sale->client->address ?? null, '-') }}</div>
                    @else
                        <div class="info-row"><span class="info-label">Client:</span> Comptoir</div>
                    @endif
                </div>
            </td>
            <td style="width: 4%;"></td>
            <td style="width: 48%; vertical-align: top;">
                <div class="info-box">
                    <h3>LIVRAISON</h3>
                    <div class="info-row"><span class="info-label">Ref Facture:</span> {{ $sale->reference ?? '-' }}</div>
                    <div class="info-row"><span class="info-label">Vendeur:</span> {{ $sale->user->name ?? '-' }}</div>
                    <div class="info-row"><span class="info-label">Nb Articles:</span> {{ $sale->items ? $sale->items->count() : 0 }}</div>
                </div>
            </td>
        </tr>
    </table>

    <!-- Products Table -->
    <table class="products">
        <thead>
            <tr>
                <th style="width: 5%;">N°</th>
                <th style="width: 29%;">Designation</th>
                <th style="width: 8%;">Qte</th>
                <th style="width: 10%;">Unite (Pcs)</th>
                <th style="width: 12%;">P.U</th>
                <th style="width: 16%;">Montant</th>
                <th style="width: 6%;">Ctrl</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalQty = 0;
                $calculatedTotal = 0;
            @endphp
            @foreach($sale->items ?? [] as $index => $item)
            @php
                $product = $item->product ?? null;
                $productName = $product->name ?? 'Produit';
                $unitSale = $product->unitSale ?? null;
                $unitName = $unitSale->short_name ?? 'U';
                $piecesPerPkg = $product->pieces_per_package ?? 1;
                $qty = $item->quantity ?? 0;
                $totalQty += $qty;
                $unitPrice = $item->unit_price ?? 0;
                $itemDiscount = $item->discount ?? 0;
                // Use stored subtotal (includes pieces_per_package)
                $lineTotal = $item->subtotal ?? (($unitPrice * $piecesPerPkg * $qty) - $itemDiscount);
                $calculatedTotal += $lineTotal;
            @endphp
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td class="text-left">
                    <strong>{{ ArabicHelper::safe($productName, 'Produit') }}</strong>
                </td>
                <td class="qty-cell">{{ number_format($qty, $qty == floor($qty) ? 0 : 2) }}</td>
                <td class="text-center">
                    {{ $unitName }}
                    @if($piecesPerPkg > 1)
                    <br><span class="unit-info">({{ $piecesPerPkg }} pcs)</span>
                    @endif
                </td>
                <td class="text-right">{{ number_format($unitPrice, 2) }}</td>
                <td class="text-right"><strong>{{ number_format($lineTotal, 2) }}</strong></td>
                <td class="text-center"><div class="checkbox"></div></td>
            </tr>
            @endforeach
            <!-- Totals Row -->
            <tr class="total-row">
                <td colspan="2" class="text-right"><strong>TOTAL</strong></td>
                <td class="text-center"><strong>{{ number_format($totalQty, $totalQty == floor($totalQty) ? 0 : 2) }}</strong></td>
                <td class="text-center">-</td>
                <td class="text-center">-</td>
                <td class="text-right"><strong>{{ number_format($calculatedTotal, 2) }}</strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    @php
        $saleDiscount = $sale->discount ?? 0;
        $saleShipping = $sale->shipping ?? 0;
        $grandTotal = $calculatedTotal - $saleDiscount + $saleShipping;
    @endphp

    <!-- Totals Box -->
    <table style="width: 220px; margin-left: auto; border: 1px solid #000; margin-bottom: 15px;">
        <tr>
            <td style="padding: 5px; text-align: left; border-bottom: 1px solid #ddd;"><strong>Total HT:</strong></td>
            <td style="padding: 5px; text-align: right; border-bottom: 1px solid #ddd;">{{ number_format($calculatedTotal, 2) }} DA</td>
        </tr>
        @if($saleDiscount > 0)
        <tr>
            <td style="padding: 5px; text-align: left; border-bottom: 1px solid #ddd;">Remise:</td>
            <td style="padding: 5px; text-align: right; border-bottom: 1px solid #ddd; color: red;">- {{ number_format($saleDiscount, 2) }} DA</td>
        </tr>
        @endif
        @if($saleShipping > 0)
        <tr>
            <td style="padding: 5px; text-align: left; border-bottom: 1px solid #ddd;">Livraison:</td>
            <td style="padding: 5px; text-align: right; border-bottom: 1px solid #ddd;">{{ number_format($saleShipping, 2) }} DA</td>
        </tr>
        @endif
        <tr style="background: #333; color: #fff; font-weight: bold;">
            <td style="padding: 8px; text-align: left;">TOTAL:</td>
            <td style="padding: 8px; text-align: right;">{{ number_format($grandTotal, 2) }} DA</td>
        </tr>
    </table>

    <!-- Check Section -->
    <div class="check-section">
        <h4>Verification a la Livraison:</h4>
        <div class="check-row"><span class="checkbox"></span> Marchandise conforme a la commande</div>
        <div class="check-row"><span class="checkbox"></span> Quantites verifiees et correctes</div>
        <div class="check-row"><span class="checkbox"></span> Etat de la marchandise: Bon / Endommage</div>
        <div class="check-row"><span class="checkbox"></span> Aucune reserve</div>
    </div>

    <!-- Observations -->
    <div class="info-box">
        <h3>OBSERVATIONS / RESERVES</h3>
        <div style="height: 50px;">
            @if(!empty($sale->note))
                {{ ArabicHelper::safe($sale->note, '') }}
            @endif
        </div>
    </div>

    <!-- Signatures -->
    <table style="width: 100%; margin-top: 20px;">
        <tr>
            <td style="width: 30%; text-align: center; vertical-align: top;">
                <div style="border: 1px solid #000; padding: 10px; min-height: 60px;">
                    <strong>Prepare par:</strong><br><br><br>
                    Signature Magasinier
                </div>
            </td>
            <td style="width: 5%;"></td>
            <td style="width: 30%; text-align: center; vertical-align: top;">
                <div style="border: 1px solid #000; padding: 10px; min-height: 60px;">
                    <strong>Livre par:</strong><br><br><br>
                    Signature Livreur
                </div>
            </td>
            <td style="width: 5%;"></td>
            <td style="width: 30%; text-align: center; vertical-align: top;">
                <div style="border: 1px solid #000; padding: 10px; min-height: 60px;">
                    <strong>Recu par:</strong><br><br><br>
                    Signature Client
                </div>
            </td>
        </tr>
    </table>

    <!-- Footer -->
    <div class="footer-info">
        Bon de Livraison genere le {{ now()->format('d/m/Y H:i') }} | Ce document doit accompagner la marchandise
    </div>
</body>
</html>
