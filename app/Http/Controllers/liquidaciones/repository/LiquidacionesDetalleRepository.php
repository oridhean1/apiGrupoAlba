<?php

namespace App\Http\Controllers\liquidaciones\repository;

use App\Models\liquidaciones\LiquidacionDetalleEntity;
use App\Models\liquidaciones\LiquidacionEntity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LiquidacionesDetalleRepository
{

    public function findBySave($params, $id)
    {
        return LiquidacionDetalleEntity::create([
            'id_liquidacion' => $id,
            'fecha_prestacion' => $params['fecha_prestacion'],
            'id_identificador_practica' => $params['id_identificador_practica'],
            'costo_practica' => $params['costo_practica'],
            'cantidad' => $params['cantidad'],
            'porcentaje_hon' => $params['porcentaje_hon'],
            'porcentaje_gast' => $params['porcentaje_gast'],
            'monto_facturado' => $params['monto_facturado'],
            'monto_aprobado' => $params['monto_aprobado'],
            'coseguro' => $params['coseguro'],
            'debita_coseguro' => $params['debita_coseguro'],
            'debita_iva' => $params['debita_iva'],
            'id_tipo_motivo_debito' => $params['id_tipo_motivo_debito'],
            'observacion_debito' => $params['observacion_debito'],
            'monto_debitado' => $params['monto_debitado'],
            'estado' => '1',
            'hospital' => $params['hospital'],
            'periodo' => $params['periodo'],
        ]);
    }

    public function findById($id)
    {
        return LiquidacionDetalleEntity::find($id);
    }


    public function findByDeleteId($id)
    {
        return LiquidacionDetalleEntity::find($id)->delete();
    }

    public function findByLiquidacionAndDetalleId($id)
    {
        $idLiquidacion = DB::select('SELECT id_liquidacion FROM  tb_liquidaciones_detalle WHERE id_detalle = ?', [$id]);
        $liquidaciones = DB::select('SELECT count(*) as cantidad from tb_liquidaciones WHERE id_liquidacion = ?', [$idLiquidacion[0]->id_liquidacion]);

        DB::delete("DELETE FROM tb_liquidaciones_detalle WHERE id_detalle = ?", [$id]);

        if ($liquidaciones[0]->cantidad == 1) {
            return DB::delete("DELETE FROM tb_liquidaciones WHERE id_liquidacion = ?", [$idLiquidacion[0]->id_liquidacion]);
        }
    }

    public function findByUpdateLinea($params)
    {
        $linea = LiquidacionDetalleEntity::find($params->id_detalle);
        $linea->fecha_prestacion = $params->fecha_prestacion;
        $linea->id_identificador_practica = $params->id_identificador_practica;
        $linea->costo_practica = $params->costo_practica;
        $linea->cantidad = $params->cantidad;
        $linea->porcentaje_hon = $params->porcentaje_hon;
        $linea->porcentaje_gast = $params->porcentaje_gast;
        $linea->monto_facturado = $params->monto_facturado;
        $linea->monto_aprobado = $params->monto_aprobado;
        $linea->coseguro = $params->coseguro;
        $linea->debita_coseguro = $params->debita_coseguro;
        $linea->debita_iva = $params->debita_iva;
        $linea->id_tipo_motivo_debito = $params->id_tipo_motivo_debito;
        $linea->observacion_debito = $params->observacion_debito;
        $linea->monto_debitado = $params->monto_debitado;
        $linea->hospital = $params->hospital;
        $linea->periodo = $params->periodo;
        $linea->update();

        return $linea;
    }

    public function findByUpdateDetalleId($params)
    {
        $linea = LiquidacionDetalleEntity::find($params['id_detalle']);
        $linea->fecha_prestacion = $params['fecha_prestacion'];
        $linea->id_identificador_practica = $params['id_identificador_practica'];
        $linea->costo_practica = $params['costo_practica'];
        $linea->cantidad = $params['cantidad'];
        $linea->porcentaje_hon = $params['porcentaje_hon'];
        $linea->porcentaje_gast = $params['porcentaje_gast'];
        $linea->monto_facturado = $params['monto_facturado'];
        $linea->monto_aprobado = $params['monto_aprobado'];
        $linea->coseguro = $params['coseguro'];
        $linea->debita_coseguro = $params['debita_coseguro'];
        $linea->debita_iva = $params['debita_iva'];
        $linea->id_tipo_motivo_debito = $params['id_tipo_motivo_debito'];
        $linea->observacion_debito = $params['observacion_debito'];
        $linea->monto_debitado = $params['monto_debitado'];
        $linea->hospital = $params['hospital'];
        $linea->periodo = $params['periodo'];
        $linea->update();

        return $linea;
    }

    public function findByUpdateMontosCabecera($id_liquidacion)
    {
        $detalle = LiquidacionDetalleEntity::where('id_liquidacion', $id_liquidacion)->get();

        if (count($detalle) > 0) {
            $totalfactura = 0;
            $totalAprobado = 0;
            $totalDebitado = 0;

            foreach ($detalle as $key) {
                $totalfactura += $key->monto_facturado;
                $totalAprobado += $key->monto_aprobado;
                $totalDebitado += $key->monto_debitado;
            }

            $liquid = LiquidacionEntity::find($id_liquidacion);
            $liquid->total_facturado = $totalfactura;
            $liquid->total_aprobado = $totalAprobado;
            $liquid->total_debitado = $totalDebitado;
            $liquid->update();
        }
    }

    public function findByUpdateDetalleEstado($estado, $idLiquidacion)
    {
        return DB::table('tb_liquidaciones_detalle')
            ->whereIn('id_liquidacion', $idLiquidacion)
            ->update(['estado' => $estado]);
    }
}
