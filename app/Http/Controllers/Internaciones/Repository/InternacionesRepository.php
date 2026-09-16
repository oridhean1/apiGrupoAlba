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

                return ['internacion' => $internacion, "prestacion" => $prestacion];
            }
        }

        // ALTA: sin cod_prestacion devolvemos una prestacion nueva (sin cod_prestacion, sin
        // detalle) para que el frontend cree un registro independiente y cada autorizacion
        // conserve su propia observacion. (T-00000804)
        // Los datos comunes a toda la internacion (tipo de tramite, diagnostico, solicitante,
        // efector y domicilios) se heredan de la ultima autorizacion cargada para no tener
        // que volver a completarlos. Observaciones, practicas y documentacion van vacias.
        $ultima = PrestacionesPracticaLaboratorioEntity::with(['datosTramite'])
            ->where('cod_internacion', $id)
            ->orderByDesc('cod_prestacion')
            ->first();

        $datosTramite = null;
        if ($ultima && $ultima->datosTramite) {
            $datosTramite = $ultima->datosTramite->toArray();
            // Sin id: el alta genera su propio registro de datos del tramite
            $datosTramite['id_detalle_tramite'] = null;
        }

        // En la primera autorizacion no hay de donde heredar: el efector suele ser la
        // misma institucion donde esta internado el paciente
        $prestadorInternacion = $internacion?->prestador;

        $prestacion = new \stdClass();
        $prestacion->arrayDetalle          = [];
        $prestacion->observaciones         = null;
        $prestacion->cod_prestacion        = null;
        $prestacion->documentacion         = [];
        $prestacion->afiliado              = $internacion?->afiliado ?? null;
        $prestacion->dni_afiliado          = $internacion?->dni_afiliado ?? null;
        $prestacion->datos_tramite         = $datosTramite;
        $prestacion->cod_prestador         = $ultima?->cod_prestador ?? $internacion?->cod_prestador;
        $prestacion->cod_profesional       = $ultima?->cod_profesional ?? $internacion?->cod_profesional ?? $internacion?->cod_prestador;
        $prestacion->domicilio_prestador   = $ultima?->domicilio_prestador ?? $prestadorInternacion?->direccion;
        $prestacion->domicilio_profesional = $ultima?->domicilio_profesional ?? $prestadorInternacion?->direccion;
        $prestacion->diagnostico           = $ultima?->diagnostico;
        $prestacion->id_diagnostico        = $ultima?->id_diagnostico;

        return ['internacion' => $internacion, 'prestacion' => $prestacion];
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
