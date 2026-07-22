<?php

namespace App\Http\Controllers\liquidaciones\repository;

use App\Http\Controllers\liquidaciones\dto\LiquidacionesDTO;
use App\Models\liquidaciones\LiquidacionDetalleEntity;
use App\Models\liquidaciones\LiquidacionEntity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LiquidacionesRepository
{
    private $user;
    private $fechaActual;
    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }


    public function findBySave($params)
    {
        return LiquidacionEntity::create([
            'id_factura' => $params->id_factura,
            'num_lote' => $params->num_lote,
            'id_afiliado' => $params->id_afiliado,
            'edad_afiliado' => $params->edad_afiliado,
            'id_cobertura' => $params->id_cobertura,
            'id_tipo_iva' => $params->id_tipo_iva,
            'cod_profesional' => $params->cod_profesional,
            'cod_provincia' => $params->cod_provincia,
            'diagnostico' => $params->diagnostico,
            'observaciones' => $params->observaciones,
            'fecha_registra' => $this->fechaActual,
            'cod_usuario' => $this->user->cod_usuario,
            'total_facturado' => $params->total_facturado,
            'total_aprobado' => $params->total_aprobado,
            'total_debitado' => $params->total_debitado,
            'dni_afiliado' => $params->dni_afiliado,
            'total_coseguro' => 0
        ]);
    }

    public function findByUpdateLiquidacion($params)
    {
        $liquid = LiquidacionEntity::find($params->id_liquidacion);
        $liquid->num_lote = $params->num_lote;
        $liquid->id_afiliado = $params->id_afiliado;
        $liquid->edad_afiliado = $params->edad_afiliado;
        $liquid->id_cobertura = $params->id_cobertura;
        $liquid->id_tipo_iva = $params->id_tipo_iva;
        $liquid->cod_profesional = $params->cod_profesional;
        $liquid->cod_provincia = $params->cod_provincia;
        $liquid->diagnostico = $params->diagnostico;
        $liquid->observaciones = $params->observaciones;
        $liquid->fecha_actualiza = $this->fechaActual;
        $liquid->total_facturado = $params->total_facturado;
        $liquid->total_aprobado = $params->total_aprobado;
        $liquid->total_debitado = $params->total_debitado;
        $liquid->dni_afiliado = $params->dni_afiliado;
        //$liquid->total_coseguro = 0;
        $liquid->update();
        return $liquid;
    }

    public function findByUpdate($id)
    {
        $fechaActual = Carbon::now('America/Argentina/Buenos_Aires');

        $detalle = LiquidacionDetalleEntity::where('id_liquidacion', $id)->get();
        $total_facturado = 0;
        $total_aprobado = 0;
        $total_debitado = 0;

        foreach ($detalle as $value) {
            $total_facturado += $value->monto_facturado;
            $total_aprobado += $value->monto_aprobado;
            $total_debitado += $value->monto_debitado;
        }

        $liq = LiquidacionEntity::find($id);
        $liq->total_facturado = $total_facturado;
        $liq->total_aprobado = $total_aprobado;
        $liq->total_debitado = $total_debitado;
        $liq->fecha_actualiza = $fechaActual;
        $liq->update();
        return (object) [
            'id_liquidacion' => $id,
            'total_facturado' => $total_facturado,
            'total_aprobado' => $total_aprobado,
            'total_debitado' => $total_debitado,
            'fecha' => $this->fechaActual
        ];
    }

    public function findById($id)
    {
        return LiquidacionEntity::find($id);
    }
    public function findByDeleteId($id)
    {
        return LiquidacionEntity::find($id)->delete();
    }

    public function findByIdExists($id)
    {
        return LiquidacionEntity::where('id_factura', $id)->exists();
    }

    public function findByLiquidacionFactura($id)
    {
        return LiquidacionEntity::where('id_factura', $id)->get();
    }

    public function findByLiquidacionPrimeraFactura($id)
    {
        return LiquidacionEntity::where('id_factura', $id)->first();
    }

    public function findByFechaPrestacionBetweenAndTipodetalle($desde, $hasta, $idFactura, $tipoDetalle)
    {
        $sql_view = 'vw_matriz_liquidaciones_medicamentos';
        if ($tipoDetalle == 'Practica') {
            //$sql_view = 'vw_matriz_liquidaciones_practicas';
            $sql_view = 'vw_suma_liquidacion_practica';
        }

        $data = DB::select(
            "SELECT * FROM $sql_view WHERE id_factura = ?",
            [$idFactura]
        );
        return $data;
    }

    public function findByLiquidacionId($id)
    {
        return DB::select("SELECT id_liquidacion,id_factura,id_afiliado,afiliado,edad_afiliado,id_cobertura,
                    id_tipo_iva,dni_afiliado,dni_medico,medico,cod_profesional,cod_provincia,diagnostico,observaciones,
                    num_lote FROM vw_matriz_liquidaciones_practicas WHERE id_liquidacion = ?", [$id]);
    }

    public function findByLiquidacionDetalleId($id)
    {
        return DB::select("SELECT fecha_prestacion,id_identificador_practica,codigo_practica,practica,
            costo_practica,cantidad,porcentaje_hon,porcentaje_gast,monto_facturado,monto_aprobado,monto_debitado,
            coseguro,debita_coseguro,debita_iva,id_tipo_motivo_debito,motivo_debito,observacion_debito,id_detalle,hospital,periodo
            FROM vw_detalle_liquidaciones WHERE id_liquidacion = ?", [$id]);
    }

    public function findByLiquidacionIdFactura($id)
    {
        return DB::select("SELECT id_liquidacion,id_factura,id_afiliado,afiliado,edad_afiliado,id_cobertura,
                    id_tipo_iva,dni_afiliado,dni_medico,medico,cod_profesional,cod_provincia,diagnostico,observaciones,
                    num_lote FROM vw_matriz_liquidaciones_practicas WHERE id_factura = ?", [$id]);
    }

    public function findByLiquidacionDetalleIdFactura($id)
    {
        return DB::select("SELECT fecha_prestacion,id_identificador_practica,codigo_practica,practica,
            costo_practica,cantidad,porcentaje_hon,porcentaje_gast,monto_facturado,monto_aprobado,monto_debitado,
            coseguro,debita_coseguro,debita_iva,id_tipo_motivo_debito,motivo_debito,observacion_debito,id_detalle,hospital,periodo
            FROM vw_detalle_liquidaciones WHERE id_liquidacion = ?", [$id]);
    }


    public function byCollectData($data)
    {
        return collect($data)->map(function ($tipo) {
            return new LiquidacionesDTO(
                $tipo->origen,
                $tipo->cuil_afiliado,
                $tipo->afiliado,
                $tipo->edad_afiliado,
                $tipo->tipo,
                $tipo->ug,
                $tipo->facturado,
                $tipo->aprobado,
                $tipo->debitado,
                $tipo->usuario,
                $tipo->estado,
                $tipo->id_liquidacion,
                $tipo->id_factura,
                $tipo->total_coseguro
            );
        });
    }

    public function findByCreateDetalleLiquidacion($detalle, $idLiquidacion, $idFactura)
    {
        $liquidacion = null;
        $totalFacturado = 0;
        $totalAprobado = 0;
        $totalDebitado = 0;

        $bulk = [];

        foreach ($detalle as $value) {

            if (!is_null($idFactura)) {
                $liquidacion = $this->findByCrearLiquidacionImport(
                    $idFactura,
                    $value['dni_afiliado'],
                    $value['id_afiliado']
                );
                $liquidacion->refresh();
                $idLiquidacion = $liquidacion->id_liquidacion;
            }

            $bulk[] = [
                'id_liquidacion' => $idLiquidacion,
                'fecha_prestacion' => $value['fecha_prestacion'],
                'id_identificador_practica' => $value['id_identificador_practica'],
                'costo_practica' => $value['costo_practica'],
                'cantidad' => $value['cantidad'],
                'porcentaje_hon' => 100,
                'porcentaje_gast' => 100,
                'monto_facturado' => $value['monto_facturado'],
                'monto_aprobado' => $value['monto_aprobado'],
                'coseguro' => $value['coseguro'],
                'debita_coseguro' => 0,
                'debita_iva' => 0,
                'id_tipo_motivo_debito' => $value['id_tipo_motivo_debito'],
                'observacion_debito' => $value['observacion_debito'],
                'monto_debitado' => $value['monto_debitado'],
                'hospital' => $value['hospital'] ?? null,
                'periodo' => $value['periodo'] ?? null,
                'tipo_hospital' => $value['tipo_hospital'] ?? null,
            ];

            $totalFacturado += $value['monto_facturado'];
            $totalAprobado += $value['monto_aprobado'];
            $totalDebitado += $value['monto_debitado'];
        }

        foreach (array_chunk($bulk, 1000) as $chunk) {
            LiquidacionDetalleEntity::insert($chunk);
        }

        return $this->findByUpdate($idLiquidacion);
    }

    public function findByCrearLiquidacionImport($idFactura, $dniAfiliado, $idAfiliado)
    {
        return LiquidacionEntity::create([
            'id_factura' => $idFactura,
            'num_lote' => '0000',
            'id_afiliado' => $idAfiliado,
            'edad_afiliado' => '0',
            'id_cobertura' => null,
            'id_tipo_iva' => null,
            'cod_profesional' => null,
            'cod_provincia' => null,
            'diagnostico' => null,
            'observaciones' => null,
            'fecha_registra' => $this->fechaActual,
            'cod_usuario' => $this->user->cod_usuario,
            'total_facturado' => 0,
            'total_aprobado' => 0,
            'total_debitado' => 0,
            'total_coseguro' => 0,
            'dni_afiliado' => $dniAfiliado
        ]);
    }

    public function fidByObtenerTotalDebitadoFactura($idLiquidacion)
    {
        /* return LiquidacionEntity::where('id_factura', $idLiquidacion)
            ->selectRaw('
        SUM(COALESCE(total_debitado, 0)) AS total_debitado,
        SUM(COALESCE(total_facturado, 0)) AS total_facturado,
        SUM(COALESCE(total_aprobado, 0)) AS total_aprobado,
        SUM(COALESCE(total_coseguro, 0)) AS total_coseguro
    ')
            ->first(); */

        $result = DB::select("
    SELECT 
        SUM(COALESCE(ld.monto_facturado, 0)) AS total_facturado,
        SUM(COALESCE(ld.monto_aprobado, 0)) AS total_aprobado,
        SUM(COALESCE(ld.monto_debitado, 0)) AS total_debitado,
        SUM(COALESCE(ld.coseguro, 0)) AS total_coseguro
    FROM tb_liquidaciones_detalle ld
    JOIN tb_liquidaciones l ON ld.id_liquidacion = l.id_liquidacion
    JOIN tb_facturacion_datos fa ON l.id_factura = fa.id_factura
    WHERE fa.id_factura = ?
", [$idLiquidacion]);

        return $result[0];
    }
}
