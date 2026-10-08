<?php

namespace App\Http\Controllers\Protesis\Repository;

use App\Models\Protesis\DetalleCotizacionProtesisEntity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DetalleCotizacionRepository
{

    private $user;
    private $fechaActual;
    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }

    public function findBySaveDetalleCotizacion($detalle, $idProtesis)
    {
        foreach ($detalle as $key) {
            foreach ($key->productos as $value) {
                if (!is_null($value->id_cotizacion)) {
                    $item =  DetalleCotizacionProtesisEntity::find($value->id_cotizacion);
                    $item->cantidad_autorizada = $value->cantidad_autoriza;
                    $item->monto_cotiza = $value->monto_cotiza;
                    $item->importe_total = $value->importe_total;
                    $item->observaciones = $value->observaciones;
                    $item->update();
                } else {
                    DetalleCotizacionProtesisEntity::create([
                        'id_detalle_producto_licitacion' => $value->id_detalle_producto_licitacion,
                        'cantidad_autorizada' =>  $value->cantidad_autoriza,
                        'monto_cotiza' =>  $value->monto_cotiza,
                        'importe_total' =>  $value->importe_total,
                        'observaciones' =>  $value->observaciones,
                        'id_solicitud'  => $value->id_solicitud,
                        'id_protesis' => $idProtesis,
                        'fecha_registra' =>  $this->fechaActual,
                        'cod_usuario' => $this->user->cod_usuario
                    ]);
                }
            }
        }
    }

    public function findByDetalleCotizacion($idProtesis)
    {
        return DB::table('tb_protesis_detalle as dtp')
            ->join('tb_protesis_matriz_productos as pd', 'pd.id_producto', '=', 'dtp.id_producto')
            ->join('tb_protesis_solicitar_presupuesto as pr', 'pr.id_protesis', '=', 'dtp.id_protesis')
            ->leftJoin('tb_prestador as pre', 'pre.cod_prestador', '=', 'pr.cod_prestador')
            ->leftJoin('tb_protesis_detalle_cotizacion as ct', function ($join) {
                $join->on('ct.id_solicitud', '=', 'pr.id_solicitud')
                    ->on('ct.id_detalle_producto_licitacion', '=', 'dtp.id_detalle');
            })
            ->where('dtp.id_protesis', $idProtesis)
            ->select(
                'dtp.id_detalle',
                'dtp.id_protesis',
                'dtp.id_producto',
                'dtp.cantidad_solicita',
                'pd.descripcion_producto',
                'pr.id_solicitud',
                'pr.cod_prestador',
                'pr.archivo_cotizacion',
                'pre.cuit',
                'pre.razon_social',
                'pre.nombre_fantasia',
                'ct.id_cotizacion',
                'ct.cantidad_autorizada',
                'ct.monto_cotiza',
                'ct.importe_total',
                'ct.observaciones'
            )
            ->get();
    }
}
