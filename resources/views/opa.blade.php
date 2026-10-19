<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Orden de Pago</title>
</head>

<body>
    <h2>Orden de Pago #{{ $datos['comprobante_nro'] }}</h2>
    <p><strong>Fecha de Emisión:</strong> {{ $datos['fecha_emision'] }}</p>
    <p><strong>Proveedor:</strong> {{ $datos['nombre_proveedor'] }}</p>
    <p><strong>Total:</strong> ${{ number_format($datos['total'], 2) }}</p>
    @if (!empty($datos['aplicaciones_anticipo']))
        <p><strong>Anticipo aplicado a:</strong></p>
        <ul>
            @foreach ($datos['aplicaciones_anticipo'] as $ap)
                @php $fc = $ap['detalle']?->detallefc; @endphp
                <li>FAC{{ $fc?->tipo_letra }} {{ $fc?->sucursal }}-{{ str_pad($fc?->numero, 8, '0', STR_PAD_LEFT) }}:
                    ${{ number_format($ap['monto'], 2) }} (resta cubrir ${{ number_format($ap['resta'], 2) }})</li>
            @endforeach
        </ul>
    @endif
    @if (!empty($datos['anticipos_aplicados']))
        <p><strong>Anticipos ya aplicados a estas facturas:</strong></p>
        <ul>
            @foreach ($datos['anticipos_aplicados'] as $ap)
                <li>{{ $ap['num_anticipo'] }}: ${{ number_format($ap['monto'], 2) }}</li>
            @endforeach
        </ul>
    @endif
    {{-- <p><strong>Observaciones:</strong> {{ $datos['observaciones'] }}</p> --}}
    <p>Adjunto encontrarás el comprobante en formato PDF.</p>
</body>

</html>