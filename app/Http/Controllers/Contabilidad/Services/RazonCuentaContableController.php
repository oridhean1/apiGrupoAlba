<?php

namespace App\Http\Controllers\Contabilidad\Services;

use App\Http\Controllers\Tesoreria\Repository\ConceptosBancarios;
use App\Models\Contabilidad\RazonCuentaContableEntity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Contabilidad › Relaciones Contables › Cuentas por razón social (2026-11-19).
 *
 * Pantalla para que Contaduría cargue `tb_cont_razon_cuenta_contable` sin SQL: una fila por razón
 * social y `tipo_contraparte`. La usan el asiento de facturas/pagos (PRESTADOR/PROVEEDOR), el
 * circuito de ingresos (VENTA_*) y los conceptos bancarios del Visor de Conciliación (BCO_*).
 *
 * Índice único (id_razon, tipo_contraparte): cambiar la cuenta es UPDATE de la fila, nunca alta nueva.
 */
class RazonCuentaContableController extends Controller
{
    /** Tipos que se pueden asignar, agrupados. El código es el `tipo_contraparte` de la tabla. */
    private static function catalogo(): array
    {
        $tipos = [
            ['grupo' => 'Pagos', 'tipo' => RazonCuentaContableEntity::TIPO_PRESTADOR, 'descripcion' => 'Prestadores a pagar', 'uso' => 'Asiento de factura y pago a prestadores'],
            ['grupo' => 'Pagos', 'tipo' => RazonCuentaContableEntity::TIPO_PROVEEDOR, 'descripcion' => 'Proveedores a pagar', 'uso' => 'Asiento de factura y pago a proveedores'],

            ['grupo' => 'Ventas', 'tipo' => RazonCuentaContableEntity::TIPO_VENTA_DEUDOR, 'descripcion' => 'Deudores por ventas', 'uso' => 'Factura de venta (Debe) y cobro (Haber)'],
            ['grupo' => 'Ventas', 'tipo' => RazonCuentaContableEntity::TIPO_VENTA_INGRESO, 'descripcion' => 'Ingresos por ventas', 'uso' => 'Factura de venta (Haber)'],
            ['grupo' => 'Ventas', 'tipo' => RazonCuentaContableEntity::TIPO_VENTA_IVA, 'descripcion' => 'IVA débito fiscal', 'uso' => 'Factura de venta con IVA'],
            ['grupo' => 'Ventas', 'tipo' => RazonCuentaContableEntity::TIPO_VENTA_RET_GANANCIAS, 'descripcion' => 'Retención de Ganancias sufrida', 'uso' => 'Cobro con retención'],
            ['grupo' => 'Ventas', 'tipo' => RazonCuentaContableEntity::TIPO_VENTA_ANTICIPO, 'descripcion' => 'Anticipos de clientes', 'uso' => 'Cobro de más / anticipo aplicado'],
            ['grupo' => 'Ventas', 'tipo' => RazonCuentaContableEntity::TIPO_VENTA_VALORES_DEP, 'descripcion' => 'Valores a depositar', 'uso' => 'Cheques / eCheq recibidos sin depositar'],
        ];
        foreach (ConceptosBancarios::CATALOGO as $c) {
            if ($c['tipo']) {
                $tipos[] = ['grupo' => 'Conceptos bancarios', 'tipo' => $c['tipo'], 'descripcion' => $c['descripcion'], 'uso' => 'Registrar desde el Visor de Conciliación'];
            }
        }
        return $tipos;
    }

