<?php

namespace App\Http\Controllers\Tesoreria\Repository;

use App\Http\Controllers\Contabilidad\Repository\AsientoContableRepository;
use App\Http\Controllers\Contabilidad\Repository\AsientosPagoHistorialRepository;
use App\Models\Contabilidad\AsientosPagoHistorialEntity;
use App\Models\Tesoreria\TesPagoEntity;
use App\Models\Tesoreria\TesPagosParciales;
use App\Models\Tesoreria\TestDetalleComprobantesPagoEntity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Anulación de pagos YA EJECUTADOS (con comprobante) y de órdenes en PAGADO / PAGO PARCIAL.
 *
 * Fase 4 de docs/circuito-pagos/plan-pago-ejecutado-con-comprobante.md (pedido del cliente "OPA
 * Anulación Pagado", 27/10/2026). Hasta acá una orden con un solo pago confirmado no se podía
 * anular, porque no había cómo revertir su débito ni su asiento.
 *
 * Revertir un pago es:
 *   - Contraasiento de SU línea del asiento de pago (`EJECUCION` para uno directo, `EMISION` para
 *     un cheque/eCheq) y, si el banco ya lo debitó, de su asiento de débito (`DEBITO`).
 *   - Devolver la plata a la cuenta si había salido: transferencia/efectivo, o un instrumento ya
 *     acreditado. Un eCheq emitido y no acreditado nunca movió saldo.
 *   - El pago queda ANULADO (no se borra: es historia) y su fecha del cronograma vuelve a quedar
 *     libre para cargar el pago correcto. Los comprobantes de esa fecha se dan de baja si ya no
 *     respaldan a ningún otro pago.
 *
 * Los pagos anteriores al circuito actual no tienen su línea guardada: su asiento es de toda la
 * boleta. Esos sólo se revierten anulando la OPA entera (contraasiento del asiento completo).
 */
class TesAnulacionPagoRepository
{
    private $user;
    private $fechaActual;
    private AsientoContableRepository $asientos;
    private AsientosPagoHistorialRepository $historial;
    private TesCuentasBancariasRepository $cuentas;

    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
        $this->asientos = new AsientoContableRepository();
        $this->historial = new AsientosPagoHistorialRepository($this->asientos);
        $this->cuentas = new TesCuentasBancariasRepository();
    }

    /** Anula UN pago ejecutado. */
    public function anularPagoEjecutado($idAbono, ?string $motivo, TestOrdenPagoRepository $opaRepo): TesPagosParciales
    {
        if (trim((string) $motivo) === '') {
            throw new \Exception('Hay que indicar el motivo de la anulación.');
        }

        return DB::transaction(function () use ($idAbono, $motivo, $opaRepo) {
            $abono = TesPagosParciales::lockForUpdate()->find($idAbono);

            if (is_null($abono)) {
                throw new \Exception("No se encontró el pago {$idAbono}.");
            }
            if (in_array((int) $abono->id_estado_instrumento, [TesInstrumentoPagoRepository::RECHAZADO, TesInstrumentoPagoRepository::ANULADO], true)) {
                throw new \Exception('Ese pago ya está anulado.');
            }
            if (is_null($abono->fecha_ejecucion)) {
                throw new \Exception('Ese pago todavía no tiene comprobante: no hace falta anularlo, se quita desde el pago.');
            }

            if (!$this->revertirPorLineas($abono, $motivo)) {
                throw new \Exception(
                    'Este pago es anterior al circuito actual y su asiento es de toda la orden, así que '
                        . 'no se puede anular solo. Para corregirlo anulá la orden de pago completa.'
                );
            }

            $this->darDeBajaElPago($abono, $motivo);
            $opaRepo->recalcularEstadoOpa(TesPagoEntity::where('id_pago', $abono->id_pago)->value('id_orden_pago'));

            return $abono->refresh();
        });
    }

    /**
     * Revierte TODO lo ejecutado de una orden, antes de anularla. Corre dentro de la transacción
     * del llamador (`anularOpa` / `anularYReemitir`).
     *
     * 1. Pagos con su línea guardada: contraasiento de la línea + devolución, uno por uno.
     * 2. Lo que queda (circuito anterior): contraasiento completo de cada asiento de pago o débito
     *    de la boleta que todavía no tenga ningún contraasiento, y devolución del débito NETO que le
     *    quede a la boleta en cada cuenta.
     *
     * @return array{pagos: int, asientos: int, devuelto: float}
     */
    public function revertirPagosDeOpa($idOpa, string $motivo): array
    {
        $resumen = ['pagos' => 0, 'asientos' => 0, 'devuelto' => 0.0];

        $boletas = TesPagoEntity::where('id_orden_pago', $idOpa)
            ->where('id_estado_orden_pago', '!=', TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO)
            ->get();

        foreach ($boletas as $boleta) {
            $abonos = TesPagosParciales::where('id_pago', $boleta->id_pago)->vivos()->lockForUpdate()->get();

            foreach ($abonos as $abono) {
                if (!is_null($abono->fecha_ejecucion) && $this->revertirPorLineas($abono, $motivo, $resumen)) {
                    $this->darDeBajaElPago($abono, $motivo);
                    $resumen['pagos']++;
                }
            }

            // Asientos de la boleta sin ningún contraasiento todavía: son los del circuito anterior
            // (a nivel boleta, sin línea por pago).
            $asientosVivos = DB::table('tb_cont_asientos_pago_historial as h')
                ->join('tb_cont_asientos_contables as a', 'a.id_asiento_contable', '=', 'h.id_asiento_contable')
                ->where('h.id_pago', $boleta->id_pago)
                ->where('h.es_contraasiento', 0)
                ->where('a.vigente', 'ACTIVO')
                ->whereIn('a.asiento_modelo', ['PAGO', 'DEBITO_INSTRUMENTO'])
                ->whereNotExists(fn($q) => $q->select(DB::raw(1))
                    ->from('tb_cont_asientos_pago_historial as c')
                    ->where('c.es_contraasiento', 1)
                    ->whereColumn('c.id_asiento_origen', 'h.id_asiento_contable'))
                ->distinct()
                ->pluck('h.id_asiento_contable');

            foreach ($asientosVivos as $idAsiento) {
                $this->historial->generarContraasiento($idAsiento, 'ANULACION', $boleta->id_pago, 'Anulación de la orden de pago. ' . $motivo);
                $resumen['asientos']++;
            }

            // Lo que la boleta todavía tenga debitado (neto) vuelve a cada cuenta.
            $netos = DB::table('tb_tes_movimiento_cuenta_bancaria')
                ->where('id_pago', $boleta->id_pago)
                ->whereNull('id_operacion')
                ->groupBy('id_cuenta_bancaria')
                ->select('id_cuenta_bancaria', DB::raw("SUM(CASE WHEN tipo_movimiento = 'EGRESO' THEN monto ELSE -monto END) AS neto"))
                ->get();

            foreach ($netos as $n) {
                if ((float) $n->neto > 0.009) {
                    $this->devolver($n->id_cuenta_bancaria, (float) $n->neto, $boleta->id_pago, 'ANULACION OPA');
                    $resumen['devuelto'] += (float) $n->neto;
                }
            }

            // Lo ejecutado del circuito anterior queda anulado junto con su asiento.
            foreach ($abonos->fresh() ?? [] as $abono) {
                $muerto = in_array((int) $abono->id_estado_instrumento,
                    [TesInstrumentoPagoRepository::RECHAZADO, TesInstrumentoPagoRepository::ANULADO], true);
                if (!is_null($abono->fecha_ejecucion) && !$muerto) {
                    $this->darDeBajaElPago($abono, $motivo);
                    $resumen['pagos']++;
                }
            }
        }

        // Las facturas que esta orden marcó como pagadas (`estado_pago = 1`, lo pone el confirmar
        // cuando la orden queda PAGADA) vuelven a impagas: la orden se anula y quedan libres.
        $facturas = DB::table('tb_tes_orden_pago_detalle')->where('id_orden_pago', $idOpa)->pluck('id_factura')
            ->push(DB::table('tb_tes_orden_pago')->where('id_orden_pago', $idOpa)->value('id_factura'))
            ->filter()->unique();
        // No si otra orden PAGADA también la cubre (factura repartida entre órdenes).
        DB::table('tb_facturacion_datos as f')
            ->whereIn('f.id_factura', $facturas)
            ->where('f.estado_pago', 1)
            ->whereNotExists(fn($q) => $q->select(DB::raw(1))
                ->from('tb_tes_opa_factura as pf')
                ->join('tb_tes_orden_pago as o', 'o.id_orden_pago', '=', 'pf.id_orden_pago')
                ->whereColumn('pf.id_factura', 'f.id_factura')
                ->where('o.id_orden_pago', '!=', $idOpa)
                ->where('o.id_estado_orden_pago', TestOrdenPagoRepository::ESTADO_OPA_PAGADO))
            ->update(['f.estado_pago' => 0]);

        return $resumen;
    }

    /**
     * Contraasiento de las líneas de ESTE pago y devolución de lo que salió. Devuelve false si el
     * pago no tiene su línea guardada (circuito anterior).
     */
    private function revertirPorLineas(TesPagosParciales $abono, string $motivo, array &$resumen = []): bool
    {
        $eventos = AsientosPagoHistorialEntity::query()
            ->where('id_pago_parcial', $abono->id_pago_parcial)
            ->where('es_contraasiento', false)
            ->whereIn('tipo_evento', ['EJECUCION', 'EMISION', 'DEBITO'])
            ->whereNotNull('id_asiento_contable_detalle')
            ->get();

        if ($eventos->isEmpty()) {
            return false;
        }

        $razon = trim('Anulación del pago. ' . $motivo);

        foreach ($eventos as $evento) {
            $contra = $this->asientos->contraasientoDeLinea($evento->id_asiento_contable_detalle, $razon);
            $this->historial->guardarHistorial(
                $abono->id_pago,
                $contra->id_asiento_contable,
                'CONTRAASIENTO',
                true,
                $evento->id_asiento_contable,
                $razon,
                $abono->id_pago_parcial
            );
            if (isset($resumen['asientos'])) {
                $resumen['asientos']++;
            }
        }

        // La plata vuelve si había salido: un pago directo sale al ejecutarse; un instrumento,
        // cuando el banco lo debita (evento DEBITO).
        $salio = $eventos->contains(fn($e) => in_array($e->tipo_evento, ['EJECUCION', 'DEBITO'], true));

        if ($salio && !empty($abono->id_cuenta_bancaria)) {
            $this->devolver($abono->id_cuenta_bancaria, (float) $abono->monto_pago, $abono->id_pago, 'ANULACION PAGO');
            if (isset($resumen['devuelto'])) {
                $resumen['devuelto'] += (float) $abono->monto_pago;
            }
        }

        return true;
    }

    private function devolver($idCuenta, float $monto, $idPago, string $descripcion): void
    {
        $this->cuentas->findByDepositoCuenta($idCuenta, $monto);
        $this->cuentas->findByRegistrarMovimiento($idCuenta, $monto, 'INGRESO', $idPago, null, $descripcion);
    }

    /**
     * El pago queda ANULADO (no se borra) y su fecha vuelve a quedar libre. Los comprobantes de esa
     * fecha se dan de baja si ya no respaldan a ningún otro pago ejecutado: si quedaran, el próximo
     * pago que se cargue en esa fecha se ejecutaría solo, sin comprobante propio.
     */
    private function darDeBajaElPago(TesPagosParciales $abono, string $motivo): void
    {
        $abono->id_estado_instrumento = TesInstrumentoPagoRepository::ANULADO;
        $abono->motivo_rechazo = $motivo;
        $abono->fecha_rechazo = $this->fechaActual;
        $abono->save();

        $idFecha = $abono->id_fecha_probable;

        $otroEjecutado = TesPagosParciales::where('id_pago', $abono->id_pago)
            ->vivos()
            ->whereNotNull('fecha_ejecucion')
            ->when(!is_null($idFecha), fn($q) => $q->where('id_fecha_probable', $idFecha))
            ->exists();

        if ($otroEjecutado) {
            return;
        }

        TestDetalleComprobantesPagoEntity::where('id_pago', $abono->id_pago)
            ->where('estado', '1')
            ->where(fn($q) => is_null($idFecha)
                ? $q->whereNull('id_fecha_probable')
                : $q->where('id_fecha_probable', $idFecha))
            ->update([
                'estado' => '0',
                'cod_usuario_elimina' => $this->user->cod_usuario ?? null,
                'fecha_elimina' => $this->fechaActual,
            ]);
    }
}
