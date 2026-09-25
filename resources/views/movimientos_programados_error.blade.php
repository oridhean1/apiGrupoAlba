<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Traspasos de Origen con error</title>
</head>

<body style="font-family: Arial, sans-serif; font-size: 14px; color: #333;">
    <p>Los siguientes traspasos de Origen programados no se pudieron aplicar en el proceso mensual.
        Quedaron en estado ERROR y deben revisarse desde el módulo Comercial.</p>
    <table cellpadding="6" cellspacing="0" border="1" style="border-collapse: collapse;">
        <thead>
            <tr style="background: #f2f2f2;">
                <th>DNI</th>
                <th>CUIL titular</th>
                <th>Fecha de alta</th>
                <th>Detalle</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($errores as $error)
                <tr>
                    <td>{{ $error['dni'] }}</td>
                    <td>{{ $error['cuil_tit'] }}</td>
                    <td>{{ \Carbon\Carbon::parse($error['fecha_vigencia'])->format('d/m/Y') }}</td>
                    <td>{{ $error['detalle'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>

</html>
