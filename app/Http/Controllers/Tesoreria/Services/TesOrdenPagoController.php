<?php

namespace App\Http\Controllers\Tesoreria\Services;

use App\Exports\OrdenesPagoExport;
use App\Http\Controllers\Tesoreria\Repository\TesPagosRepository;
use App\Http\Controllers\Tesoreria\Repository\TesAnticipoRepository;
use App\Http\Controllers\Tesoreria\Repository\TesImputacionFifoRepository;
use App\Http\Controllers\Tesoreria\Repository\TesCuentaCorrienteRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;


class TesOrdenPagoController extends Controller
{

    public function getListTipoEstado(TestOrdenPagoRepository $opa)
    {
        return response()->json($opa->findByListTipoEstado());
    }

    public function getFilterOrdenPago(Request $request, TestOrdenPagoRepository $opa)
    {
        $data = [];
        $data = $opa->getFiltroDinamico($request);
        return response()->json($data);
    }

    public function getProcesar(Request $request, TestOrdenPagoRepository $opa, TesPagosRepository $pagosRepo)
    {
        try {
            DB::beginTransaction();
            $menssage = "OPA generado con éxito.";
            if (is_null($request->id_orden_pago)) {
                $opa->findByCreate($request);
            } else {
                /* if (!$opa->findByExistsOpaEstado($request->id_orden_pago, '1')) {
                    DB::rollBack();
                    return response()->json(['message' => 'La OPA ya se encuentra en un proceso de PAGO y no puede ser modificado.'], 409);
                } */
                foreach ((array) $request->fechaprobablepagos as $fechaProbable) {
                    if (empty($fechaProbable['fecha_probable_pago'])) {
                        DB::rollBack();
                        return response()->json(['message' => 'Hay fechas de pago sin completar. Revisá la lista antes de continuar.'], 422);
                    }
                }
                $opa->findByUpdate($request);
                $pagosRepo->findByUpdatePagoPorOpa($request, $request->id_orden_pago);
                $menssage = "OPA actualizo con éxito.";
            }

            DB::commit();
            return response()->json(['message' => $menssage]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getModificarEstado(Request $request, TestOrdenPagoRepository $opa)
    {
        $tes = $opa->findByUpdateEstado($request->id_orden_pago, $request->id_estado_orden_pago, $request->motivo);
        return response()->json(['message' => "OPA {$tes->estado->descripcion_estado} con éxito"]);
    }

    /**
     * POST /v1/tesoreria/anular-opa
     * Body: { id_orden_pago, motivo }
     *
     * Anula la orden SIN reemplazarla: se usa cuando directamente no tendría que existir.
     * Sus facturas vuelven a estar disponibles para una orden nueva.
     *
     * 409 cuando choca contra una guarda (pagos confirmados, eCheq ya emitidos): el mensaje
     * viene redactado para el usuario.
     */
    public function getAnularOpa(Request $request, TestOrdenPagoRepository $opa)
    {
        try {
            $idOpa  = $request->input('id_orden_pago');
            $motivo = $request->input('motivo');

            if (!$idOpa) {
                return response()->json(['message' => 'id_orden_pago es requerido'], 422);
            }

            if (is_null($motivo) || trim((string) $motivo) === '') {
                return response()->json(['message' => 'El motivo de la anulacion es requerido'], 422);
            }

            $res = $opa->anularOpa($idOpa, $motivo);

            if (!$res['ok']) {
                return response()->json(['message' => $res['message']], 409);
            }

            return response()->json([
                'message' => $res['message'],
                'data'    => ['anulada' => $res['anulada']],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error anular OPA: ' . $e->getMessage());
            return response()->json(['message' => 'Error al anular la orden de pago'], 500);
        }
    }

    /**
     * POST /v1/tesoreria/anular-reemitir-opa
     * Body: { id_orden_pago, motivo }
     *
     * Anula la OP y emite otra por las mismas facturas, dejando el vinculo entre las dos.
     * 409 cuando choca contra una guarda (pagos confirmados, eCheq ya emitidos): el mensaje
     * viene redactado para el usuario.
     */
    public function getAnularYReemitir(Request $request, TestOrdenPagoRepository $opa)
    {
        try {
            $idOpa  = $request->input('id_orden_pago');
            $motivo = $request->input('motivo');

            if (!$idOpa) {
                return response()->json(['message' => 'id_orden_pago es requerido'], 422);
            }

            if (is_null($motivo) || trim((string) $motivo) === '') {
                return response()->json(['message' => 'El motivo de la anulacion es requerido'], 422);
            }

            $res = $opa->anularYReemitir($idOpa, $motivo);

            if (!$res['ok']) {
                return response()->json(['message' => $res['message']], 409);
            }

            return response()->json([
                'message' => $res['message'],
                'data'    => ['anulada' => $res['anulada'], 'nueva' => $res['nueva']],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error anular y reemitir OPA: ' . $e->getMessage());
            return response()->json(['message' => 'Error al anular y reemitir la orden de pago'], 500);
        }
    }

    /**
     * GET /v1/tesoreria/cadena-reemplazos-opa/{id}
     * Cadena completa de reemplazos, de la mas vieja a la mas nueva.
     */
    public function getCadenaReemplazos($id, TestOrdenPagoRepository $opa)
    {
        try {
            return response()->json($opa->cadenaDeReemplazos($id), 200);
        } catch (\Throwable $e) {
            Log::error('Error obtener cadena de reemplazos: ' . $e->getMessage());
            return response()->json(['message' => 'Error al obtener la cadena de reemplazos'], 500);
        }
    }

    /**
     * GET /v1/tesoreria/imputacion-fifo-opa/{id}
     *
     * Que facturas de la OP quedaron cubiertas por lo efectivamente pagado, de la mas vieja a
     * la mas nueva. Se calcula al vuelo: no hay un dato guardado que pueda quedar desfasado.
     */
    public function getImputacionFifo($id, TesImputacionFifoRepository $fifo)
    {
        try {
            return response()->json($fifo->distribuir($id), 200);
        } catch (\Throwable $e) {
            Log::error('Error calcular imputacion FIFO: ' . $e->getMessage());
            return response()->json(['message' => 'Error al calcular la imputacion'], 500);
        }
    }

    /**
     * GET /v1/tesoreria/estado-pago-factura/{id}
     *
     * Estado de pago de una factura segun lo efectivamente cobrado, sumando todas las OPs vivas
     * que la imputan.
     */
    public function getEstadoPagoFactura($id, TesImputacionFifoRepository $fifo)
    {
        try {
            return response()->json($fifo->estadoDeFactura($id), 200);
        } catch (\Throwable $e) {
            Log::error('Error obtener estado de pago de factura: ' . $e->getMessage());
            return response()->json(['message' => 'Error al obtener el estado de la factura'], 500);
        }
    }

    /**
     * POST /v1/tesoreria/anticipos
     * Body: { id_beneficiario, tipo_beneficiario: PROVEEDOR|PRESTADOR, monto, observaciones? }
     */
    public function getCrearAnticipo(Request $request, TesAnticipoRepository $ant)
    {
        try {
            foreach (['id_beneficiario', 'tipo_beneficiario', 'monto'] as $campo) {
                if (is_null($request->input($campo)) || $request->input($campo) === '') {
                    return response()->json(['message' => "{$campo} es requerido"], 422);
                }
            }

            $a = $ant->crearAnticipo(
                $request->input('id_beneficiario'),
                $request->input('tipo_beneficiario'),
                $request->input('monto'),
                $request->input('observaciones')
            );

            return response()->json(['message' => "Anticipo {$a->num_orden_pago} creado", 'data' => $a], 201);
        } catch (QueryException $e) {
            Log::error('Error crear anticipo: ' . $e->getMessage());
            return response()->json(['message' => 'Error al crear el anticipo'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * POST /v1/tesoreria/anticipos/aplicar
     * Body: { id_anticipo, lineas: [{ id_factura, monto }], observaciones? }
     */
    public function getAplicarAnticipo(Request $request, TesAnticipoRepository $ant)
    {
        try {
            $idAnticipo = $request->input('id_anticipo');
            $lineas     = $request->input('lineas', []);

            if (!$idAnticipo) {
                return response()->json(['message' => 'id_anticipo es requerido'], 422);
            }

            if (!is_array($lineas) || empty($lineas)) {
                return response()->json(['message' => 'Hay que enviar al menos una factura'], 422);
            }

            $ap = $ant->aplicarAFacturas($idAnticipo, $lineas, $request->input('observaciones'));

            return response()->json([
                'message' => "Anticipo aplicado en {$ap->num_orden_pago}",
                'data'    => $ap,
            ], 201);
        } catch (QueryException $e) {
            Log::error('Error aplicar anticipo: ' . $e->getMessage());
            return response()->json(['message' => 'Error al aplicar el anticipo'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * GET /v1/tesoreria/anticipos-con-saldo?id_beneficiario=&tipo_beneficiario=
     */
    public function getAnticiposConSaldo(Request $request, TesAnticipoRepository $ant)
    {
        try {
            $id   = $request->query('id_beneficiario');
            $tipo = $request->query('tipo_beneficiario');

            if (!$id || !$tipo) {
                return response()->json(['message' => 'id_beneficiario y tipo_beneficiario son requeridos'], 422);
            }

            return response()->json([
                'saldo_a_favor' => $ant->saldoAFavor($id, $tipo),
                'anticipos'     => $ant->anticiposConSaldo($id, $tipo),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error listar anticipos con saldo: ' . $e->getMessage());
            return response()->json(['message' => 'Error al listar los anticipos'], 500);
        }
    }

    /**
     * GET /v1/tesoreria/cuenta-corriente?id_beneficiario=&tipo_beneficiario=&desde=&hasta=
     *
     * Cuenta corriente del prestador o proveedor: resumen, movimientos y anticipos con saldo.
     */
    public function getCuentaCorriente(Request $request, TesCuentaCorrienteRepository $cc)
    {
        try {
            $id   = $request->query('id_beneficiario');
            $tipo = $request->query('tipo_beneficiario');

            if (!$id || !$tipo) {
                return response()->json(['message' => 'id_beneficiario y tipo_beneficiario son requeridos'], 422);
            }

            return response()->json(
                $cc->cuentaCorriente($id, $tipo, $request->query('desde'), $request->query('hasta')),
                200
            );
        } catch (\Throwable $e) {
            Log::error('Error obtener cuenta corriente: ' . $e->getMessage());
            return response()->json(['message' => 'Error al obtener la cuenta corriente'], 500);
        }
    }

    /**
     * GET /v1/tesoreria/cuenta-corriente-beneficiarios?tipo_beneficiario=&texto=
     *
     * Buscador de prestadores/proveedores que tengan movimientos de cuenta corriente.
     */
    public function getBeneficiariosCuentaCorriente(Request $request, TesCuentaCorrienteRepository $cc)
    {
        try {
            $tipo = $request->query('tipo_beneficiario');

            if (!$tipo) {
                return response()->json(['message' => 'tipo_beneficiario es requerido'], 422);
            }

            // `solo_con_movimientos=0` para el alta de anticipos: ahi hay que poder elegir a
            // cualquiera, tenga facturas o no.
            $soloConMovimientos = $request->query('solo_con_movimientos', '1') !== '0';

            return response()->json(
                $cc->buscarBeneficiarios($tipo, $request->query('texto'), 25, $soloConMovimientos),
                200
            );
        } catch (\Throwable $e) {
            Log::error('Error buscar beneficiarios de cuenta corriente: ' . $e->getMessage());
            return response()->json(['message' => 'Error al buscar beneficiarios'], 500);
        }
    }

    /**
     * GET /v1/tesoreria/anticipos-facturas-aplicables?id_beneficiario=&tipo_beneficiario=
     */
    public function getFacturasAplicables(Request $request, TesAnticipoRepository $ant)
    {
        try {
            $id   = $request->query('id_beneficiario');
            $tipo = $request->query('tipo_beneficiario');

            if (!$id || !$tipo) {
                return response()->json(['message' => 'id_beneficiario y tipo_beneficiario son requeridos'], 422);
            }

            return response()->json($ant->facturasAplicables($id, $tipo), 200);
        } catch (\Throwable $e) {
            Log::error('Error listar facturas aplicables: ' . $e->getMessage());
            return response()->json(['message' => 'Error al listar las facturas'], 500);
        }
    }

    public function printOrderPay($id)
    {
        $query = TesOrdenPagoEntity::with([
            'estado',
            'opadetalle',
            'opadetalle.detallefc',
            'opadetalle.detallefc.razonSocial',
            'proveedor.datosBancarios',
            'proveedor.tipoIva',
            'prestador.datosBancarios',
            'prestador.tipoIva',
            'pagos',
            'pagos.formaPago',
            'pagos.cuenta.entidadBancaria',
            // Solo abonos VIVOS: un eCheq anulado o rechazado no va en el comprobante — es un
            // documento que sale al proveedor, no puede listar pagos que se dieron de baja. Y al
            // excluirlos, la fecha que quedó liberada vuelve a salir como pendiente de emisión,
            // que es lo correcto: está de nuevo en "A emitir". (2026-09-07)
            'pagos.pagosParciales' => fn($q) => $q->vivos(),
            'pagos.fechaprobablepagos',
            'pagos.pagosParciales.bancoEmisor',
            'pagos.pagosParciales.estadoInstrumento',
        ])->where('id_orden_pago', $id)
            ->first();

        Carbon::setLocale('es');
        $debito = 0;
        if ($query && $query->opadetalle) {
            foreach ($query->opadetalle as $detalle) {
                $debito += $detalle->detallefc->total_debitado_liquidacion ?? 0;
            }
        }

        // Instrumentos del circuito nuevo (eCheq). Los pagos viejos no tienen estado de
        // instrumento y siguen saliendo por el bloque de "pagosParciales" de siempre.
        // Cada eCheq es un ABONO de la boleta, no la boleta misma (ver 2026_09_04_100900).
        $instrumentos = ($query?->pagos ?? collect())
            ->flatMap(fn($p) => $p->pagosParciales ?? collect())
            ->filter(fn($a) => !is_null($a->id_estado_instrumento))
            ->values();

        // Desde el 2026-09-05, Confirmar OPA solo deja el cronograma (fecha + cuota): el
        // instrumento (monto y forma de pago) nace despues, al emitir cada fecha. Estas son
        // las fechas que ya se planificaron pero todavia no tienen su pago emitido -> sin
        // esto, imprimir la orden recien confirmada salia sin sello y sin ningun dato.
        // Una fecha esta cubierta si tiene CUALQUIER abono vivo, tenga o no estado de instrumento.
        //
        // Antes se miraban solo los `$instrumentos` (los del circuito de eCheq). Una transferencia
        // cargada desde Confirmar Pago tiene `id_estado_instrumento` en NULL, asi que no entraba
        // en esa lista y su fecha seguia saliendo como "Pendiente de emitir" — la misma cuota
        // aparecia DOS veces: una como pendiente y otra como el pago que la cubre. Se vio en la
        // OPA-1413 (id 4524): la transferencia de $100.000 ocupa la fecha 220 y esa fecha salia
        // igual en la lista de pendientes. (2026-09-10)
        //
        // `pagosParciales` ya viene filtrado por `vivos()`, asi que un abono anulado no tapa su
        // fecha: vuelve a figurar como pendiente, que es lo correcto.
        $abonosVivos = ($query?->pagos ?? collect())
            ->flatMap(fn($p) => $p->pagosParciales ?? collect());

        $idsFechaOcupada = $abonosVivos->pluck('id_fecha_probable')->filter()->unique();

        $fechasPendientes = ($query?->pagos ?? collect())
            ->flatMap(fn($p) => $p->fechaprobablepagos ?? collect())
            ->reject(fn($f) => $idsFechaOcupada->contains($f->id_fecha_probable))
            ->sortBy('orden_cuotas')
            ->values();

        // Numero de cuota real de cada abono, para no mostrar el indice del loop: con dos abonos
        // el comprobante decia "1" y "2" sin importar que cuotas del cronograma cubrian.
        $cuotaPorFecha = ($query?->pagos ?? collect())
            ->flatMap(fn($p) => $p->fechaprobablepagos ?? collect())
            ->pluck('orden_cuotas', 'id_fecha_probable');

        // Las dos versiones que pide el circuito salen de la MISMA plantilla: lo unico que
        // cambia es si los numeros ya se cargaron. La inicial va a Tesoreria para que emita;
        // la definitiva, al proveedor. (2026-09-03)
        $faltanNumeros = $instrumentos->contains(fn($p) => empty(trim((string) $p->numero_echeq)));

        $versionComprobante = ($instrumentos->isEmpty() && $fechasPendientes->isEmpty())
            ? null
            : (($faltanNumeros || $fechasPendientes->isNotEmpty())
                ? 'PENDIENTE DE EMISION - COPIA PARA TESORERIA'
                : 'COMPROBANTE DEFINITIVO');

        // Lo que REALMENTE hay que pagar y lo que REALMENTE se entregó.
        //
        // El comprobante calculaba el total como `monto_orden_pago - debito`, o sea partiendo de
        // la cabecera. Esa puede estar desincronizada con lo imputado (ver
        // revisar-cabeceras-desincronizadas.md), y entonces el total impreso no coincide ni con
        // las facturas listadas arriba ni con lo que el sistema deja pagar. `montoPagableOpa()`
        // es el mismo criterio que usa el freno de sobrepago y el modal de Confirmar Pago.
        //
        // Y la columna "Valores Entregados" cerraba con ese mismo total: mostraba lo A PAGAR
        // debajo de una lista de pagos, así que el numero no era la suma de las filas de arriba.
        // Ahora cierra con lo entregado y lo que falta. (2026-09-10)
        $montoPagable = (new TestOrdenPagoRepository())->montoPagableOpa($id);

        if ($montoPagable <= 0) {
            $montoPagable = max(0, (float) ($query?->monto_orden_pago ?? 0) - $debito);
        }

        $totalEntregado = $instrumentos->sum(fn($i) => (float) $i->monto_pago)
            + ($query?->pagos ?? collect())
                ->flatMap(fn($p) => $p->pagosParciales ?? collect())
                ->filter(fn($a) => is_null($a->id_estado_instrumento))
                ->sum(fn($a) => (float) $a->monto_pago);

        $datos = [
            "cuota_por_fecha" => $cuotaPorFecha,
            "monto_pagable" => round($montoPagable, 2),
            "total_entregado" => round($totalEntregado, 2),
            "total_restante" => round(max(0, $montoPagable - $totalEntregado), 2),
            "instrumentos" => $instrumentos,
            "fechas_pendientes" => $fechasPendientes,
            "version_comprobante" => $versionComprobante,
            "comprobante_nro" => $query?->num_orden_pago,
            "fecha_emision" => $query?->fecha_emision,
            "cuit_proveedor" => $query?->proveedor ? $query?->proveedor?->cuit : $query?->prestador?->cuit,
            "nombre_proveedor" => $query?->proveedor ? $query?->proveedor?->razon_social : $query?->prestador?->razon_social,
            "cbu_proveedor" => $query?->proveedor ? $query?->proveedor?->datosBancarios?->cbu_cuenta : $query?->prestador?->datosBancarios?->cbu_cuenta,
            "iva_proveedor" => $query?->proveedor ? $query?->proveedor?->tipoIva?->descripcion_iva : $query?->prestador?->tipoIva?->descripcion_iva,
            "domicilio_proveedor" => $query?->proveedor ? $query?->proveedor?->direccion : $query?->prestador?->direccion,
            "facturas" => $query?->opadetalle ?? null,
            "total" => $query?->monto_orden_pago,
            "pagos" => $query?->pagos ?? null,
            "fecha_pago" => $query?->fecha_confirma_pago,
            "debito" => $debito,
            "totalPagos" => !empty($query?->pagos) && count($query?->pagos) > 0
                ? number_format((float) $query?->monto_orden_pago, 2, '.', '')
                : '0.00',
            "razon_social" => "PRUEBA",
            "observaciones" => optional($query->pagos->first())->observaciones
                ?? $query->observaciones
                ?? null,
            "pagosParciales" => $query?->pagos?->pluck('pagosParciales')?->flatten() ?? collect()
        ];

        $pdf = PDF::loadView('orden_pago', $datos);
        $pdf->setPaper('A4');
        return $pdf->download('recibo-pago-' . $query->id_orden_pago . '.pdf');
    }

    public function exportOrdenesPago(Request $request)
    {
        return Excel::download(new OrdenesPagoExport($request), 'OrdenesPago.xlsx');
    }

    public function printMultiplePago(Request $request)
    {
        $query = TesOrdenPagoEntity::with([
            'estado',
            'factura.razonSocial',
            'factura.tipoComprobante',
            'proveedor.datosBancarios',
            'proveedor.tipoIva',
            'prestador.datosBancarios',
            'prestador.tipoIva',
            'pagos.formaPago',
            'pagos.cuenta.entidadBancaria',
            // Igual que en printOrderPay: un eCheq anulado o rechazado no va en el comprobante.
            'pagos.pagosParciales' => fn($q) => $q->vivos(),
        ])->whereRelation('factura', 'id_factura', $id)
            ->first();

        Carbon::setLocale('es');
        $fecha = Carbon::parse($query?->factura?->periodo);

        $datos = [
            "comprobante_nro" => $query?->num_orden_pago,
            "fecha_emision" => $query?->fecha_emision,
            "cuit_proveedor" => $query?->proveedor ? $query->proveedor->cuit : $query?->prestador->cuit,
            "nombre_proveedor" => $query?->proveedor ? $query->proveedor->razon_social : $query?->prestador->razon_social,
            "cbu_proveedor" => $query?->proveedor ? $query->proveedor->datosBancarios?->cbu_cuenta : $query?->prestador->datosBancarios?->cbu_cuenta,
            "iva_proveedor" => $query?->proveedor ? $query->proveedor->tipoIva->descripcion_iva : $query?->prestador->tipoIva->descripcion_iva,
            "domicilio_proveedor" => $query?->proveedor ? $query->proveedor->direccion : $query?->prestador->direccion,
            "tipo_comprobante" => $query?->factura?->tipoComprobante?->descripcion,
            "numero_comprobante" => $query?->factura?->numero,
            "facturas" => [$query?->factura],
            "total" => $query?->monto_orden_pago,
            "pagos" => $query?->pagos,
            "fecha_pago" => $query?->fecha_confirma_pago,
            "debito" => $query?->factura?->total_debitado_liquidacion,
            "totalPagos" => !empty($query?->pagos) && count($query?->pagos) > 0
                ? number_format((float) $query?->monto_orden_pago, 2, '.', '')
                : '0.00',
            "razon_social" => $query?->factura->razonSocial,
            "observaciones" => 'PRESTACIÓN ' . strtoupper($fecha->translatedFormat('F')) . ' ' . $fecha->year,
            "pagosParciales" => $query->pagos->pluck('pagosParciales')->flatten()
        ];

        $pdf = PDF::loadView('pago_multiple.multiple_pago', $datos);
        $pdf->setPaper('A4');
        return $pdf->download('recibo-pago-' . $query->id_orden_pago . '.pdf');
    }
}
