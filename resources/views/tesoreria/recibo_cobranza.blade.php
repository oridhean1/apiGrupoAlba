<!DOCTYPE html>
<html lang="es">
{{-- Recibo de cobro de una orden de cobranza (circuito de ingresos). Formato estándar provisorio:
     se ajusta al modelo de Finanzas cuando lo manden (README 13.2, pregunta 1). --}}
<head>
    <meta charset="UTF-8" />
    <title>Recibo {{ $numero }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; margin: 0; color: #334155; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 3px 5px; }
        .header { background-color: #f8fafc; border-bottom: 3px solid #388E3C; padding: 10px 15px; margin-bottom: 12px; }
        .verde { color: #388E3C; }
        .oscuro { color: #0f172a; }
        .rojo { color: #dc2626; }
        .bold { font-weight: bold; }
        .right { text-align: right; }
        .center { text-align: center; }
        .titulo { font-size: 18px; font-weight: 800; }
        .card { border: 1px solid #cbd5e1; border-radius: 4px; padding: 8px 10px; margin: 0 15px 10px 15px; }
        .seccion { font-size: 11px; font-weight: bold; color: #388E3C; margin: 0 15px 4px 15px; }
        .tabla { margin: 0 15px 10px 15px; width: calc(100% - 30px); }
        .tabla th { background-color: #f1f5f9; border-bottom: 1px solid #cbd5e1; font-size: 9px; text-transform: uppercase; }
        .tabla td { border-bottom: 1px solid #e2e8f0; }
        .total td { font-size: 12px; font-weight: bold; border-top: 2px solid #388E3C; border-bottom: none; }
        .anulado { position: fixed; top: 40%; left: 15%; font-size: 90px; color: rgba(220, 38, 38, 0.18); transform: rotate(-30deg); font-weight: 800; }
        .pie { margin: 25px 15px 0 15px; font-size: 9px; color: #64748b; }
        .firma { margin-top: 45px; width: 40%; border-top: 1px solid #334155; text-align: center; padding-top: 3px; }
    </style>
</head>

<body>
    @if ($anulada)
        <div class="anulado">ANULADO</div>
    @endif

    <div class="header">
        <table>
            <tr>
                <td style="width: 60%">
                    <div class="titulo oscuro">{{ $razon->razon_social }}</div>
                    <div>CUIT {{ $razon->cuit }}</div>
                    @if (!empty($razon->domicilio))<div>{{ $razon->domicilio }}</div>@endif
                    @if (!empty($razon->iva))<div>{{ $razon->iva }}</div>@endif
                </td>
                <td class="right">
                    <div class="titulo verde">RECIBO DE COBRO</div>
                    <div class="bold oscuro" style="font-size: 13px">{{ $numero }}</div>
                    <div>Fecha de cobro: <b>{{ $fecha_cobro }}</b></div>
                </td>
            </tr>
        </table>
    </div>

    <div class="card">
        <table>
            <tr>
                <td style="width: 15%" class="bold">Recibimos de</td>
                <td class="oscuro bold">{{ $cliente->razon_social }}</td>
                <td style="width: 10%" class="bold">CUIT</td>
                <td style="width: 20%">{{ $cliente->cuit }}</td>
            </tr>
            @if (!empty($cliente->condicion_iva))
                <tr><td class="bold">Condición IVA</td><td colspan="3">{{ $cliente->condicion_iva }}</td></tr>
            @endif
            {{-- Detalle libre del cobro (p. ej. "Prestación Junio"): se carga en Observaciones de la orden --}}
            @if (!empty($observaciones))
                <tr><td class="bold">Detalle</td><td colspan="3" class="oscuro bold">{{ $observaciones }}</td></tr>
            @endif
        </table>
    </div>

    <div class="seccion">Valores recibidos</div>
    <table class="tabla">
        <tr><th>Concepto</th><th>Detalle</th><th class="right">Importe</th></tr>
        @foreach ($medios as $m)
            <tr>
                <td>{{ $m->tipo_pago }}</td>
                <td>{{ $m->nombre_cuenta }}{{ $m->referencia ? ' · Ref. ' . $m->referencia : '' }}</td>
                <td class="right">$ {{ number_format($m->importe, 2, ',', '.') }}</td>
            </tr>
        @endforeach
        @foreach ($retenciones as $r)
            <tr>
                <td>Retención Ganancias</td>
                <td>{{ $r->numero_certificado ? 'N° ' . $r->numero_certificado : '' }}</td>
                <td class="right">$ {{ number_format($r->importe, 2, ',', '.') }}</td>
            </tr>
        @endforeach
        @foreach ($anticipos as $a)
            <tr>
                <td>Anticipo aplicado</td>
                <td>{{ 'OC-' . str_pad($a->numero, 6, '0', STR_PAD_LEFT) }} del {{ \Carbon\Carbon::parse($a->fecha_cobro)->format('d/m/Y') }}</td>
                <td class="right">$ {{ number_format($a->importe, 2, ',', '.') }}</td>
            </tr>
        @endforeach
        <tr class="total"><td colspan="2">Total</td><td class="right">$ {{ number_format($totales['total_imputable'], 2, ',', '.') }}</td></tr>
    </table>

    <div class="seccion">Aplicado a</div>
    <table class="tabla">
        <tr><th>Comprobante</th><th>Fecha</th><th class="right">Importe del comprobante</th><th class="right">Aplicado</th></tr>
        @forelse ($imputaciones as $i)
            <tr>
                <td>{{ $i->tipo }} {{ str_pad($i->punto_venta, 5, '0', STR_PAD_LEFT) }}-{{ str_pad($i->numero, 8, '0', STR_PAD_LEFT) }}</td>
                <td>{{ \Carbon\Carbon::parse($i->fecha_emision)->format('d/m/Y') }}</td>
                <td class="right">$ {{ number_format($i->importe_total, 2, ',', '.') }}</td>
                <td class="right">$ {{ number_format($i->importe, 2, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="center">Sin comprobantes: el importe queda a cuenta como anticipo.</td></tr>
        @endforelse
        @if ($totales['excedente'] > 0)
            <tr><td colspan="3" class="bold">A cuenta (anticipo)</td><td class="right bold">$ {{ number_format($totales['excedente'], 2, ',', '.') }}</td></tr>
        @endif
    </table>

    @if ($anulada)
        <div class="card rojo"><b>Anulado</b> el {{ $fecha_anula }}{{ $motivo_anulacion ? ': ' . $motivo_anulacion : '' }}</div>
    @endif

    <div class="pie">
        <div class="firma">Firma y aclaración</div>
        <p>Emitido el {{ $emitido }}. Los valores recibidos quedan sujetos a su acreditación.</p>
    </div>
</body>

</html>