    /** GET ?id_razon= — todos los tipos con la cuenta asignada (o null) para esa razón. */
    public function getListar(Request $request)
    {
        if (!$request->filled('id_razon')) {
            return response()->json(['message' => 'Elegí la razón social.'], 422);
        }
        $asignadas = DB::table('tb_cont_razon_cuenta_contable as r')
            ->join('tb_cont_planes_cuentas_detalle as d', 'd.id_detalle_plan', '=', 'r.id_detalle_plan')
            ->leftJoin('tb_cont_planes_cuentas as p', 'p.id_plan_cuenta', '=', 'd.id_plan_cuenta')
            ->where('r.id_razon', $request->id_razon)
            ->get(['r.tipo_contraparte', 'r.vigente', 'r.fecha_registra', 'r.fecha_modifica', 'd.id_detalle_plan',
                   'd.codigo_cuenta', 'd.cuenta', 'p.plan_cuenta'])
            ->keyBy('tipo_contraparte');

        $data = array_map(function ($t) use ($asignadas) {
            $a = $asignadas[$t['tipo']] ?? null;
            return $t + [
                'id_detalle_plan' => $a->id_detalle_plan ?? null,
                'codigo_cuenta'   => $a->codigo_cuenta ?? null,
                'cuenta'          => $a->cuenta ?? null,
                'plan_cuenta'     => $a->plan_cuenta ?? null,
                'vigente'         => $a ? (int) $a->vigente : null,
                'fecha'           => $a ? ($a->fecha_modifica ?? $a->fecha_registra) : null,
            ];
        }, self::catalogo());

        return response()->json($data);
    }

    /** POST {id_razon, tipo_contraparte, id_detalle_plan} — asigna o cambia la cuenta. */
    public function postGuardar(Request $request)
    {
        $idRazon = $request->input('id_razon');
        $tipo    = $request->input('tipo_contraparte');
        $idDet   = $request->input('id_detalle_plan');

        if (!$idRazon || !$tipo || !$idDet) {
            return response()->json(['message' => 'Faltan datos: razón social, concepto y cuenta contable.'], 422);
        }
        $item = collect(self::catalogo())->firstWhere('tipo', $tipo);
        if (!$item) {
            return response()->json(['message' => "Concepto desconocido: {$tipo}."], 422);
        }

        // La cuenta tiene que ser de un plan de ESA razón social: cada entidad imputa en su plan.
        // Sin plan o de otra razón, corta (no se deja pasar por falta de dato).
        $cuenta = DB::table('tb_cont_planes_cuentas_detalle as d')
            ->join('tb_cont_planes_cuentas as p', 'p.id_plan_cuenta', '=', 'd.id_plan_cuenta')
            ->where('d.id_detalle_plan', $idDet)
            ->first(['d.id_detalle_plan', 'd.codigo_cuenta', 'd.cuenta', 'd.vigente', 'p.id_razon', 'p.plan_cuenta']);
        if (!$cuenta) {
            return response()->json(['message' => 'No se encontró la cuenta contable elegida.'], 422);
        }
        if ((int) $cuenta->id_razon !== (int) $idRazon) {
            return response()->json(['message' => "La cuenta {$cuenta->codigo_cuenta} es del \"{$cuenta->plan_cuenta}\", "
                . 'que no es de esta razón social. Elegí una cuenta del plan de la razón.'], 422);
        }
        if ((int) $cuenta->vigente !== 1) {
            return response()->json(['message' => "La cuenta {$cuenta->codigo_cuenta} no está vigente en el plan."], 422);
        }

        $user  = Auth::user();
        $ahora = Carbon::now();
        $fila  = RazonCuentaContableEntity::where('id_razon', $idRazon)->where('tipo_contraparte', $tipo)->first();

        if ($fila) {
            $fila->id_detalle_plan      = $idDet;
            $fila->vigente              = 1;
            $fila->cod_usuario_modifica = $user->cod_usuario ?? null;
            $fila->fecha_modifica       = $ahora;
            $fila->save();
        } else {
            RazonCuentaContableEntity::create([
                'id_razon'         => $idRazon,
                'tipo_contraparte' => $tipo,
                'id_detalle_plan'  => $idDet,
                'vigente'          => 1,
                'cod_usuario'      => $user->cod_usuario ?? null,
                'fecha_registra'   => $ahora,
            ]);
        }

        return response()->json(['message' => "{$item['descripcion']}: cuenta {$cuenta->codigo_cuenta} {$cuenta->cuenta} asignada."]);
    }
}
