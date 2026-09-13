<?php

namespace App\Http\Controllers\Contabilidad\Repository;

use App\Models\Contabilidad\PeriodosContablesEntity;
use App\Models\Contabilidad\PeriodoEstadoRazonEntity;
use App\Models\configuracion\RazonSocialModelo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class PeriodosContablesRepository
{

    private $user;
    private $fechaActual;

    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }


    public function findByCreate($params)
    {
        $id_tipo_periodo  = isset($params->mes) ? 1 : 2;
        $periodo_contable = $this->generatePeriodoContable($params->anio_periodo, $params->mes ?? null);
        $periodo          = $this->generatePeriodo($params->anio_periodo, $params->mes ?? null);

        // Buscar o crear el período globalmente (compartido por todas las razones)
        $periodoDb = PeriodosContablesEntity::firstOrCreate(
            ['periodo' => $periodo],
            [
                'id_tipo_periodo'  => $id_tipo_periodo,
                'anio_periodo'     => $params->anio_periodo,
                'mes'              => $params->mes ?? null,
                'periodo_contable' => $periodo_contable,
                'periodo_inicio'   => $params->periodo_inicio,
                'periodo_fin'      => $params->periodo_fin,
                'cod_usuario_crea' => $this->user->cod_usuario,
                'fecha_registra'   => $this->fechaActual,
                'vigente'          => $params->vigente,
                'activo'           => $params->activo,
            ]
        );

        // Crear el estado activo/vigente para TODAS las razones sociales
        $razones = RazonSocialModelo::all();
        foreach ($razones as $razon) {
            PeriodoEstadoRazonEntity::firstOrCreate(
                [
                    'id_periodo_contable' => $periodoDb->id_periodo_contable,
                    'id_razon'            => $razon->id_razon,
                ],
                [
                    'activo'         => $params->activo,
                    'vigente'        => $params->vigente,
                    'cod_usuario'    => $this->user->cod_usuario,
                    'fecha_registra' => $this->fechaActual,
                ]
            );
        }

        return $periodoDb;
    }

    public function findByUpdate($params, $id)
    {
        $periodo = PeriodosContablesEntity::find($id);

        $id_tipo_periodo  = isset($params->mes) ? 1 : 2;
        $periodo_contable = $this->generatePeriodoContable($params->anio_periodo, $params->mes ?? null);
        $periodoValue     = $this->generatePeriodo($params->anio_periodo, $params->mes ?? null);

        $periodo->id_tipo_periodo       = $id_tipo_periodo;
        $periodo->periodo               = $periodoValue;
        $periodo->anio_periodo          = $params->anio_periodo;
        $periodo->mes                   = $params->mes ?? null;
        $periodo->periodo_contable      = $periodo_contable;
        $periodo->periodo_inicio        = $params->periodo_inicio;
        $periodo->periodo_fin           = $params->periodo_fin;
        $periodo->cod_usuario_modifica  = $this->user->cod_usuario;
        $periodo->fecha_modifica        = $this->fechaActual;
        $periodo->vigente               = $params->vigente;
        $periodo->activo                = $params->activo;
        $periodo->update();

        if (!empty($params->id_razon)) {
            PeriodoEstadoRazonEntity::where('id_periodo_contable', $id)
                ->where('id_razon', $params->id_razon)
                ->update([
                    'activo'               => $params->activo,
                    'vigente'              => $params->vigente,
                    'cod_usuario_modifica' => $this->user->cod_usuario,
                    'fecha_modifica'       => $this->fechaActual,
                ]);
        }

        return $periodo;
    }

    private function generatePeriodo($anio, $mes = null)
    {
        $anioCorto = substr($anio, -2);

        if ($mes) {
            return $anioCorto . str_pad($mes, 2, '0', STR_PAD_LEFT);
        } else {
            return $anioCorto . '00';
        }
    }

    private function generatePeriodoContable($anio, $mes = null)
    {
        if ($mes) {
            return "Periodo {$anio}-" . str_pad($mes, 2, '0', STR_PAD_LEFT);
        } else {
            return "Periodo {$anio}";
        }
    }

    public function findByList($params)
    {
        $idRazon = $params->id_razon ?? null;
        $estado  = $params->estado  ?? null;

        if ($idRazon) {
            $query = PeriodosContablesEntity::query()
                ->select([
                    'tb_cont_periodos_contables.id_periodo_contable',
                    'tb_cont_periodos_contables.id_tipo_periodo',
                    'tb_cont_periodos_contables.periodo',
                    'tb_cont_periodos_contables.anio_periodo',
                    'tb_cont_periodos_contables.mes',
                    'tb_cont_periodos_contables.periodo_contable',
                    'tb_cont_periodos_contables.periodo_inicio',
                    'tb_cont_periodos_contables.periodo_fin',
                    'er.activo',
                    'er.vigente',
                    'er.id_razon',
                ])
                ->join('tb_cont_periodo_estado_razon as er',
                    fn($j) => $j->on('er.id_periodo_contable', '=', 'tb_cont_periodos_contables.id_periodo_contable')
                                ->where('er.id_razon', $idRazon)
                );

            if (!is_null($estado)) {
                $query->where('er.activo', $estado);
            }
        } else {
            $query = PeriodosContablesEntity::query();
            if (!is_null($estado)) {
                $query->where('activo', $estado);
            }
        }

        return $query->orderBy('tb_cont_periodos_contables.periodo', 'desc')->get();
    }

    public function findByListAnual($params)
    {
        $idRazon = $params->id_razon ?? null;
        $estado  = $params->estado  ?? null;

        if ($idRazon) {
            $query = PeriodosContablesEntity::query()
                ->select([
                    'tb_cont_periodos_contables.id_periodo_contable',
                    'tb_cont_periodos_contables.id_tipo_periodo',
                    'tb_cont_periodos_contables.periodo',
                    'tb_cont_periodos_contables.anio_periodo',
                    'tb_cont_periodos_contables.mes',
                    'tb_cont_periodos_contables.periodo_contable',
                    'tb_cont_periodos_contables.periodo_inicio',
                    'tb_cont_periodos_contables.periodo_fin',
                    'er.activo',
                    'er.vigente',
                    'er.id_razon',
                ])
                ->where('tb_cont_periodos_contables.id_tipo_periodo', 2)
                ->join('tb_cont_periodo_estado_razon as er',
                    fn($j) => $j->on('er.id_periodo_contable', '=', 'tb_cont_periodos_contables.id_periodo_contable')
                                ->where('er.id_razon', $idRazon)
                );

            if (!is_null($estado)) {
                $query->where('er.activo', $estado);
            }
        } else {
            $query = PeriodosContablesEntity::query()->where('id_tipo_periodo', 2);
            if (!is_null($estado)) {
                $query->where('activo', $estado);
            }
        }

        return $query->orderBy('tb_cont_periodos_contables.periodo', 'desc')->get();
    }

    public function findByExistsAnio($anio)
    {
        return PeriodosContablesEntity::where('anio_periodo', $anio)
            ->where('activo', '1')
            ->exists();
    }

    // El período es global: existe si ya hay una fila para ese año/tipo, sin importar la razón
    public function findByExistsPeriodoAnual($anio, $idRazon = null)
    {
        return PeriodosContablesEntity::where('id_tipo_periodo', 2)
            ->where('anio_periodo', $anio)
            ->exists();
    }

    // El período es global: existe si ya hay una fila para ese año/mes, sin importar la razón
    public function findByExistsPeriodoMensual($anio, $mes, $idRazon = null)
    {
        return PeriodosContablesEntity::where('id_tipo_periodo', 1)
            ->where('anio_periodo', $anio)
            ->where('mes', $mes)
            ->exists();
    }

    public function findByExistsPeriodoActivo($periodo, $idRazon = null)
    {
        $query = PeriodosContablesEntity::where('periodo', $periodo);

        if ($idRazon) {
            $query->whereHas('estadosRazon', fn($q) => $q->where('id_razon', $idRazon)->where('activo', 1));
        } else {
            $query->where('activo', '1');
        }

        return $query->first();
    }

    public function findByPeriodoContableActivo($idRazon = null)
    {
        $query = PeriodosContablesEntity::query();

        if ($idRazon) {
            $query->whereHas('estadosRazon', fn($q) => $q->where('id_razon', $idRazon)->where('activo', 1));
        } else {
            $query->where('activo', '1');
        }

        return $query->first();
    }

    public function findByPeriodoContableActivoNow($idRazon = null)
    {
        return $this->findByPeriodoContableEnFecha($this->fechaActual->toDateString(), $idRazon);
    }

    /**
     * Período mensual activo que contiene UNA FECHA DADA (no necesariamente hoy).
     *
     * Lo necesita el asiento de débito de un instrumento diferido: un eCheq emitido en septiembre
     * y acreditado en octubre pertenece al período de **octubre**. Resolver el período por "hoy"
     * lo metería en el mes equivocado, y el error solo se vería al cerrar el período.
     * (2026-09-12)
     *
     * `findByPeriodoContableActivoNow()` es este mismo método con la fecha de hoy — una sola
     * implementación, para que las dos no puedan divergir.
     */
    public function findByPeriodoContableEnFecha($fecha, $idRazon = null)
    {
        $fecha = \Illuminate\Support\Carbon::parse($fecha)->toDateString();

        $query = PeriodosContablesEntity::where('id_tipo_periodo', 1)
            ->whereDate('periodo_inicio', '<=', $fecha)
            ->whereDate('periodo_fin', '>=', $fecha);

        if ($idRazon) {
            $query->whereHas('estadosRazon', fn($q) => $q->where('id_razon', $idRazon)->where('activo', 1));
        } else {
            $query->where('activo', '1');
        }

        return $query->first();
    }

    public function toggleActivo($id, $idRazon = null)
    {
        if ($idRazon) {
            $estado = PeriodoEstadoRazonEntity::where('id_periodo_contable', $id)
                ->where('id_razon', $idRazon)
                ->firstOrFail();
            $estado->activo               = !$estado->activo;
            $estado->cod_usuario_modifica = $this->user->cod_usuario;
            $estado->fecha_modifica       = $this->fechaActual;
            $estado->save();
        } else {
            $periodo = PeriodosContablesEntity::find($id);
            $periodo->activo               = !$periodo->activo;
            $periodo->cod_usuario_modifica = $this->user->cod_usuario;
            $periodo->fecha_modifica       = $this->fechaActual;
            $periodo->save();
        }
    }

    public function toggleVigente($id, $idRazon = null)
    {
        if ($idRazon) {
            $estado = PeriodoEstadoRazonEntity::where('id_periodo_contable', $id)
                ->where('id_razon', $idRazon)
                ->firstOrFail();
            $estado->vigente              = !$estado->vigente;
            $estado->cod_usuario_modifica = $this->user->cod_usuario;
            $estado->fecha_modifica       = $this->fechaActual;
            $estado->save();
        } else {
            $periodo = PeriodosContablesEntity::find($id);
            $periodo->vigente              = !$periodo->vigente;
            $periodo->cod_usuario_modifica = $this->user->cod_usuario;
            $periodo->fecha_modifica       = $this->fechaActual;
            $periodo->save();
        }
    }

    public function findById($id)
    {
        return PeriodosContablesEntity::find($id);
    }

    public function setActivoByAnio($anio, bool $activo, $idRazon = null)
    {
        if ($idRazon) {
            PeriodoEstadoRazonEntity::whereHas('periodoContable', fn($q) => $q->where('anio_periodo', $anio))
                ->where('id_razon', $idRazon)
                ->update([
                    'activo'               => $activo ? '1' : '0',
                    'cod_usuario_modifica' => $this->user->cod_usuario,
                    'fecha_modifica'       => $this->fechaActual,
                ]);
        } else {
            PeriodosContablesEntity::where('anio_periodo', $anio)
                ->update([
                    'activo'               => $activo ? '1' : '0',
                    'cod_usuario_modifica' => $this->user->cod_usuario,
                    'fecha_modifica'       => $this->fechaActual,
                ]);
        }
    }
}
