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
                <div style="margin-bottom: 6px;">
                    @if (isset($facturas[0]) && $facturas[0]->detallefc->razonSocial->id_razon == 1)
                        <img src="{{ storage_path('app/public/images/alba.png') }}" width="90px">
                    @elseif (isset($facturas[0]) && $facturas[0]->detallefc->razonSocial?->id_razon == 2)
                        <img src="{{ storage_path('app/public/images/bon_baja.jpeg') }}" width="75px">
                    @elseif (isset($facturas[0]) && $facturas[0]->detallefc->razonSocial?->id_razon == 3)
                        <img src="{{ storage_path('app/public/images/alba.png') }}" width="90px">
                    @elseif (isset($facturas[0]) && $facturas[0]->detallefc->razonSocial?->id_razon == 5)
                        <img src="{{ storage_path('app/public/images/alba.jpeg') }}" width="90px">
                    @elseif (isset($facturas[0]) && $facturas[0]->detallefc->razonSocial?->id_razon == 6)
                        <img src="{{ storage_path('app/public/images/bene_baja.jpeg') }}" width="90px">
                    @else
                        <img src="{{ storage_path('app/public/images/sembrar_baja.jpeg') }}" width="90px">
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

    {{-- Sello de version del comprobante: bien visible en el encabezado, no perdido adentro de
         una card chica. Ambar mientras falta emitir/numerar; verde cuando ya es la version
         definitiva para el proveedor. --}}
    @if (!empty($version_comprobante))
        <table style="margin-bottom: 10px;">
            <tr>
                <td style="background-color: {{ $version_comprobante === 'COMPROBANTE DEFINITIVO' ? '#388E3C' : '#b45309' }};
                           color: #ffffff; text-align: center; padding: 7px 10px; font-weight: 800;
                           font-size: 13px; letter-spacing: 0.6px; border-radius: 4px;">
                    {{ $version_comprobante }}
                </td>
            </tr>
        </table>
    @endif

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

    <!-- Main Payment Data -->
    <table style="margin-bottom: 10px;">
        <tr>
            <!-- Columna Izquierda: Aplicado a -->
            <td width="48.5%" style="vertical-align: top; padding: 0;">
                <div class="card" style="border-top: 3px solid #0f172a; margin-bottom: 0;">
                    <div class="card-header" style="background-color: #f8fafc;">
                        Aplicado a (Comprobantes)
                    </div>
                    @php
                        $countLeft = is_iterable($facturas) ? count($facturas) : 0;
                        $countRight = 0;
                        if (is_iterable($pagos)) {
                            foreach ($pagos as $pagoItem) {
                                if (isset($pagoItem->pagosParciales) && is_iterable($pagoItem->pagosParciales)) {
                                    $countRight += count($pagoItem->pagosParciales) * 2;
                                }
                                $countRight += 3;
                            }
                        }
                        $maxFilasTarget = max($countLeft, $countRight, 8);
                    @endphp
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th width="50%">Detalle</th>
                                <th width="20%">Facturas</th>
                                <th width="30%">Importe</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $totalFilas = 0;
                            @endphp
                            @foreach ($facturas as $item)
                            <tr>
                                <td style="font-size: 9px;">
                                    <strong class="text-dark">FAC{{ $item?->detallefc?->tipo_letra }} {{ $item?->detallefc?->sucursal }}-{{ str_pad($item?->detallefc?->numero, 8, '0', STR_PAD_LEFT) }}</strong><br>
                                    <span style="color: #64748b;">(LIQ Nº {{ $item?->detallefc?->num_liquidacion }})</span>
                                    @if(($item?->detallefc?->total_debitado_liquidacion ?? 0) > 0)
                                        <div style="color: #dc2626; font-size: 8.5px; margin-top: 2px;">
                                            <span class="font-bold">Débito:</span> ${{ number_format($item->detallefc->total_debitado_liquidacion, 2, ',', '.') }}
                                        </div>
                                    @endif
                                </td>
                                <td class="text-center font-bold">{{ $loop->iteration }}</td>
                                <td class="text-right font-bold text-dark">${{ number_format($item?->detallefc?->total_neto ?? 0, 2, ',', '.') }}</td>
                            </tr>
                            @php $totalFilas++; @endphp
                            @endforeach

                            @while ($totalFilas < $maxFilasTarget)
                            <tr><td style="height: 18px;"></td><td></td><td></td></tr>
                            @php $totalFilas++; @endphp
                            @endwhile

                            <tr class="total-row">
                                <td colspan="2" class="text-right text-red">Débito:</td>
                                <td class="text-right text-red">${{ number_format($debito ?? 0, 2, ',', '.') }}</td>
                            </tr>
                            {{-- El total sale de las facturas imputadas (monto_pagable), no de
                                 `cabecera - debito`: la cabecera puede estar desincronizada con lo
                                 imputado y entonces el total no cerraba ni con las filas de arriba
                                 ni con lo que el sistema deja pagar. --}}
                            <tr class="total-final-row">
                                <td colspan="2" class="text-right">Total a Pagar:</td>
                                <td class="text-right">${{ number_format($monto_pagable ?? (($total ?? 0) - ($debito ?? 0)), 2, ',', '.') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </td>

            <td width="3%"></td>

            <!-- Columna Derecha: Valores -->
            <td width="48.5%" style="vertical-align: top; padding: 0;">
                <div class="card" style="border-top: 3px solid #388E3C; margin-bottom: 0;">
                    <div class="card-header" style="background-color: #f8fafc;">
                        Valores Entregados
                    </div>
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th width="50%">Detalle</th>
                                <th width="20%">Cuota</th>
                                <th width="30%">Importe</th>
                        </thead>
                        <tbody>
                            @php
                                $totalFilas2 = 0;
                            @endphp

                            {{-- Instrumentos del circuito nuevo (eCheq). Cuando el numero
                                 todavia no se cargo, sale una linea en blanco para completar a
                                 mano: es la version que va a Tesoreria para emitir. --}}
                            {{-- Fechas ya planificadas (Confirmar OPA) pero todavia sin emitir: no
                                 tienen monto ni forma de pago porque eso se define recien al
                                 emitir cada una, en Carga de eCheq. --}}
                            @foreach ($fechas_pendientes ?? [] as $fecha)
                                <tr>
                                    <td class="font-bold text-dark" style="font-size: 9px;">
                                        Pendiente de emitir
                                        <br>
                                        <div style="margin-top: 3px;">
                                            <span class="font-bold" style="font-size: 8px;">Fecha de Pago:</span>
                                            <span class="text-blue" style="font-size: 8px;">{{ $fecha->fecha_probable_pago }}</span>
                                        </div>
                                    </td>
                                    <td class="text-center font-bold">{{ $fecha->orden_cuotas }}</td>
                                    <td class="text-right font-bold text-dark">
                                        <span style="display: inline-block; min-width: 40px;
                                                     border-bottom: 1px solid #94a3b8;">&nbsp;</span>
                                    </td>
                                </tr>
                                @php $totalFilas2++; @endphp
                            @endforeach

                            @foreach ($instrumentos ?? [] as $inst)
                                <tr>
                                    <td class="font-bold text-dark" style="font-size: 9px;">
                                        {{ $inst?->formaPago?->tipo_pago ?? 'eCheq' }}:
                                        @if (trim((string) $inst?->numero_echeq) !== '')
                                            {{ $inst->numero_echeq }}
                                        @else
                                            <span style="display: inline-block; min-width: 90px;
                                                         border-bottom: 1px solid #94a3b8;">&nbsp;</span>
                                        @endif
                                        <br>
                                        <span style="font-weight: normal; font-size: 8px; color: #64748b;">
                                            {{ $inst?->bancoEmisor?->descripcion_banco }}
                                        </span><br>
                                        <div style="margin-top: 3px;">
                                            <span class="font-bold" style="font-size: 8px;">Fecha de Pago:</span>
                                            <span class="text-blue" style="font-size: 8px;">{{ $inst?->fecha_emision_echeq }}</span>
                                            @if ($inst?->estadoInstrumento)
                                                <span style="font-size: 8px; color: #64748b;">
                                                    &middot; {{ $inst->estadoInstrumento->descripcion_estado }}
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-center font-bold">{{ $loop->iteration }}</td>
                                    <td class="text-right font-bold text-dark">${{ number_format($inst?->monto_pago ?? 0, 2, ',', '.') }}</td>
                                </tr>
                                @php $totalFilas2++; @endphp
                            @endforeach

                            @foreach ($pagos as $item)
                                {{-- Los pagosParciales del circuito nuevo (con id_estado_instrumento)
                                     ya se listaron arriba en $instrumentos. Sin este filtro, un
                                     eCheq salia dos veces: una vez por cada loop. Este bloque
                                     queda solo para los pagosParciales VIEJOS, que no tienen
                                     estado de instrumento. --}}
                                @foreach ($item->pagosParciales->whereNull('id_estado_instrumento') as $pagosP)
                                <tr>
                                    <td class="font-bold text-dark" style="font-size: 9px;">
                                       {{ $pagosP?->formaPago?->tipo_pago }}{{ $pagosP?->num_cheque ? ': '.$pagosP->num_cheque : '' }}<br>
                                        <span style="font-weight: normal; font-size: 8px; color: #64748b;">{{ $item->cuenta?->nombre_cuenta }}</span><br>
                                        <div style="margin-top: 3px;">
                                            <span class="font-bold" style="font-size: 8px;">Fecha de Pago:</span> 
                                            <span class="text-blue" style="font-size: 8px;">{{ $pagosP?->fecha_confirma_pago }}</span>
                                        </div>
                                    </td>
                                    <td class="text-center font-bold">{{ $loop->iteration }}</td>
                                    <td class="text-right font-bold text-dark">${{ number_format($pagosP?->monto_pago ?? 0, 2, ',', '.') }}</td>
                                </tr>
                                @php $totalFilas2++; @endphp
                                @endforeach


                                @if ($item?->id_forma_pago == 1)
                                <tr>
                                    <td colspan="3" style="background-color: #f8fafc; padding: 6px;">
                                        <div class="font-bold text-dark" style="font-size: 9px;">DESTINATARIO (DEPÓSITO/TRANSF)</div>
                                        <div style="font-size: 9px; color: #475569; margin-top: 2px;">
                                            CUIT: {{ $cuit_proveedor }} <br>
                                            CBU: {{ $cbu_proveedor }}
                                        </div>
                                    </td>
                                </tr>
                                @php $totalFilas2++; @endphp
                                @else
                                    @for ($i = 0; $i < 3; $i++)
                                    <tr><td colspan="3" style="height: 18px;"></td></tr>
                                    @endfor
                                    @php $totalFilas2 += 3; @endphp
                                @endif
                            @endforeach

                            @while ($totalFilas2 < $maxFilasTarget)
                            <tr><td style="height: 18px;"></td><td></td><td></td></tr>
                            @php $totalFilas2++; @endphp
                            @endwhile

                            {{-- Esta columna lista los valores entregados, asi que cierra con la
                                 SUMA DE ESAS FILAS y con lo que queda. Antes repetia el "total a
                                 pagar" de la columna de facturas: debajo de una lista de pagos por
                                 $70.000 decia $78.960, que no era la suma de nada de lo de arriba.
                                 Y arrastraba una fila de "Débito", que es una deduccion de la
                                 factura y ya figura en la columna izquierda. (2026-09-10) --}}
                            <tr class="total-row">
                                <td colspan="2" class="text-right">Entregado:</td>
                                <td class="text-right">${{ number_format($total_entregado ?? 0, 2, ',', '.') }}</td>
                            </tr>
                            <tr class="total-final-row" style="background-color: #065933;">
                                <td colspan="2" class="text-right" style="background-color: #065933;">Restante:</td>
                                <td class="text-right" style="background-color: #065933;">${{ number_format($total_restante ?? 0, 2, ',', '.') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </td>
        </tr>
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