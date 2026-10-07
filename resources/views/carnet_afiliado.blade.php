<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Credencial Usuario</title>
    <style>
        * {
            margin: 4px;
            box-sizing: 0;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
        }

        .contenedor {
            position: absolute;
            display: inline-block;
            text-align: center;
            margin-left: 10px;
        }

        .img {
            position: absolute;
        }

        .img img {
            position: relative;
            max-width: 750px;
            max-height: 500px;
        }

        .datos {
            position: absolute;
            margin-top: 3cm;
            float: left;
            padding-left: 5cm;
            width: 800px;
            text-align: left;
            color: #fff;
        }
        
        .alba {
            position: absolute;
            margin-top: 3cm;
            float: left;
            padding-left: 5cm;
            width: 800px;
            text-align: left;
            color: #000;
        }

        .osv {
            position: absolute;
            margin-top: 5.5cm;
            float: left;
            padding-left: 5cm;
            width: 800px;
            text-align: left;
            color: #fff;
        }

        .nombre,
        .filial,
        .cuil,
        .plan {
            font-size: 20px;
            text-transform: uppercase;
            text-align: left;
            margin-top: 0.5cm;
            margin-left: -4cm;
        }

        .fecha .fecha_inicio {
            margin-left: -1.5cm !important;
            font-size: 20px;
            margin-top: 0.5cm;
        }

        .fecha .fecha_fin {
            margin-left: 5.5cm !important;
        }

        .page-break {
            page-break-after: always;
        }
    </style>
</head>

<body>
    @php
        $tipoPrincipal = $plan[0]->detalleplan[0]->addplan->tipo ?? null;
        $dniTitular=null;
    @endphp
    @foreach ($data as $padron)        
            @php
                $dniTitular = strlen($padron->cuil_tit) > 3 ? substr($padron->cuil_tit, 2, -1) : $padron->cuil_tit;
            @endphp
        @if ($padron->activo == 1)
        <div class="contenedor">
            <div class="img">
                @php
                    $razonSocial = strtoupper(config('app.empresa_razon_social'));
                    $isOsv = str_contains($razonSocial, 'VAREADORES') || str_contains($razonSocial, 'OSV');
                @endphp
                @if ($isOsv)
                    <img src="{{ storage_path('app/public/images/osvsalud.jpg') }}" width="750" height="500" style="max-width: none; max-height: none;">
                @elseif ($padron->id_locatario == 1)
                    <img src="{{ storage_path('app/public/images/BONSALUD.png') }}">
                @elseif ($padron->id_locatario == 2)
                    <img src="{{ storage_path('app/public/images/SEMBRAR.png') }}">
                @elseif ($padron->id_locatario == 3)
                    <img src="{{ storage_path('app/public/images/CREDENCIAL_BENE.png') }}">
                @else
                    <img src="{{ storage_path('app/public/images/credencial_alba.jpeg') }}">
                @endif
                
                @php
                    $isAlba = !$isOsv && $padron->id_locatario > 3;
                @endphp
                <div class="{{ $isOsv ? 'osv' : ($isAlba ? 'alba' : 'datos') }}" >
                    <p class="nombre">APELLIDOS Y NOMBRES:<b> {{ $padron->apellidos . ' ' . $padron->nombre }} </b></p>
                    <p class="filial">FILIACIÓN:<b class="parentezco">
                            {{ $padron['tipoParentesco']['parentesco'] ?? 'Titular' }} </b></p>
                    <p class="cuil">N° DE AFILIADO:<b> {{ $dniTitular . ' /0' . $padron->correlativo }} </b></p>
                    <p class="cuil">DNI:<b> {{ $padron->dni }} </b></p>
                    @php
                        $tipoPlanMostrar = $isOsv 
                            ? ($padron->origen->detalle_comercial_origen ?? $padron->detalleplan[0]->addplan->tipo ?? $tipoPrincipal)
                            : 'PLAN ÚNICO';
                    @endphp
                    <p class="plan">TIPO PLAN:<b> {{ $tipoPlanMostrar }} </b></p>
                    @if(!$isOsv)
                    <p class="plan">OBRA SOCIAL:<b> {{ $padron->origen->detalle_comercial_origen ?? 'DESCONOCIDO' }} </b>
                    </p>
                    @endif

                    <div class="fecha">
                        <p class="text fecha_inicio">VÁLIDO DESDE <b>{{ date('d/m/y', strtotime($f_inicio)) }}
                            </b>VÁLIDO HASTA <b>{{ date('d/m/y', strtotime($f_fin)) }}</b></p>
                    </div>
                </div>

            </div>
        </div>
        @if (!$loop->last)
            <div class="page-break"></div>
        @endif
        @endif
    @endforeach
</body>

</html>
