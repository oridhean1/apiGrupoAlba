<?php

namespace App\Http\Controllers\PrestacionesMedicas\Repository;

use App\Models\PrestacionesMedicas\AuditarPrestacionesPracticaLaboratorioEntity;
use App\Models\PrestacionesMedicas\DetallePrestacionesPracticaLaboratorioEntity;
use App\Models\PrestacionesMedicas\DetalleTramitePrestacionMedicaEntity;
use App\Models\PrestacionesMedicas\PrestacionesPracticaLaboratorioEntity;
use App\Models\PrestacionesMedicas\PrestacionMedicaFile;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PrestacionMedicaRepository
{
    private $fechaActual;
    private $user;
    public function __construct()
    {
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
        $this->user = Auth::user();
    }

    public function findBySave($params, $nombreArchivo, $datosTramite)
    {
        return PrestacionesPracticaLaboratorioEntity::create([
            'fecha_registra' => $params->fecha_registra,
            'observaciones' => $params->observaciones ?? null,
            'vigente' => $params->vigente,
            'monto_pagar' => $params->monto_pagar,
            'archivo_adjunto' => $nombreArchivo,
            'usuario_registra' => $this->user->cod_usuario,
            'cod_prestador' => $params->cod_prestador,
            'cod_profesional' => $params->cod_profesional,
            'dni_afiliado' => $params->dni_afiliado,
            'cod_tipo_estado' => $params->cod_tipo_estado,
            'diagnostico' => $params->diagnostico ?? null,
            'id_diagnostico' => !empty($params->id_diagnostico) ? $params->id_diagnostico : null,
            'domicilio_prestador' => $params->domicilio_prestador,
            'domicilio_profesional' => $params->domicilio_profesional,
            'edad_afiliado' => $params->edad_afiliado,
            'cod_internacion' => (!empty($params->cod_internacion) ? $params->cod_internacion : null),
            'id_detalle_tramite' => $datosTramite->id_detalle_tramite,
            'observacion_interna' => $params->observacion_interna
        ]);
    }

    public function findByUpdateId($params, $nombreArchivo, $datosTramite)
    {
        $prestacion = PrestacionesPracticaLaboratorioEntity::find($params->cod_prestacion);
        $prestacion->observaciones = $params->observaciones;
        $prestacion->vigente = $params->vigente;
        $prestacion->monto_pagar = $params->monto_pagar;
        $prestacion->archivo_adjunto =  $nombreArchivo ?? $prestacion->archivo_adjunto;
        $prestacion->cod_prestador = $params->cod_prestador;
        $prestacion->cod_profesional = $params->cod_profesional;
        $prestacion->dni_afiliado = $params->dni_afiliado;
        $prestacion->cod_tipo_estado = $params->cod_tipo_estado;
        $prestacion->diagnostico = $params->diagnostico;
        $prestacion->id_diagnostico = !empty($params->id_diagnostico) ? $params->id_diagnostico : null;
        $prestacion->domicilio_prestador = $params->domicilio_prestador;
        $prestacion->domicilio_profesional = $params->domicilio_profesional;
        $prestacion->edad_afiliado = $params->edad_afiliado;
        $prestacion->cod_internacion = (!empty($params->cod_internacion) ? $params->cod_internacion : null);
        $prestacion->id_detalle_tramite = $datosTramite->id_detalle_tramite;
        $prestacion->observacion_interna = $params->observacion_interna;
        $prestacion->usuario_registra = $this->user->cod_usuario;
        $prestacion->fecha_modifica = $this->fechaActual;

        $prestacion->update();
        return $prestacion;
    }

    public function findBySaveDetallePrestacion($detalle, $prestacion)
    {
        $sumatotal = 0;
        foreach ($detalle as $key) {
            if ($key->cod_detalle == '') {
                DetallePrestacionesPracticaLaboratorioEntity::create([
                    'cantidad_solicitada' => $key->cantidad_solicitada,
                    'cantidad_autorizada' => ($key->cantidad_autorizada !== null && $key->cantidad_autorizada !== '') ? $key->cantidad_autorizada : $key->cantidad_solicitada,
                    'precio_unitario' => ($key->monto_pagar / $key->cantidad_solicitada),
                    'monto_pagar' => $key->monto_pagar,
                    'id_identificador_practica' => $key->id_identificador_practica,
                    'cod_prestacion' => $prestacion->cod_prestacion,
                    'estado_imprimir' => '0'
                ]);
            }
            $sumatotal += $key->monto_pagar;
        }
        return $sumatotal;
    }

    public function findBySaveDetalleTramite($params)
    {
        return DetalleTramitePrestacionMedicaEntity::create([
            'id_locatorio' => $params->id_locatorio,
            'cod_sindicato' => $params->cod_sindicato,
            'id_tipo_tramite' => $params->id_tipo_tramite,
            'id_tipo_prioridad' => $params->id_tipo_prioridad
        ]);
    }

    public function findByUpdateDetalleTramite($params)
    {

        if (is_numeric($params->id_detalle_tramite)) {
            $detalle = DetalleTramitePrestacionMedicaEntity::find($params->id_detalle_tramite);
            $detalle->id_locatorio = $params->id_locatorio;
            $detalle->cod_sindicato = $params->cod_sindicato;
            $detalle->id_tipo_tramite = $params->id_tipo_tramite;
            $detalle->id_tipo_prioridad = $params->id_tipo_prioridad;
            $detalle->update();
            return $detalle;
        } else {
            return $this->findBySaveDetalleTramite($params);
        }
    }

    public function findByUpdateDetallePrestacion($detalle, $prestacion)
    {
        $sumatotal = 0;
        foreach ($detalle as $key) {
            if (is_numeric($key->cod_detalle)) {
                $item = DetallePrestacionesPracticaLaboratorioEntity::find($key->cod_detalle);
                $item->cantidad_solicitada = $key->cantidad_solicitada;
                $item->cantidad_autorizada = ($key->cantidad_autorizada !== null && $key->cantidad_autorizada !== '') ? $key->cantidad_autorizada : $key->cantidad_solicitada;
                $item->precio_unitario = ($key->monto_pagar / $key->cantidad_solicitada);
                $item->monto_pagar = $key->monto_pagar;
                $item->id_identificador_practica = $key->id_identificador_practica;
                $item->update();
            } else {
                DetallePrestacionesPracticaLaboratorioEntity::create([
                    'cantidad_solicitada' => $key->cantidad_solicitada,
                    'cantidad_autorizada' => ($key->cantidad_autorizada !== null && $key->cantidad_autorizada !== '') ? $key->cantidad_autorizada : $key->cantidad_solicitada,
                    'precio_unitario' => ($key->monto_pagar / $key->cantidad_solicitada),
                    'monto_pagar' => $key->monto_pagar,
                    'id_identificador_practica' => $key->id_identificador_practica,
                    'cod_prestacion' => $prestacion->cod_prestacion,
                    'estado_imprimir' => '0'
                ]);
            }


            $sumatotal += $key->monto_pagar;
        }
        return $sumatotal;
    }

    public function findByUpdateEstadoImprimir($id, $estado)
    {
        $detalle = DetallePrestacionesPracticaLaboratorioEntity::find($id);
        $detalle->estado_imprimir = $estado;
        $detalle->update();
        return $detalle;
    }

    public function findByEliminarItemDetalle($idDetalle)
    {
        return DetallePrestacionesPracticaLaboratorioEntity::find($idDetalle)->delete();
    }

    public function findByExisteAuditoriaItemDetalle($cod_detalle)
    {
        return AuditarPrestacionesPracticaLaboratorioEntity::where('cod_detalle', $cod_detalle)->exists();
    }

    public function findByEliminarPrestacion($cod_prestacion)
    {
        return PrestacionesPracticaLaboratorioEntity::find($cod_prestacion)->delete();
    }

    public function findBySavePrestacionMedicaFile($archivos, $dni_afil)
    {
        foreach ($archivos as $key) {
            PrestacionMedicaFile::create([
                'archivo' => $key['nombre'],
                'fecha_carga' => $this->fechaActual,
                'cod_prestacion' => $dni_afil
            ]);
        }
    }

    public function findByObtenerAdjuntoId($id)
    {
        return PrestacionMedicaFile::find($id);
    }

    public function findByEliminarAdjunto($cod_file)
    {
        return PrestacionMedicaFile::find($cod_file)->delete();
    }
}
