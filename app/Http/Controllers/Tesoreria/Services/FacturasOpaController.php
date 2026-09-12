<?php

namespace App\Http\Controllers\Tesoreria\Services;

use App\Http\Controllers\Tesoreria\Repository\FacturasOpaRepository;
use Illuminate\Routing\Controller;

/**
 * Consulta de la imputación de facturas a una OPA (tabla puente `tb_tes_opa_factura`).
 *
 * ⚠️ Adelgazado el 2026-09-12. Este controller traía diez métodos de los cuales **uno solo estaba
 * ruteado** (`getFacturasOpa`); los otros nueve eran código muerto que llamaba a métodos del
 * repository que no podían funcionar —consultaban `id_estado_pago`, una columna que en
 * `tb_facturacion_datos` se llama `estado_pago`— y que además escribían el estado de pago de la
 * factura con un catálogo que todavía no se migró. Se borraron en vez de arreglarse: agregar y
 * quitar facturas de una OPA se hace desde el circuito de `TestOrdenPagoRepository`, que es el que
 * tiene las guardas de pagos vivos y de estado.
 */
class FacturasOpaController extends Controller
{
    protected $facturasOpaRepository;

    public function __construct(FacturasOpaRepository $facturasOpaRepository)
    {
        $this->facturasOpaRepository = $facturasOpaRepository;
    }

    /**
     * GET /v1/tesoreria/getFacturasOpaId/{id}
     * Facturas imputadas a una OPA, con el monto aplicado a cada una.
     */
    public function getFacturasOpa($id)
    {
        try {
            return response()->json([
                'success' => true,
                'facturas' => $this->facturasOpaRepository->getFacturasOPA($id),
            ]);
        } catch (\Throwable $th) {
            return response()->json(
                ['message' => 'Error al obtener facturas de OPA: ' . $th->getMessage()],
                500
            );
        }
    }
}
