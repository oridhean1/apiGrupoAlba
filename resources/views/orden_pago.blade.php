<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Orden de Pago - {{ $comprobante_nro }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            margin: 0;
            color: #334155;
            font-size: 10px;
            background-color: #ffffff;
        }

        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 2px 4px; }
        
        .header-container {
            background-color: #f8fafc;
            border-bottom: 3px solid #388E3C;
            padding: 8px 15px;
            margin-bottom: 10px;
        }

        .text-blue { color: #388E3C; }
        .text-dark { color: #0f172a; }
        .text-white { color: #ffffff; }
        .text-red { color: #dc2626; }
        
        .font-bold { font-weight: bold; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        
        .badge {
            background-color: #388E3C;
            color: #ffffff;
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 10px;
            letter-spacing: 0.5px;
        }

        .doc-type {
            border: 2px solid #388E3C;
            color: #388E3C;
            width: 35px;
            height: 35px;
            margin: 0 auto;
            text-align: center;
            line-height: 35px;
            font-size: 24px;
            font-weight: 800;
            background-color: #ffffff;
        }

        .card {
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            margin-bottom: 10px;
            overflow: hidden;
        }

        .card-header {
            background-color: #f1f5f9;
            padding: 5px 10px;
            border-bottom: 1px solid #cbd5e1;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
            font-size: 9px;
            letter-spacing: 0.5px;
        }

        .card-body {
            padding: 5px 10px;
        }

        .info-grid {
            width: 100%;
        }
        .info-grid td {
            vertical-align: top;
            padding: 4px 8px 4px 0;
            line-height: 1.3;
            border-bottom: 1px dashed #e2e8f0;
        }
        .info-grid tr:last-child td {
            border-bottom: none;
        }

        .modern-table {
            border: none;
            margin-bottom: 0;
            width: 100%;
        }
        .modern-table th {
            background-color: #388E3C;
            color: #ffffff;
            text-transform: uppercase;
            font-size: 9px;
            padding: 6px 4px;
            text-align: center;
            border-top: none;
            border-bottom: 2px solid #388E3C;
        }
        .modern-table td {
            padding: 5px;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        .modern-table td:last-child {
            border-right: none;
        }

        .modern-table tr.total-row td {
            background-color: #f8fafc;
            font-weight: bold;
            color: #0f172a;
            font-size: 11px;
            text-transform: uppercase;
            border-top: 2px solid #cbd5e1;
            border-bottom: none;
            padding: 6px 5px;
        }

        .modern-table tr.total-final-row td {
            background-color: #0f172a;
            color: #ffffff;
            font-weight: bold;
            font-size: 12px;
            padding: 8px 5px;
            border: none;
        }

        /* Tabla unica Comprobantes | Valores: dompdf corta por fila y repite el thead */
        .op-table { margin-bottom: 10px; }
        .op-table tr { page-break-inside: avoid; }
        .op-table tr.op-card-title th {
            background-color: #f8fafc;
            color: #0f172a;
            text-align: left;
            padding: 5px 10px;
            border-top: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
        }
        .op-table .op-l1 { border-left: 1px solid #cbd5e1; }
        .op-table td.op-l3 { border-right: 1px solid #cbd5e1; }
        .op-table th.op-l3 { border-right: 1px solid #cbd5e1; }
        .modern-table tr th.sep,
        .modern-table tr td.sep,
        .modern-table tr.op-card-title th.sep,
        .modern-table tr.total-row td.sep,
        .modern-table tr.total-final-row td.sep {
            width: 3%;
            background-color: #ffffff;
            border: none;
            padding: 0;
        }

        .debit-amount {
            color: #dc2626;
            font-weight: bold;
        }
    </style>
</head>

<body>

    <!-- Modern Header -->
    <table class="header-container">
        <tr>
            <td width="40%" style="vertical-align: middle;">
                @php
                    $empresaRazonSocial = strtoupper(config('app.empresa_razon_social'));
                    $isOsv = str_contains($empresaRazonSocial, 'VAREADORES') || str_contains($empresaRazonSocial, 'OSV');
                    $idRazon = isset($facturas[0]) ? $facturas[0]?->detallefc?->razonSocial?->id_razon : null;
                    [$logoArchivo, $logoAncho] = match (true) {
                        $isOsv => ['osvsalud.png', '90px'],
                        $idRazon == 1, $idRazon == 3 => ['alba.png', '90px'],
                        $idRazon == 2 => ['bon_baja.jpeg', '75px'],
                        $idRazon == 5 => ['alba.jpeg', '90px'],
                        $idRazon == 6 => ['bene_baja.jpeg', '90px'],
                        default => ['sembrar_baja.jpeg', '90px'],
                    };
                    $logoPath = storage_path('app/public/images/' . $logoArchivo);
                @endphp
                <div style="margin-bottom: 6px;">
                    @if (file_exists($logoPath))
                        <img src="{{ $logoPath }}" width="{{ $logoAncho }}">
                    @endif
                </div>
                <div class="font-bold text-dark" style="font-size: 12px; margin-bottom: 2px;">
                    {{ $facturas[0]?->detallefc->razonSocial?->razon_social ?? 'Empresa' }}
                </div>
                <div style="color: #64748b; font-size: 9px;">
                    IVA: {{ $facturas[0]?->detallefc->razonSocial?->iva }} <br>
                    Domicilio: {{ $facturas[0]?->detallefc->razonSocial?->domicilio }}
                </div>
            </td>

            <td width="20%" style="text-align: center; vertical-align: middle;">
                <div class="doc-type">O</div>
                <div style="margin-top: 4px; font-weight: bold; color: #475569; font-size: 9px;">
                    COD. {{ substr($comprobante_nro ?? '000', 0, 3) }}
                </div>
            </td>

            <td width="40%" style="text-align: right; vertical-align: middle;">
                <div style="font-size: 17px; font-weight: 800; color: #0f172a; margin-bottom: 8px; letter-spacing: 0.5px;">ORDEN DE PAGO</div>
                <div style="margin-bottom: 8px;">
                    <span class="badge">NRO: {{ substr($comprobante_nro, 4) }}</span>
                </div>
                <div style="color: #475569; line-height: 1.4; font-size: 9px;">
                    <span class="font-bold text-dark">Emisión:</span> {{ $fecha_emision }} <br>
                    <span class="font-bold text-dark">CUIT:</span> {{ $facturas[0]?->detallefc->razonSocial?->cuit }} <br>
                    <span class="font-bold text-dark">Ingresos Brutos:</span> Exento <br>
                    <span class="font-bold text-dark">Inic. Actividades:</span> 01/11/2007
                </div>
            </td>
        </tr>
    </table>

    <!-- Provider Details Card -->
    <div class="card" style="border-left: 3px solid #388E3C;">
        <div class="card-header text-blue">
            Datos del Proveedor / Beneficiario
        </div>
        <div class="card-body">
            <table class="info-grid">
                <tr>
                    <td width="15%" class="font-bold text-dark">Razón Social:</td>
                    <td width="35%" style="color: #334155; font-size: 11px; font-weight: bold;">{{ $nombre_proveedor }}</td>
                    <td width="15%" class="font-bold text-dark">CUIT:</td>
                    <td width="35%" style="color: #334155;">{{ $cuit_proveedor }}</td>
                </tr>
                <tr>
                    <td class="font-bold text-dark">Condición IVA:</td>
                    <td style="color: #334155;">{{ $iva_proveedor }}</td>
                    <td class="font-bold text-dark">Domicilio:</td>
                    <td style="color: #334155;">{{ $domicilio_proveedor }}</td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Main Payment Data: una sola tabla (Comprobantes | Valores) para que dompdf pagine por fila -->
    @php
        $filasIzq = is_iterable($facturas) ? array_values(is_array($facturas) ? $facturas : collect($facturas)->all()) : [];
        $filasDer = [];
        if (is_iterable($pagos)) {
            foreach ($pagos as $pagoItem) {
                if (isset($pagoItem->pagosParciales) && is_iterable($pagoItem->pagosParciales)) {
                    $cuota = 0;
                    foreach ($pagoItem->pagosParciales as $pagosP) {
                        $filasDer[] = ['tipo' => 'pago', 'pago' => $pagoItem, 'parcial' => $pagosP, 'cuota' => ++$cuota];
                    }
                }
                if ($pagoItem?->id_forma_pago == 1) {
                    $filasDer[] = ['tipo' => 'destinatario'];
                }
            }
        }
        $maxFilasTarget = max(count($filasIzq), count($filasDer), 8);
    @endphp
    <table class="modern-table op-table">
        <thead>
            <tr class="op-card-title">
                <th colspan="3" class="op-l1 op-l3" style="border-top: 3px solid #0f172a;">Aplicado a (Comprobantes)</th>
                <th class="sep"></th>
                <th colspan="3" class="op-l1 op-l3" style="border-top: 3px solid #388E3C;">Valores Entregados</th>
            </tr>
            <tr>
                <th width="24%" class="op-l1">Detalle</th>
                <th width="10%">Facturas</th>
                <th width="14.5%" class="op-l3">Importe</th>
                <th class="sep"></th>
                <th width="24%" class="op-l1">Detalle</th>
                <th width="10%">Cuota</th>
                <th width="14.5%" class="op-l3">Importe</th>
            </tr>
        </thead>
        <tbody>
            @for ($i = 0; $i < $maxFilasTarget; $i++)
            @php
                $item = $filasIzq[$i] ?? null;
                $der = $filasDer[$i] ?? null;
            @endphp
            <tr>
                {{-- Comprobantes --}}
                @if ($item)
                <td class="op-l1" style="font-size: 9px;">
                    <strong class="text-dark">FAC{{ $item?->detallefc?->tipo_letra }} {{ $item?->detallefc?->sucursal }}-{{ str_pad($item?->detallefc?->numero, 8, '0', STR_PAD_LEFT) }}</strong><br>
                    <span style="color: #64748b;">(LIQ Nº {{ $item?->detallefc?->num_liquidacion }})</span>
                    @if(($item?->detallefc?->total_debitado_liquidacion ?? 0) > 0)
                        <div style="color: #dc2626; font-size: 8.5px; margin-top: 2px;">
                            <span class="font-bold">Débito:</span> ${{ number_format($item->detallefc->total_debitado_liquidacion, 2, ',', '.') }}
                        </div>
                    @endif
                </td>
                <td class="text-center font-bold">{{ $i + 1 }}</td>
                <td class="op-l3 text-right font-bold text-dark">${{ number_format($item?->detallefc?->total_neto ?? 0, 2, ',', '.') }}</td>
                @else
                <td class="op-l1" style="height: 18px;"></td><td></td><td class="op-l3"></td>
                @endif

                <td class="sep"></td>

                {{-- Valores Entregados --}}
                @if ($der && $der['tipo'] === 'pago')
                @php $pagosP = $der['parcial']; @endphp
                <td class="op-l1 font-bold text-dark" style="font-size: 9px;">
                    {{ $pagosP?->formaPago?->tipo_pago }}{{ $pagosP?->num_cheque ? ': '.$pagosP->num_cheque : '' }}<br>
                    <span style="font-weight: normal; font-size: 8px; color: #64748b;">{{ $der['pago']->cuenta?->nombre_cuenta }}</span><br>
                    <div style="margin-top: 3px;">
                        <span class="font-bold" style="font-size: 8px;">Fecha de Pago:</span>
                        <span class="text-blue" style="font-size: 8px;">{{ $pagosP?->fecha_confirma_pago }}</span>
                    </div>
                </td>
                <td class="text-center font-bold">{{ $der['cuota'] }}</td>
                <td class="op-l3 text-right font-bold text-dark">${{ number_format($pagosP?->monto_pago ?? 0, 2, ',', '.') }}</td>
                @elseif ($der && $der['tipo'] === 'destinatario')
                <td colspan="3" class="op-l1 op-l3" style="background-color: #f8fafc; padding: 6px;">
                    <div class="font-bold text-dark" style="font-size: 9px;">DESTINATARIO (DEPÓSITO/TRANSF)</div>
                    <div style="font-size: 9px; color: #475569; margin-top: 2px;">
                        CUIT: {{ $cuit_proveedor }} <br>
                        CBU: {{ $cbu_proveedor }}
                    </div>
                </td>
                @else
                <td class="op-l1" style="height: 18px;"></td><td></td><td class="op-l3"></td>
                @endif
            </tr>
            @endfor

            <tr class="total-row">
                <td colspan="2" class="op-l1 text-right text-red">Débito:</td>
                <td class="op-l3 text-right text-red">${{ number_format($debito ?? 0, 2, ',', '.') }}</td>
                <td class="sep"></td>
                <td colspan="2" class="op-l1 text-right text-red">Débito:</td>
                <td class="op-l3 text-right text-red">${{ number_format($debito ?? 0, 2, ',', '.') }}</td>
            </tr>
            <tr class="total-final-row">
                <td colspan="2" class="text-right">Total a Pagar:</td>
                <td class="text-right">${{ number_format(($total ?? 0) - ($debito ?? 0), 2, ',', '.') }}</td>
                <td class="sep"></td>
                <td colspan="2" class="text-right" style="background-color: #065933;">Total:</td>
                <td class="text-right" style="background-color: #065933;">${{ number_format(($total ?? 0) - ($debito ?? 0), 2, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <!-- Footer: Observaciones y Detalle de Débitos -->
    <div class="card" style="border-left: 3px solid #388E3C;">
        <div class="card-body" style="padding: 10px;">
            <div class="font-bold text-dark" style="font-size: 11px; margin-bottom: 5px; text-transform: uppercase;">Observaciones:</div>
            <div style="font-size: 10px; color: #334155; margin-bottom: 10px; min-height: 20px;">
                {{ $observaciones ?? 'Sin observaciones adicionales.' }}
            </div>

            <!-- @if(($debito ?? 0) > 0)
                <div style="border-top: 1px dashed #cbd5e1; padding-top: 8px;">
                    <div class="font-bold" style="color: #dc2626; font-size: 9px; margin-bottom: 5px;">DETALLE DE DÉBITOS APLICADOS:</div>
                    <table style="width: 100%;">
                        @foreach ($facturas as $item)
                            @php 
                                $debitoFac = $item?->detallefc?->total_debitado_liquidacion ?? 0; 
                            @endphp
                            @if($debitoFac > 0)
                                <tr>
                                    <td style="padding: 2px 0;">
                                        <span class="font-bold text-dark">Comprobante:</span>
                                        FAC{{ $item?->detallefc?->tipo_letra }} {{ $item?->detallefc?->sucursal }}-{{ str_pad($item?->detallefc?->numero, 8, '0', STR_PAD_LEFT) }}
                                        <span style="color: #64748b; margin-left: 10px;">(LIQ Nº {{ $item?->detallefc?->num_liquidacion }})</span>
                                    </td>
                                    <td style="text-align: right; width: 30%;">
                                        <span class="debit-amount">${{ number_format($debitoFac, 2, ',', '.') }}</span>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </table>
                </div>
            @endif -->
        </div>
    </div>

</body>
</html>