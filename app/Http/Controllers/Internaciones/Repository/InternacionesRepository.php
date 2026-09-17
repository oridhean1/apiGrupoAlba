<?php

namespace App\Http\Controllers\Internaciones\Repository;

use App\Models\Internaciones\InternacionesEntity;
use App\Models\PrestacionesMedicas\DetallePrestacionesPracticaLaboratorioEntity;
use App\Models\PrestacionesMedicas\PrestacionesPracticaLaboratorioEntity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class InternacionesRepository
{
    private $user;
    private $fechaActual;
    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }

    public function findByPrestacionInternacionId($id, $codPrestacion = null)
    {
        $internacion = InternacionesEntity::with(['afiliado', 'prestador'])->find($id);

        // EDICION: se pidio una autorizacion puntual -> la devolvemos completa con su
        // detalle para que el frontend la actualice en vez de crear otra. (T-00000804)
        if (!empty($codPrestacion)) {
            $prestacion = PrestacionesPracticaLaboratorioEntity::with([
                "detalle",
                "detalle.practica",
                "estadoPrestacion",
                "afiliado",
                "usuario",
                "prestador",
                "datosTramite",
                "documentacion"
            ])
                ->where('cod_prestacion', $codPrestacion)
                ->where('cod_internacion', $id)
                ->first();

            if ($prestacion) {
                $detalle = DetallePrestacionesPracticaLaboratorioEntity::with(["practica"])
                    ->where('cod_prestacion', $prestacion->cod_prestacion)
                    ->get();

                $prestacion['arrayDetalle'] = $detalle->toArray();

                // Se respeta lo guardado en la autorizacion; solo lo que quedo vacio se
                // completa con los datos de la internacion
                $this->completarDesdeInternacion($prestacion, $internacion);

                return ['internacion' => $internacion, "prestacion" => $prestacion];
            }
        }

        // ALTA: sin cod_prestacion devolvemos una prestacion nueva (sin cod_prestacion, sin
        // detalle) para que el frontend cree un registro independiente y cada autorizacion
        // conserve su propia observacion. Observaciones, practicas y documentacion van
        // vacias. (T-00000804)
        $prestacion = new \stdClass();
        $prestacion->arrayDetalle          = [];
        $prestacion->observaciones         = null;
        $prestacion->cod_prestacion        = null;
        $prestacion->documentacion         = [];
        $prestacion->afiliado              = $internacion?->afiliado ?? null;
        $prestacion->dni_afiliado          = $internacion?->dni_afiliado ?? null;
        $prestacion->datos_tramite         = null;
        $prestacion->cod_prestador         = null;
        $prestacion->cod_profesional       = null;
        $prestacion->domicilio_prestador   = null;
        $prestacion->domicilio_profesional = null;
        $prestacion->diagnostico           = null;
        $prestacion->id_diagnostico        = null;

        // Diagnostico y prestador salen de la internacion, que es donde se cargan
        $this->completarDesdeInternacion($prestacion, $internacion);

        // Los datos del tramite (tipo, locatario, sindicato) no existen en la internacion:
        // se heredan de la ultima autorizacion cargada
        $ultima = PrestacionesPracticaLaboratorioEntity::with(['datosTramite'])
            ->where('cod_internacion', $id)
            ->orderByDesc('cod_prestacion')
            ->first();

        if ($ultima && $ultima->datosTramite) {
            $datosTramite = $ultima->datosTramite->toArray();
            // Sin id: el alta genera su propio registro de datos del tramite
            $datosTramite['id_detalle_tramite'] = null;
            $prestacion->datos_tramite = $datosTramite;
        }

        return ['internacion' => $internacion, 'prestacion' => $prestacion];
    }

    // Completa diagnostico, solicitante y efector (con sus domicilios) con los datos de
    // la internacion, solo en los campos vacios. Cada domicilio o texto de diagnostico
    // se completa unicamente si corresponde al mismo codigo de la internacion, para no
    // mezclar un domicilio o texto con otro prestador o diagnostico. (T-00000804)
    private function completarDesdeInternacion($prestacion, $internacion)
    {
        if (!$internacion) {
            return;
        }

        $institucion = $internacion->prestador;
        // El efector es el lugar donde esta internado el paciente
        $codInstitucion = $internacion->cod_profesional ?: $internacion->cod_prestador;

        // Texto del diagnostico: el de la internacion; si no tiene, el ultimo texto cargado
        // en una autorizacion de la internacion (siempre que no sea de otro diagnostico);
        // y si tampoco hay, la descripcion del tipo de diagnostico
        $textoDiagnostico = $internacion->diagnostico_presuntivo;
        if (blank($textoDiagnostico)) {
            $textoDiagnostico = PrestacionesPracticaLaboratorioEntity::where('cod_internacion', $internacion->cod_internacion)
                ->whereNotNull('diagnostico')
                ->where('diagnostico', '<>', '')
                ->where(function ($query) use ($internacion) {
                    $query->whereNull('id_diagnostico')
                        ->orWhere('id_diagnostico', $internacion->cod_tipo_diagnostico);
                })
                ->orderByDesc('cod_prestacion')
                ->value('diagnostico');
        }
        if (blank($textoDiagnostico)) {
            $textoDiagnostico = $internacion->tipoDiagnostico?->descripcion;
        }

        $pares = [
            ['cod_prestador',   $internacion->cod_prestador,        'domicilio_prestador',   $institucion?->direccion],
            ['cod_profesional', $codInstitucion,                    'domicilio_profesional', $institucion?->direccion],
            ['id_diagnostico',  $internacion->cod_tipo_diagnostico, 'diagnostico',           $textoDiagnostico],
        ];

        foreach ($pares as [$campoCod, $valorCod, $campoTexto, $valorTexto]) {
            if (blank($prestacion->$campoCod)) {
                $prestacion->$campoCod = $valorCod;
            }
            if (blank($prestacion->$campoTexto) && $prestacion->$campoCod == $valorCod) {
                $prestacion->$campoTexto = $valorTexto;
            }
        }
    }

    public function findBySave($params)
    {
        //  'cod_profesional' => $params->cod_profesional, 'cod_tipo_facturacion' => $params->cod_tipo_facturacion,
        return InternacionesEntity::create([
            'dni_afiliado' => $params->dni_afiliado,
            'fecha_internacion' => $params->fecha_internacion,
            'cod_prestador' => $params->cod_prestador,
            'vigente' => $params->vigente,
            'cod_tipo_prestacion' => $params->cod_tipo_prestacion,
            'cod_tipo_internacion' => $params->cod_tipo_internacion,
            'cod_tipo_habitacion' => $params->cod_tipo_habitacion,
            'cod_categoria_internacion' => $params->cod_categoria_internacion,
            'cod_especialidad' => $params->cod_especialidad,
            'cod_tipo_egreso' => $params->cod_tipo_egreso,
            'cod_tipo_diagnostico' => $params->cod_tipo_diagnostico,
            'fecha_ingresa' => $params->fecha_ingresa,
            'fecha_egreso' => $params->fecha_egreso,
            'cantidad_dias' => $params->cantidad_dias,
            'diagnostico_presuntivo' => $params->diagnostico_presuntivo,
            'tratamiento_indicado' => $params->tratamiento_indicado,
            'observaciones' => $params->observaciones,
            'nombre_archivo' => $params->nombre_archivo,
            'cod_tipo_estado' => $params->cod_tipo_estado,
            'cod_usuario_registra' => $this->user->cod_usuario,
            'edad_afiliado' => $params->edad_afiliado,
            'medico_prescribiente' => $params->medico_prescribiente,
            'hospital' => $params->hospital,
            'cod_hospital' => $params->cod_hospital,
            'estado'=>$params->estado,
            'num_internacion'=>$params->num_internacion
        ]);
    }

    public function findByUpdate($params)
    {
        $internacion = InternacionesEntity::find($params->cod_internacion);
        $internacion->dni_afiliado = $params->dni_afiliado;
        $internacion->fecha_internacion = $params->fecha_internacion;
        $internacion->cod_prestador = $params->cod_prestador;
        //  $internacion->cod_profesional = $params->cod_profesional; $internacion->cod_tipo_facturacion = $params->cod_tipo_facturacion;
        $internacion->vigente = $params->vigente;
        $internacion->cod_tipo_prestacion = $params->cod_tipo_prestacion;
        $internacion->cod_tipo_internacion = $params->cod_tipo_internacion;
        $internacion->cod_tipo_habitacion = $params->cod_tipo_habitacion;
        $internacion->cod_categoria_internacion = $params->cod_categoria_internacion;

        $internacion->cod_especialidad = $params->cod_especialidad;
        $internacion->cod_tipo_egreso = $params->cod_tipo_egreso;
        $internacion->cod_tipo_diagnostico = $params->cod_tipo_diagnostico;
        $internacion->fecha_ingresa = $params->fecha_ingresa;
        $internacion->fecha_egreso = $params->fecha_egreso;
        $internacion->cantidad_dias = $params->cantidad_dias;
        $internacion->diagnostico_presuntivo = $params->diagnostico_presuntivo;
        $internacion->tratamiento_indicado = $params->tratamiento_indicado;
        $internacion->observaciones = $params->observaciones;
        $internacion->nombre_archivo = $params->nombre_archivo;
        $internacion->cod_tipo_estado = $params->cod_tipo_estado;
        $internacion->edad_afiliado = $params->edad_afiliado;
        $internacion->medico_prescribiente = $params->medico_prescribiente;
        $internacion->hospital = $params->hospital;
        $internacion->cod_hospital = $params->cod_hospital;
        $internacion->estado = $params->estado;
        $internacion->num_internacion=$params->num_internacion;
        $internacion->update();

        return $internacion;
    }

    public function findByUpdateAndEstado($id, $estado)
    {
        $internacion = InternacionesEntity::find($id);
        $internacion->cod_tipo_estado = $estado;
        $internacion->update();

        return $internacion;
    }

    public function findByDeleteId($id)
    {
        $internacion = InternacionesEntity::find($id);
        $internacion->delete();

        return $internacion;
    }
}
