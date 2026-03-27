@php
use App\Helpers\ArabicHelper;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Etat de compte - {{ ArabicHelper::safe($client->name ?? '', 'Client') }}</title>
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

        /* Header */
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
        .legal-info { font-size: 7px; color: #333; }

        /* Client box */
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

        /* Title */
        .statement-title {
            text-align: center;
            background: #000;
            color: #fff;
            padding: 6px;
            margin: 8px 0;
        }
        .statement-title h1 { font-size: 16px; margin: 0; }
        .statement-title .sub { font-size: 10px; margin-top: 2px; }

        /* Period */
        .filter-box {
            border: 1px solid #999;
            padding: 5px 10px;
            margin-bottom: 8px;
            font-size: 8px;
            background: #f5f5f5;
        }

        /* Summary */
        .summary-table { margin-bottom: 8px; }
        .summary-table td { padding: 0 2px; }
        .summary-box {
            border: 1px solid #000;
            text-align: center;
            padding: 4px;
            font-size: 8px;
        }
        .summary-box .label { font-size: 7px; color: #555; }
        .summary-box .value { font-size: 11px; font-weight: bold; }
        .summary-box.dark { background: #333; color: #fff; }
        .summary-box.dark .label { color: #ccc; }

        /* Operations table */
        table.ops th {
            background: #333;
            color: #fff;
            padding: 4px 3px;
            font-size: 8px;
            text-align: center;
            border: 1px solid #000;
        }
        table.ops td {
            padding: 3px;
            font-size: 8px;
            border: 1px solid #000;
            text-align: center;
        }
        .totals-row { background: #eee; font-weight: bold; }
        .opening-row { background: #f5f5f5; font-style: italic; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        .text-green { color: #008000; }
        .text-red { color: #cc0000; }

        /* Legal section */
        .legal-section {
            border: 1px solid #ccc;
            padding: 4px 8px;
            margin-bottom: 8px;
            font-size: 7px;
            color: #333;
        }

        /* Footer */
        .footer {
            text-align: center;
            font-size: 7px;
            color: #666;
            margin-top: 12px;
            border-top: 1px dashed #000;
            padding-top: 5px;
        }
    </style>
</head>
<body>
    <!-- Header: Company + Client -->
    <table class="header-table">
        <tr>
            <td style="width: 50%;">
                <div class="company-box">
                    @if(!empty($settings['company_logo']) && extension_loaded('gd'))
                    @php $logoPath = storage_path('app/public/' . $settings['company_logo']); @endphp
                    @if(file_exists($logoPath))
                    <div style="margin-bottom: 6px;">
                        <img src="{{ $logoPath }}" style="max-height: 50px; max-width: 120px;" alt="Logo">
                    </div>
                    @endif
                    @endif
                    <div class="company-name">{{ ArabicHelper::safe($settings['company_name'] ?? null, 'ENTREPRISE') }}</div>
                    @if(!empty($settings['company_address']))
                    <div>{{ ArabicHelper::safe($settings['company_address'] ?? null, '') }}</div>
                    @endif
                    @if(!empty($settings['company_phone']))
                    <div>Tel: {{ $settings['company_phone'] }}</div>
                    @endif
                    @if(!empty($settings['company_email']))
                    <div>Email: {{ $settings['company_email'] }}</div>
                    @endif
                    @if(!empty($settings['company_rc']) || !empty($settings['company_nif']))
                    <div class="legal-info" style="margin-top: 4px;">
                        @if(!empty($settings['company_rc']))<strong>RC:</strong> {{ $settings['company_rc'] }} @endif
                        @if(!empty($settings['company_nif']))<strong>NIF:</strong> {{ $settings['company_nif'] }}@endif
                    </div>
                    @endif
                    @if(!empty($settings['company_ai']) || !empty($settings['company_nis']))
                    <div class="legal-info">
                        @if(!empty($settings['company_ai']))<strong>AI:</strong> {{ $settings['company_ai'] }} @endif
                        @if(!empty($settings['company_nis']))<strong>NIS:</strong> {{ $settings['company_nis'] }}@endif
                    </div>
                    @endif
                    @if(!empty($settings['company_rib']))
                    <div class="legal-info"><strong>RIB:</strong> {{ $settings['company_rib'] }}</div>
                    @endif
                </div>
            </td>
            <td style="width: 50%; padding-left: 10px;">
                @if($includeSections['include_info'])
                <div class="info-box">
                    <h3>CLIENT</h3>
                    <strong style="font-size: 10px;">{{ ArabicHelper::safe($client->name ?? '', 'Client') }}</strong><br>
                    @if(!empty($client->phone))
                    Tel: {{ $client->phone }}<br>
                    @endif
                    @if(!empty($client->address))
                    Adresse: {{ ArabicHelper::safe($client->address ?? '', '-') }}<br>
                    @endif
                    @if(!empty($client->clientCategory))
                    Categorie: {{ ArabicHelper::safe($client->clientCategory->name ?? '', '-') }}
                    @endif
                </div>
                @endif
                <div style="font-size: 9px; margin-top: 6px;">
                    <strong>Date:</strong> {{ now()->format('d/m/Y H:i') }}
                </div>
            </td>
        </tr>
    </table>

    <!-- Title -->
    <div class="statement-title">
        <h1>{{ ArabicHelper::safe('كشف حساب العميل', 'ETAT DE COMPTE CLIENT') }}</h1>
    </div>

    <!-- Client Legal Info -->
    @if($includeSections['include_legal'] && (!empty($client->rc) || !empty($client->nif) || !empty($client->ai) || !empty($client->nis) || !empty($client->rib)))
    <div class="legal-section">
        <strong>Informations legales du client:</strong>
        @if(!empty($client->rc))<strong>RC:</strong> {{ $client->rc }} &nbsp; @endif
        @if(!empty($client->nif))<strong>NIF:</strong> {{ $client->nif }} &nbsp; @endif
        @if(!empty($client->ai))<strong>AI:</strong> {{ $client->ai }} &nbsp; @endif
        @if(!empty($client->nis))<strong>NIS:</strong> {{ $client->nis }} &nbsp; @endif
        @if(!empty($client->rib))| <strong>RIB:</strong> {{ $client->rib }}@endif
    </div>
    @endif

    <!-- Period -->
    <div class="filter-box">
        <strong>Periode:</strong>
        Du <strong>{{ \Carbon\Carbon::parse($dateFrom)->format('d/m/Y') }}</strong>
        Au <strong>{{ \Carbon\Carbon::parse($dateTo)->format('d/m/Y') }}</strong>
    </div>

    <!-- Summary -->
    <table class="summary-table">
        <tr>
            <td style="width: 25%;">
                <div class="summary-box">
                    <div class="label">Solde ouverture</div>
                    <div class="value">{{ number_format($openingBalance, 2) }}</div>
                </div>
            </td>
            <td style="width: 25%;">
                <div class="summary-box">
                    <div class="label">Total factures</div>
                    <div class="value" style="color: #cc0000;">{{ number_format($totalSomme, 2) }}</div>
                </div>
            </td>
            <td style="width: 25%;">
                <div class="summary-box">
                    <div class="label">Total versements</div>
                    <div class="value" style="color: #008000;">{{ number_format($totalVersement, 2) }}</div>
                </div>
            </td>
            <td style="width: 25%;">
                <div class="summary-box dark">
                    <div class="label">Solde final</div>
                    <div class="value">{{ number_format($closingBalance, 2) }}</div>
                </div>
            </td>
        </tr>
    </table>

    <!-- Operations Table -->
    <table class="ops">
        <thead>
            <tr>
                <th style="width: 4%;">N°</th>
                <th style="width: 18%;">Code</th>
                <th style="width: 12%;">Date</th>
                <th style="width: 14%;">Type</th>
                <th style="width: 17%;">Debit (DA)</th>
                <th style="width: 17%;">Credit (DA)</th>
                <th style="width: 18%;">Solde (DA)</th>
            </tr>
        </thead>
        <tbody>
            <!-- Opening balance -->
            <tr class="opening-row">
                <td></td>
                <td colspan="3" style="text-align: left; font-weight: bold;">Solde d'ouverture</td>
                <td></td>
                <td></td>
                <td style="font-weight: bold;">{{ number_format($openingBalance, 2) }}</td>
            </tr>
            @foreach($operations as $index => $op)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td class="text-left" style="font-size: 7px;">{{ $op['code'] }}</td>
                <td>{{ $op['date'] ? \Carbon\Carbon::parse($op['date'])->format('d/m/Y') : '-' }}</td>
                <td>
                    @if($op['type'] === 'facture')
                        <span style="color: #cc0000;">Facture</span>
                    @else
                        <span style="color: #008000;">Versement</span>
                    @endif
                </td>
                <td>{{ $op['somme'] > 0 ? number_format($op['somme'], 2) : '-' }}</td>
                <td class="{{ $op['versement'] > 0 ? 'text-green' : '' }}">{{ $op['versement'] > 0 ? number_format($op['versement'], 2) : '-' }}</td>
                <td style="font-weight: bold;" class="{{ $op['credit'] > 0 ? 'text-red' : 'text-green' }}">{{ number_format($op['credit'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="totals-row">
                <td colspan="4" style="text-align: left; font-size: 9px;">TOTAL</td>
                <td style="font-size: 9px;">{{ number_format($totalSomme, 2) }}</td>
                <td class="text-green" style="font-size: 9px;">{{ number_format($totalVersement, 2) }}</td>
                <td style="font-size: 10px;" class="{{ $closingBalance > 0 ? 'text-red' : 'text-green' }}">{{ number_format($closingBalance, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    @if(count($operations) === 0)
    <div style="text-align: center; padding: 15px; font-size: 9px; color: #666; border: 1px dashed #ccc; margin-top: 5px;">
        Aucune operation dans cette periode
    </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        @if(!empty($settings['company_name']))
        <strong>{{ ArabicHelper::safe($settings['company_name'] ?? null, '') }}</strong>
        @if(!empty($settings['company_address'])) | {{ $settings['company_address'] ?? '' }}@endif
        @if(!empty($settings['company_phone'])) | Tel: {{ $settings['company_phone'] }}@endif
        <br>
        @endif
        Etat de compte genere le {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
