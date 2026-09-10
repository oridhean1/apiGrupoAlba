<?php

namespace App\Http\Controllers\Tesoreria\Repository;

use App\Models\Tesoreria\TesCuentasBancariasEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use App\Models\Tesoreria\TesPagosParciales;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida del instrumento de pago (eCheq).
 *
 * ═══ Dónde vive el instrumento ═══
 *
 *     Orden de pago (tb_tes_orden_pago)
 *      └── 1 boleta de pago (tb_tes_pago)
 *           ├── N fechas probables (tb_tes_fecha_probable_pago)  — el cronograma
 *           └── N abonos          (tb_tes_pago_parcial)          — CADA eCHEQ ES UNO DE ESTOS
 *
 * **Cada eCheq es un ABONO**, no una boleta. Verificado contra los datos el 2026-09-04: ninguna
 * de las 4.187 OPAs tiene más de una boleta, 23 boletas tienen más de un abono, y 81 números de
 * cheque viven en `tb_tes_pago_parcial` contra 9 en `tb_tes_pago`.
 *
 * La primera versión de este repositorio operaba sobre la boleta. Estaba un nivel demasiado
 * arriba: dos eCheq de una misma orden habrían exigido dos boletas, algo que no ocurre en ningún
 * caso real y que la guarda de `getCrearPago` impide. Corregido en 2026_09_04_100900.
 *
 * ═══ MÁQUINA DE ESTADOS ═══
 *
 *   BORRADOR (1)          Pagos definió el abono (monto y fecha). La OP todavía no se imprimió.
 *        |
 *        v  marcarPendienteEmision()  — se imprime la OP inicial, SIN números
 *   PENDIENTE_EMISION (2) Esperando que el banco emita. El número se carga acá como borrador.
 *        |
 *        v  confirmarEmisionDeOpa()   — se confirman TODOS los números de la OP juntos
 *   EMITIDO (3)
 *        +--> ACREDITADO (4)  la conciliación bancaria confirmó el débito
 *        +--> RECHAZADO (5)   volvió — LO CARGA EL USUARIO A MANO (negocio, 2026-09-03)
 *        +--> ANULADO (6)
 *
 * El número de eCheq es único en toda la tabla: un eCheq cubre un solo abono. Lo garantiza el
 * índice `uq_pp_numero_echeq`; la validación aplicativa solo da el aviso temprano.
 */
class TesInstrumentoPagoRepository
{
    const BORRADOR          = 1;
    const PENDIENTE_EMISION = 2;
    const EMITIDO           = 3;
    const ACREDITADO        = 4;
    const RECHAZADO         = 5;
    const ANULADO           = 6;

    /**
     * `tb_tes_formas_pago`: 7 = eCheq. Verificado en las DOS bases el 2026-09-03 — los catálogos
     * de formas de pago y de estado de instrumento coinciden id por id entre Alba y OSV.
     */
    const FORMA_PAGO_ECHEQ = 7;

    private $user;
    private $fechaActual;

    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }

    private static function aCentavos($monto): int
    {
        return (int) round(((float) $monto) * 100);
    }

    /** Las boletas de pago vivas de una OP. Los abonos cuelgan de ellas. */
    private function boletasDeOpa($idOpa): array
    {
        return TesPagoEntity::where('id_orden_pago', $idOpa)
            ->where('id_estado_orden_pago', '!=', TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO)
            ->pluck('id_pago')
            ->all();
    }

    /** Abonos de una OP, en cualquier estado. */
    public function abonosDeOpa($idOpa)
    {
        return TesPagosParciales::whereIn('id_pago', $this->boletasDeOpa($idOpa))->get();
    }

    /**
     * Resuelve el banco emisor del eCheq.
     *
     * Si vino la cuenta bancaria, el banco lo determina la cuenta: es la única fuente que no
     * puede contradecirse a sí misma. Si además mandaron un banco explícito y NO coincide, se
     * corta — elegir uno en silencio dejaría el listado por banco mal agrupado sin que nadie
     * se entere.
     *
     * El banco explícito sin cuenta es válido: el catálogo de cuentas está incompleto, así que
     * hay eCheq emitidos desde bancos todavía sin cuenta cargada.
     */
    private function resolverBancoEmisor($idCuenta, $idBancoExplicito): ?int
    {
        if (is_null($idCuenta)) {
            return is_null($idBancoExplicito) ? null : (int) $idBancoExplicito;
        }

        $cuenta = TesCuentasBancariasEntity::find($idCuenta);

        if (is_null($cuenta)) {
            throw new \Exception("No se encontró la cuenta bancaria {$idCuenta}.");
        }

        $idBancoCuenta = (int) $cuenta->id_entidad_bancaria;

        if (!is_null($idBancoExplicito) && (int) $idBancoExplicito !== $idBancoCuenta) {
            throw new \Exception(
                "El banco emisor indicado no coincide con el de la cuenta bancaria {$idCuenta}."
            );
        }

        return $idBancoCuenta;
    }

    /**
     * Fechas planificadas de una OP que todavía NO tienen su pago emitido.
     *
     * Confirmar la orden deja el cronograma; esto devuelve lo que falta emitir de ese plan.
     */
    public function fechasPendientesDeEmitir($idOpa)
    {
        return DB::table('tb_tes_fecha_probable_pago as fp')
            ->whereIn('fp.id_pago', $this->boletasDeOpa($idOpa))
            // Un abono ANULADO o RECHAZADO no ocupa su fecha: la fecha vuelve al plan y se puede
            // volver a emitir. Sin esta exclusión, anular un pago mal cargado dejaba la fecha
            // muerta para siempre y la orden imposible de completar. (2026-09-07)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('tb_tes_pago_parcial as pp')
                    ->whereColumn('pp.id_fecha_probable', 'fp.id_fecha_probable')
                    ->where(function ($w) {
                        $w->whereNull('pp.id_estado_instrumento')
                            ->orWhereNotIn('pp.id_estado_instrumento', [self::RECHAZADO, self::ANULADO]);
                    });
            })
            ->orderBy('fp.orden_cuotas')
            ->get();
    }

    /**
     * Emite UN pago sobre una fecha planificada: acá se definen el monto y la forma de pago.
     *
     * Es el momento en que el plan se vuelve un instrumento concreto. Antes de esto solo existe
     * la fecha; el abono no puede existir sin monto ni forma (las dos columnas son NOT NULL, y
     * rellenarlas con ceros sería inventar datos).
     *
     * @param array $datos ['monto' => float, 'id_forma_pago' => int,
     *                      'id_cuenta_bancaria' => ?int, 'id_banco_emisor' => ?int]
     */
    public function emitirPagoDeFecha($idFechaProbable, array $datos): TesPagosParciales
    {
        return DB::transaction(function () use ($idFechaProbable, $datos) {
            $fecha = DB::table('tb_tes_fecha_probable_pago')->where('id_fecha_probable', $idFechaProbable)->first();

            if (is_null($fecha)) {
                throw new \Exception("No se encontró la fecha de pago {$idFechaProbable}.");
            }

            // Un abono ANULADO o RECHAZADO no ocupa su fecha: la fecha vuelve al plan y se puede
            // volver a emitir. Sin esta exclusión, anular un pago mal cargado dejaba la fecha
            // muerta para siempre y la orden imposible de completar. (2026-09-07)
            if (
                TesPagosParciales::where('id_fecha_probable', $idFechaProbable)
                    ->where(function ($q) {
                        $q->whereNull('id_estado_instrumento')
                            ->orWhereNotIn('id_estado_instrumento', [self::RECHAZADO, self::ANULADO]);
                    })
                    ->exists()
            ) {
                throw new \Exception('Esa fecha de pago ya tiene su pago emitido.');
            }

            $monto = (float) ($datos['monto'] ?? 0);

            if (self::aCentavos($monto) <= 0) {
                throw new \Exception('Hay que indicar el monto del pago.');
            }

            if (empty($datos['id_forma_pago'])) {
                throw new \Exception('Hay que indicar la forma de pago.');
            }

            $boleta = TesPagoEntity::find($fecha->id_pago);
            $opa = TesOrdenPagoEntity::find($boleta->id_orden_pago);

            $opaRepo = new TestOrdenPagoRepository();

            // Lo ya emitido sobre esta orden, para saber cuánto queda. Los abonos RECHAZADOS y
            // ANULADOS no cuentan: esa plata no salió (o volvió), así que no puede seguir ocupando
            // lugar en el tope. Sin eso, rechazar un eCheq dejaba la orden imposible de repagar.
            $yaEmitido = $this->emitidoVivoDeOpa($opa->id_orden_pago);

            // No se puede emitir por encima de lo que realmente hay que pagarle al beneficiario.
            //
            // El tope es el monto PAGABLE (lo imputado menos el débito de liquidación), no el
            // `monto_orden_pago`, que arrastra el bruto de la factura. Sin este freno se podía
            // cargar y confirmar abonos por el bruto: el caso que lo destapó tenía $1.139.311,04
            // en abonos sobre una orden cuyo neto real era $78.960 — $1.060.351,04 de sobrepago,
            // y lo único que avisaba era un saldo en rojo en la grilla, que no bloquea nada.
            // (2026-09-05, ver docs/circuito-pagos/revisar-debito-no-descontado.md)
            $tope = $this->topeDeOpa($opa->id_orden_pago, $opaRepo);

            if (self::aCentavos($yaEmitido + $monto) > self::aCentavos($tope)) {
                $disponible = max(0, $tope - $yaEmitido);

                throw new \Exception(sprintf(
                    'El pago se pasa de lo que hay que pagar en esta orden. '
                        . 'A pagar: $%s. Ya emitido: $%s. Disponible: $%s.',
                    number_format($tope, 2, ',', '.'),
                    number_format($yaEmitido, 2, ',', '.'),
                    number_format($disponible, 2, ',', '.')
                ));
            }

            // La cuenta de origen tiene que ser de la MISMA razón social que la orden.
            //
            // Esto ya se validaba al confirmar el pago (`TesPagosController::getConfirmarPago`),
            // pero no acá — así que se podía emitir un eCheq desde una cuenta de otra entidad del
            // grupo y recién enterarse al final, con el instrumento ya cargado y el número de
            // eCheq gastado. Es el caso que lo destapó: la OPA 4521 (razón 1) terminó con un
            // abono de $100.000 sobre una cuenta del Macro de la razón 2. (2026-09-07)
            //
            // Si el pago debita una cuenta de una razón y el asiento imputa la deuda en el plan
            // de cuentas de otra, quedan dos contabilidades descuadradas entre sí.
            $idCuenta = $datos['id_cuenta_bancaria'] ?? $boleta->id_cuenta_bancaria ?? null;

            $this->validarCuentaDeRazonSocial($idCuenta, $opa->id_orden_pago, $opaRepo);

            $esEcheq = (int) $datos['id_forma_pago'] === self::FORMA_PAGO_ECHEQ;

            return TesPagosParciales::create([
                'id_pago'               => $fecha->id_pago,
                'id_fecha_probable'     => $idFechaProbable,
                'fecha_registra'        => $this->fechaActual->toDateString(),
                // No nace confirmado: se confirma cuando el banco acredita.
                'fecha_confirma_pago'   => null,
                'id_forma_pago'         => $datos['id_forma_pago'],
                'monto_pago'            => $monto,
                'monto_opa'             => $opa->monto_orden_pago,
                // Contra el monto PAGABLE, no contra `monto_orden_pago`: ese arrastra el bruto de
                // la factura, así que al pagar el neto correcto quedaba un "restante" igual al
                // débito. Es el número que el modal de Confirmar Pago muestra como "Monto
                // Restante Actual", y decía $576 sobre una orden ya saldada. (2026-09-06)
                'monto_restante'        => max(0, $tope - ($yaEmitido + $monto)),
                'id_usuario'            => $this->user->cod_usuario ?? null,
                // El eCheq espera que el banco le asigne número; una transferencia no.
                'id_estado_instrumento' => $esEcheq ? self::PENDIENTE_EMISION : self::EMITIDO,
                'fecha_emision_echeq'   => $fecha->fecha_probable_pago,
                'id_banco_emisor'       => $this->resolverBancoEmisor(
                    $idCuenta,
                    $datos['id_banco_emisor'] ?? null
                ),
                // La cuenta de origen se guarda EN EL ABONO, no en la boleta: cada pago de una
                // misma orden puede salir de una cuenta distinta. Hasta el 2026-09-06 esto vivía
                // en `tb_tes_pago` y obligaba a que toda la orden se pagara desde una sola.
                // Si no se indica, se hereda la de la boleta para no perder el dato.
                'id_cuenta_bancaria'    => $idCuenta,
            ]);
        });
    }

    /**
     * ¿El número de eCheq está libre? La unicidad es GLOBAL: un eCheq cubre un solo abono.
     *
     * `$idAbonoExcluir` permite revalidar el propio número al editarlo sin chocar consigo mismo.
     */
    public function numeroEcheqDisponible(?string $numero, $idAbonoExcluir = null): bool
    {
        $numero = trim((string) $numero);

        if ($numero === '') {
            return false;
        }

        return !TesPagosParciales::where('numero_echeq', $numero)
            ->when(!is_null($idAbonoExcluir), fn($q) => $q->where('id_pago_parcial', '!=', $idAbonoExcluir))
            ->exists();
    }

    /**
     * Guarda el número de un eCheq como BORRADOR: queda persistido pero el abono no avanza de
     * estado hasta que se confirme la OP completa.
     *
     * Se guarda con trim: en la columna vieja `num_cheque` hay valores con espacios al borde y
     * no queremos arrastrar eso.
     */
    public function guardarBorradorNumero($idAbono, ?string $numero): TesPagosParciales
    {
        $abono = TesPagosParciales::find($idAbono);

        if (is_null($abono)) {
            throw new \Exception("No se encontró el abono {$idAbono}.");
        }

        if (!in_array((int) $abono->id_estado_instrumento, [self::BORRADOR, self::PENDIENTE_EMISION], true)) {
            throw new \Exception(
                'Solo se puede cargar el número de un pago que todavía no fue emitido.'
            );
        }

        $numero = trim((string) $numero);

        if ($numero !== '' && !$this->numeroEcheqDisponible($numero, $idAbono)) {
            throw new \Exception("El número de eCheq {$numero} ya está usado en otro pago.");
        }

        $abono->numero_echeq = $numero === '' ? null : $numero;
        // `num_cheque` se mantiene en sincronía: es la columna que leen las pantallas viejas
        // y el comprobante de pago que ya existe.
        $abono->num_cheque = $abono->numero_echeq;
        $abono->save();

        return $abono;
    }

    /**
     * Cambia la forma de pago de UN instrumento.
     *
     * Se elige por instrumento y no al confirmar la orden porque una misma OP puede pagarse con
     * un eCheq y una transferencia a la vez (requerimiento, punto 2).
     *
     * Al pasar de eCheq a otra forma se borra el número: un pago que no es eCheq no lo tiene, y
     * dejarlo cargado bloquearía ese número para otro eCheq que sí lo necesite.
     */
    public function cambiarFormaPago($idAbono, $idFormaPago): TesPagosParciales
    {
        $abono = TesPagosParciales::find($idAbono);

        if (is_null($abono)) {
            throw new \Exception("No se encontró el abono {$idAbono}.");
        }

        if (!in_array((int) $abono->id_estado_instrumento, [self::BORRADOR, self::PENDIENTE_EMISION], true)) {
            throw new \Exception('Solo se puede cambiar la forma de pago de un pago que todavía no fue emitido.');
        }

        $abono->id_forma_pago = $idFormaPago;

        if ((int) $idFormaPago !== self::FORMA_PAGO_ECHEQ) {
            $abono->numero_echeq = null;
            $abono->num_cheque = null;
        }

        $abono->save();

        return $abono;
    }

    /**
     * Confirma la emisión de TODOS los eCheq de una OP, en un solo acto.
     *
     * Exige que ninguno haya quedado sin número: el requerimiento pide que se carguen todos
     * juntos y se confirmen de una.
     */
    public function confirmarEmisionDeOpa($idOpa, TestOrdenPagoRepository $opaRepo): int
    {
        return DB::transaction(function () use ($idOpa, $opaRepo) {
            $pendientes = TesPagosParciales::whereIn('id_pago', $this->boletasDeOpa($idOpa))
                ->whereIn('id_estado_instrumento', [self::BORRADOR, self::PENDIENTE_EMISION])
                ->get();

            if ($pendientes->isEmpty()) {
                throw new \Exception('Esta orden de pago no tiene eCheq pendientes de emisión.');
            }

            // Solo el eCheq necesita numero: una transferencia se emite y listo. Por eso la
            // exigencia se aplica unicamente a los eCheq de la orden.
            $sinNumero = $pendientes
                ->filter(fn($p) => (int) $p->id_forma_pago === self::FORMA_PAGO_ECHEQ)
                ->filter(fn($p) => empty(trim((string) $p->numero_echeq)));

            if ($sinNumero->isNotEmpty()) {
                throw new \Exception(
                    'Faltan cargar ' . $sinNumero->count() . ' número(s) de eCheq. '
                    . 'Se confirman todos juntos.'
                );
            }

            foreach ($pendientes as $p) {
                $p->id_estado_instrumento = self::EMITIDO;
                $p->save();
            }

            // Emitir todavía no acredita, así que esto normalmente deja la OP igual. Se recalcula
            // para no depender de supuestos.
            $opaRepo->recalcularEstadoOpa($idOpa);

            return $pendientes->count();
        });
    }

    /**
     * Marca un eCheq como ACREDITADO. Es el único evento del ciclo que llega desde la
     * conciliación bancaria.
     */
    public function marcarAcreditado($idAbono, $fechaAcreditacion, TestOrdenPagoRepository $opaRepo): TesPagosParciales
    {
        return DB::transaction(function () use ($idAbono, $fechaAcreditacion, $opaRepo) {
            $abono = TesPagosParciales::find($idAbono);

            if (is_null($abono)) {
                throw new \Exception("No se encontró el abono {$idAbono}.");
            }

            if ((int) $abono->id_estado_instrumento !== self::EMITIDO) {
                throw new \Exception('Solo se puede acreditar un eCheq que esté emitido.');
            }

            // No se acredita un eCheq cuyo PAGO todavía no se confirmó.
            //
            // Acreditar dice que la plata salió del banco. Pero el asiento contable y el descuento
            // del saldo de la cuenta se hacen **solo** al confirmar el pago
            // (`TesPagosController::getConfirmarPago`): no hay una sola línea que los genere en
            // este camino. Sin esta guarda, un eCheq podía recorrer todo su ciclo —emitir, número,
            // confirmar emisión, acreditar— sin que la contabilidad se enterara ni el saldo de la
            // cuenta se moviera. La orden figuraba PAGADA con plata que el sistema nunca debitó.
            //
            // El orden correcto es: emitir -> número -> confirmar emisión -> CONFIRMAR PAGO ->
            // acreditar. Relevado el 2026-09-10: 0 eCheq acreditados en esa situación en las dos
            // bases, y 2 emitidos que ahora tienen que pasar por Confirmar Pago primero.
            //
            // El RECHAZO no lleva esta guarda a propósito: un eCheq que el banco devolvió tiene
            // que poder registrarse siempre, esté el pago confirmado o no.
            $boletaDelAbono = TesPagoEntity::find($abono->id_pago);

            if (is_null($boletaDelAbono?->fecha_confirma_pago)) {
                throw new \Exception(
                    'Este eCheq todavía no tiene el pago confirmado, así que no se puede acreditar. '
                        . 'Andá a Pagos y confirmá el pago de esta orden: ahí se genera el asiento '
                        . 'contable y se descuenta el saldo de la cuenta. Después volvé a acreditarlo.'
                );
            }

            $abono->id_estado_instrumento = self::ACREDITADO;
            $abono->fecha_confirma_pago   = $fechaAcreditacion;
            $abono->save();

            $opaRepo->recalcularEstadoOpa($this->opaDeAbono($abono));

            return $abono;
        });
    }

    /**
     * Marca un eCheq como RECHAZADO. **Carga manual del usuario** — confirmado por negocio el
     * 2026-09-03: el rechazo no llega desde la conciliación bancaria.
     *
     * Al perder la fecha de confirmación deja de contar como cobrado, así que la OP vuelve sola
     * al estado que le corresponde.
     */
    public function marcarRechazado($idAbono, ?string $motivo, TestOrdenPagoRepository $opaRepo): TesPagosParciales
    {
        return DB::transaction(function () use ($idAbono, $motivo, $opaRepo) {
            $abono = TesPagosParciales::find($idAbono);

            if (is_null($abono)) {
                throw new \Exception("No se encontró el abono {$idAbono}.");
            }

            if (!in_array((int) $abono->id_estado_instrumento, [self::EMITIDO, self::ACREDITADO], true)) {
                throw new \Exception('Solo se puede rechazar un eCheq que haya sido emitido.');
            }

            $abono->id_estado_instrumento = self::RECHAZADO;
            $abono->motivo_rechazo        = $motivo;
            $abono->fecha_rechazo         = $this->fechaActual;
            $abono->fecha_confirma_pago   = null;
            $abono->save();

            $opaRepo->recalcularEstadoOpa($this->opaDeAbono($abono));

            return $abono;
        });
    }

    private function opaDeAbono(TesPagosParciales $abono)
    {
        return TesPagoEntity::where('id_pago', $abono->id_pago)->value('id_orden_pago');
    }

    /**
     * Corrige un pago que todavía NO salió: su cuenta de origen y/o su monto.
     *
     * Hasta el 2026-09-07 un eCheq ya emitido pero sin número no se podía tocar: si se elegía la
     * cuenta equivocada, el único camino era anular y reemitir la ORDEN ENTERA, que además le
     * cambia el número de OPA. Un mazazo para corregir un banco mal tipeado.
     *
     * El requerimiento lo habilita explícitamente: *"mientras el pago esté creado pero sin
     * confirmar, la orden es editable por completo"*. Y hay precedente directo — `cambiarFormaPago()`
     * ya edita un abono en este mismo estado, con este mismo guard.
     *
     * @param array $datos ['id_cuenta_bancaria' => ?int, 'monto' => ?float]
     */
    public function editarAbonoNoEmitido($idAbono, array $datos, TestOrdenPagoRepository $opaRepo): TesPagosParciales
    {
        return DB::transaction(function () use ($idAbono, $datos, $opaRepo) {
            $abono = TesPagosParciales::find($idAbono);

            if (is_null($abono)) {
                throw new \Exception("No se encontró el abono {$idAbono}.");
            }

            // El límite es "todavía no salió". Un EMITIDO ya tiene número del banco y un
            // ACREDITADO ya movió plata: esos van por rechazo, no por edición.
            if (!in_array((int) $abono->id_estado_instrumento, [self::BORRADOR, self::PENDIENTE_EMISION], true)) {
                throw new \Exception('Solo se puede editar un pago que todavía no fue emitido.');
            }

            $idOpa = $this->opaDeAbono($abono);

            if (array_key_exists('id_cuenta_bancaria', $datos)) {
                $idCuenta = $datos['id_cuenta_bancaria'];

                $this->validarCuentaDeRazonSocial($idCuenta, $idOpa, $opaRepo);

                $abono->id_cuenta_bancaria = $idCuenta;
                // El banco sale de la cuenta: si se cambia una, la otra tiene que seguirla, si no
                // queda un eCheq apuntando al banco viejo con la cuenta nueva.
                $abono->id_banco_emisor = $this->resolverBancoEmisor($idCuenta, null);
            }

            if (array_key_exists('monto', $datos) && !is_null($datos['monto'])) {
                $monto = (float) $datos['monto'];

                if (self::aCentavos($monto) <= 0) {
                    throw new \Exception('Hay que indicar el monto del pago.');
                }

                // El mismo tope que al emitir, pero sin contarse a sí mismo: si no, editar un
                // abono de $100 a $101 se rechazaría por "ya emitido $100".
                $tope = $this->topeDeOpa($idOpa, $opaRepo);
                $yaEmitido = $this->emitidoVivoDeOpa($idOpa, $abono->id_pago_parcial);

                if (self::aCentavos($yaEmitido + $monto) > self::aCentavos($tope)) {
                    $disponible = max(0, $tope - $yaEmitido);

                    throw new \Exception(sprintf(
                        'El pago se pasa de lo que hay que pagar en esta orden. '
                            . 'A pagar: $%s. Ya emitido: $%s. Disponible: $%s.',
                        number_format($tope, 2, ',', '.'),
                        number_format($yaEmitido, 2, ',', '.'),
                        number_format($disponible, 2, ',', '.')
                    ));
                }

                $abono->monto_pago     = $monto;
                $abono->monto_restante = max(0, $tope - ($yaEmitido + $monto));
            }

            $abono->save();

            $opaRepo->recalcularEstadoOpa($idOpa);

            return $abono;
        });
    }

    /**
     * Anula un pago que todavía no salió y **devuelve su fecha al plan**.
     *
     * Es la salida para un abono que directamente no tendría que existir (el caso que lo motivó:
     * el abono 1222, cargado sobre una cuenta de otra razón social). Sin esto, corregirlo obligaba
     * a anular la orden completa.
     *
     * No se borra la fila: queda en ANULADO (6). El estado existía en el catálogo desde el
     * principio pero solo lo usaba la anulación de la orden entera. Un abono anulado no cuenta
     * para nada — ni para el tope, ni para lo pagado, ni para el estado de la orden — y su fecha
     * planificada vuelve a aparecer en "A emitir" para reemitirla bien.
     */
    public function anularAbonoNoEmitido($idAbono, ?string $motivo, TestOrdenPagoRepository $opaRepo): TesPagosParciales
    {
        return DB::transaction(function () use ($idAbono, $motivo, $opaRepo) {
            $abono = TesPagosParciales::find($idAbono);

            if (is_null($abono)) {
                throw new \Exception("No se encontró el abono {$idAbono}.");
            }

            if (!in_array((int) $abono->id_estado_instrumento, [self::BORRADOR, self::PENDIENTE_EMISION], true)) {
                throw new \Exception(
                    'Solo se puede anular un pago que todavía no fue emitido. '
                        . 'Un eCheq ya emitido se da de baja por rechazo.'
                );
            }

            $abono->id_estado_instrumento = self::ANULADO;
            $abono->motivo_rechazo        = $motivo;
            $abono->fecha_rechazo         = $this->fechaActual;
            $abono->fecha_confirma_pago   = null;
            $abono->save();

            $opaRepo->recalcularEstadoOpa($this->opaDeAbono($abono));

            return $abono;
        });
    }

    /**
     * La cuenta de origen tiene que pertenecer a una de las razones sociales de la orden.
     *
     * Se valida contra el CONJUNTO, no contra una sola: una orden que mezcla facturas de dos
     * entidades (anomalía de datos, pero existe) no tiene una razón social única, y elegir una al
     * azar hacía que el sistema exigiera la cuenta equivocada. Ver `razonesSocialesDeOpa()`.
     *
     * Un ANTICIPO no tiene facturas imputadas, así que no tiene razón social: ahí no se valida.
     */
    private function validarCuentaDeRazonSocial($idCuenta, $idOpa, TestOrdenPagoRepository $opaRepo): void
    {
        if (is_null($idCuenta)) {
            return;
        }

        $razonesOpa = $opaRepo->razonesSocialesDeOpa($idOpa);

        if (empty($razonesOpa)) {
            return;
        }

        $razonCuenta = DB::table('tb_tes_cuentas_bancarias')
            ->where('id_cuenta_bancaria', $idCuenta)
            ->value('id_razon');

        if (!is_null($razonCuenta) && !in_array((int) $razonCuenta, $razonesOpa, true)) {
            throw new \Exception(
                'La cuenta de origen no pertenece a la razón social de la orden. '
                    . 'Elegí una cuenta de la misma razón social para emitir este pago.'
            );
        }
    }

    /** Lo máximo que se puede pagar de una orden: lo imputado menos el débito de liquidación. */
    private function topeDeOpa($idOpa, TestOrdenPagoRepository $opaRepo): float
    {
        $tope = $opaRepo->montoPagableOpa($idOpa);

        if (self::aCentavos($tope) <= 0) {
            // Un ANTICIPO no tiene facturas: su tope es su propio monto.
            $tope = (float) TesOrdenPagoEntity::where('id_orden_pago', $idOpa)->value('monto_orden_pago');
        }

        return $tope;
    }

    /**
     * Lo ya emitido sobre una orden, sin contar RECHAZADOS ni ANULADOS: esa plata no salió (o
     * volvió), así que no puede seguir ocupando lugar en el tope.
     */
    private function emitidoVivoDeOpa($idOpa, $idAbonoExcluir = null): float
    {
        return (float) TesPagosParciales::whereIn(
            'id_pago',
            TesPagoEntity::where('id_orden_pago', $idOpa)->pluck('id_pago')
        )
            ->when(!is_null($idAbonoExcluir), fn($q) => $q->where('id_pago_parcial', '!=', $idAbonoExcluir))
            ->where(function ($q) {
                $q->whereNull('id_estado_instrumento')
                    ->orWhereNotIn('id_estado_instrumento', [self::RECHAZADO, self::ANULADO]);
            })
            ->sum('monto_pago');
    }

    /**
     * Agrega a cada fila `monto_pagable` y `monto_disponible`, que es lo que la pantalla tiene que
     * mostrar y ofrecer para cargar.
     *
     *   monto_pagable    = por cada factura de la orden, min(imputado, neto − débito)
     *   monto_disponible = monto_pagable − lo ya emitido (sin contar rechazados ni anulados)
     *
     * Es el mismo criterio que aplica el freno de `emitirPagoDeFecha()`: la pantalla y la
     * validación tienen que decir lo mismo, si no el operador carga un importe que después
     * rebota.
     *
     * Se resuelve en dos consultas agrupadas y no una por fila: estos listados traen varias
     * decenas de órdenes.
     */
    private function agregarMontosPagables($filas): void
    {
        $idsOpa = collect($filas)->pluck('id_orden_pago')->filter()->unique()->values();

        if ($idsOpa->isEmpty()) {
            return;
        }

        // Lo pagable por orden. LEAST/GREATEST replican el min()/max() de montoPagableOpa().
        $pagables = DB::table('tb_tes_opa_factura as pf')
            ->join('tb_facturacion_datos as f', 'f.id_factura', '=', 'pf.id_factura')
            ->whereIn('pf.id_orden_pago', $idsOpa)
            ->groupBy('pf.id_orden_pago')
            ->select('pf.id_orden_pago', DB::raw(
                'SUM(LEAST(pf.monto_aplicado, GREATEST(0, f.total_neto - COALESCE(f.total_debitado_liquidacion, 0)))) AS pagable'
            ))
            ->pluck('pagable', 'pf.id_orden_pago');

        // Lo ya emitido por orden, excluyendo rechazados y anulados (esa plata no salió o volvió).
        $emitidos = DB::table('tb_tes_pago_parcial as pp')
            ->join('tb_tes_pago as p', 'p.id_pago', '=', 'pp.id_pago')
            ->whereIn('p.id_orden_pago', $idsOpa)
            ->where(function ($q) {
                $q->whereNull('pp.id_estado_instrumento')
                    ->orWhereNotIn('pp.id_estado_instrumento', [self::RECHAZADO, self::ANULADO]);
            })
            ->groupBy('p.id_orden_pago')
            ->select('p.id_orden_pago', DB::raw('SUM(pp.monto_pago) AS emitido'))
            ->pluck('emitido', 'p.id_orden_pago');

        // Razones sociales de cada orden, para que el front solo ofrezca cuentas de esa entidad.
        // Antes el desplegable listaba TODAS las cuentas del grupo y nada frenaba elegir una
        // ajena: el error saltaba recién al confirmar el pago. (2026-09-07)
        //
        // Se traen TODAS, no un `MIN(id_locatorio)`. Ese MIN elegía una al azar cuando la orden
        // mezclaba facturas de dos entidades: la OPA-1102 se filtraba correctamente por razón 2
        // pero se mostraba como razón 1, que son $273.838 de $12.312.369. (2026-09-09)
        $razones = DB::table('tb_tes_orden_pago_detalle as od')
            ->join('tb_facturacion_datos as fd', 'fd.id_factura', '=', 'od.id_factura')
            ->whereIn('od.id_orden_pago', $idsOpa)
            ->whereNotNull('fd.id_locatorio')
            ->distinct()
            ->get(['od.id_orden_pago', 'fd.id_locatorio'])
            ->groupBy('id_orden_pago')
            ->map(fn($g) => $g->pluck('id_locatorio')->map(fn($r) => (int) $r)->sort()->values()->all());

        foreach ($filas as $fila) {
            // Vacío en un ANTICIPO (no tiene facturas): ahí el front no filtra nada.
            $fila->razones = $razones[$fila->id_orden_pago] ?? [];

            // `id_razon` solo cuando es inequívoca. Con más de una no hay respuesta única, y
            // contestar cualquiera es lo que causaba el bug.
            $fila->id_razon = count($fila->razones) === 1 ? $fila->razones[0] : null;

            // Una orden sin facturas imputadas (un ANTICIPO) no tiene débito que descontar: su
            // tope es su propio monto, igual que en el freno.
            $pagable = (float) ($pagables[$fila->id_orden_pago] ?? 0);

            if ($pagable <= 0) {
                $pagable = (float) $fila->monto_orden_pago;
            }

            $emitido = (float) ($emitidos[$fila->id_orden_pago] ?? 0);

            $fila->monto_pagable    = round($pagable, 2);
            $fila->monto_disponible = round(max(0, $pagable - $emitido), 2);
        }
    }

    /**
     * Pagos planificados que todavía no se emitieron, más los eCheq emitidos que esperan número.
     *
     * Son las dos cosas que Tesorería tiene pendientes de resolver sobre una orden ya confirmada:
     * definir monto y forma de los que faltan, y poner el número a los eCheq que el banco emitió.
     *
     * Las filas sin `id_pago_parcial` son plan puro: todavía no existe el instrumento.
     */
    public function listarPendientesDeNumero($idBanco = null, $numeroOpa = null, $idRazon = null)
    {
        // 1) Fechas del plan sin pago emitido.
        //
        // Es una query cruda (DB::table), no Eloquent: no trae relaciones. Sin el leftJoin a
        // proveedor/prestador, el front no tenía de dónde sacar el nombre del beneficiario y la
        // vista "A emitir" mostraba todo como SIN BENEFICIARIO (hallado el 2026-09-05). Se anida
        // en un objeto `proveedor`/`prestador` para que quede con la misma forma que usan
        // `nombreBeneficiario()` del front y el resto de los listados de esta pantalla.
        $planificados = DB::table('tb_tes_fecha_probable_pago as fp')
            ->join('tb_tes_pago as p', 'p.id_pago', '=', 'fp.id_pago')
            ->join('tb_tes_orden_pago as o', 'o.id_orden_pago', '=', 'p.id_orden_pago')
            ->leftJoin('tb_proveedor as prov', 'prov.cod_proveedor', '=', 'o.id_proveedor')
            ->leftJoin('tb_prestador as pres', 'pres.cod_prestador', '=', 'o.id_prestador')
            // Un abono ANULADO o RECHAZADO no ocupa su fecha: la fecha vuelve al plan y se puede
            // volver a emitir. Sin esta exclusión, anular un pago mal cargado dejaba la fecha
            // muerta para siempre y la orden imposible de completar. (2026-09-07)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('tb_tes_pago_parcial as pp')
                    ->whereColumn('pp.id_fecha_probable', 'fp.id_fecha_probable')
                    ->where(function ($w) {
                        $w->whereNull('pp.id_estado_instrumento')
                            ->orWhereNotIn('pp.id_estado_instrumento', [self::RECHAZADO, self::ANULADO]);
                    });
            })
            ->where('p.id_estado_orden_pago', '!=', TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO)
            ->whereIn('o.id_estado_orden_pago', $this->estadosOpaViva())
            ->tap(fn($q) => $this->filtrarPorOpaYRazon($q, $numeroOpa, $idRazon, 'o.num_orden_pago', 'o.id_orden_pago'))
            ->select([
                'fp.id_fecha_probable',
                'fp.fecha_probable_pago',
                'fp.orden_cuotas',
                'p.id_pago',
                'o.id_orden_pago',
                'o.num_orden_pago',
                'o.monto_orden_pago',
                'o.tipo_factura',
                'o.id_proveedor',
                'o.id_prestador',
                'prov.cuit as proveedor_cuit',
                'prov.razon_social as proveedor_razon_social',
                'pres.cuit as prestador_cuit',
                'pres.razon_social as prestador_razon_social',
            ])
            ->orderBy('o.num_orden_pago')
            ->orderBy('fp.orden_cuotas')
            ->get()
            ->map(function ($fila) {
                $fila->proveedor = $fila->id_proveedor
                    ? (object) ['cuit' => $fila->proveedor_cuit, 'razon_social' => $fila->proveedor_razon_social]
                    : null;
                $fila->prestador = $fila->id_prestador
                    ? (object) ['cuit' => $fila->prestador_cuit, 'razon_social' => $fila->prestador_razon_social]
                    : null;
                return $fila;
            });

        // Cuánto se puede pagar realmente de cada orden: el bruto de la factura MENOS el débito
        // de liquidación, y menos lo que ya se emitió. Sin esto la pantalla mostraba el total de
        // la orden ($7.866) y el operador cargaba ese importe, que el freno rechazaba porque lo
        // pagable eran $7.290. Mostrar el bruto y después rebotar el pago es hacerle perder el
        // viaje. (2026-09-06)
        $this->agregarMontosPagables($planificados);

        // 2) eCheq ya definidos, esperando el número del banco.
        $sinNumero = TesPagosParciales::query()
            ->join('tb_tes_pago as p', 'p.id_pago', '=', 'tb_tes_pago_parcial.id_pago')
            ->join('tb_tes_orden_pago as o', 'o.id_orden_pago', '=', 'p.id_orden_pago')
            ->where('tb_tes_pago_parcial.id_estado_instrumento', self::PENDIENTE_EMISION)
            ->when(!is_null($idBanco), fn($q) => $q->where('tb_tes_pago_parcial.id_banco_emisor', $idBanco))
            ->whereIn('o.id_estado_orden_pago', $this->estadosOpaViva())
            ->tap(fn($q) => $this->filtrarPorOpaYRazon($q, $numeroOpa, $idRazon, 'o.num_orden_pago', 'o.id_orden_pago'))
            ->select([
                'tb_tes_pago_parcial.*',
                'o.id_orden_pago',
                'o.num_orden_pago',
                'o.monto_orden_pago',
                'o.tipo_factura',
                'o.id_proveedor',
                'o.id_prestador',
            ])
            ->with(['bancoEmisor', 'cuentaBancaria', 'formaPago', 'pago.opa.proveedor', 'pago.opa.prestador'])
            ->get();

        // Estas filas ahora se pueden editar en la pantalla (cuenta y monto), así que necesitan
        // los mismos datos que "A emitir": la razón social para filtrar el desplegable de cuentas
        // y el disponible para validar el monto. (2026-09-07)
        $this->agregarMontosPagables($sinNumero);

        return ['planificados' => $planificados, 'sin_numero' => $sinNumero];
    }

    /**
     * Filtro compartido por N° de OPA y razón social (la entidad pagadora del grupo — Grupo
     * Alba, Tripalium, Medicina Privada, etc. — NO el proveedor/prestador beneficiario) para los
     * listados de Carga de eCheq.
     *
     * El N° de OPA se compara NUMÉRICAMENTE, no con LIKE: los correlativos viejos vienen con
     * ceros a la izquierda ('OPA-0999') y los nuevos no ('OPA-14358'), así que "1435" haría
     * matchear de más con un LIKE (ver `sql-fix-numeracion-opa.md`). El usuario puede tipear
     * indistinto "OPA-1435", "1435" o "opa 1435".
     *
     * La razón social de la orden sale de sus facturas (`tb_tes_orden_pago_detalle` ->
     * `tb_facturacion_datos.id_locatorio` -> `tb_razones_sociales`), no de una columna propia de
     * la OPA: una orden de anticipo (sin facturas) no matchea contra ningún `id_razon`.
     */
    private function filtrarPorOpaYRazon($query, $numeroOpa, $idRazon, string $colNumOpa, string $colIdOrdenPago): void
    {
        if (!empty($numeroOpa)) {
            $numero = preg_replace('/\D/', '', (string) $numeroOpa);

            if ($numero !== '') {
                $query->whereRaw("CAST(REPLACE({$colNumOpa}, 'OPA-', '') AS UNSIGNED) = ?", [(int) $numero]);
            }
        }

        if (!empty($idRazon)) {
            $query->whereExists(function ($q) use ($idRazon, $colIdOrdenPago) {
                $q->select(DB::raw(1))
                    ->from('tb_tes_orden_pago_detalle as od')
                    ->join('tb_facturacion_datos as fd', 'fd.id_factura', '=', 'od.id_factura')
                    ->whereColumn('od.id_orden_pago', $colIdOrdenPago)
                    ->where('fd.id_locatorio', $idRazon);
            });
        }
    }

    /** Estados en los que una OP sigue viva y sus pagos pueden trabajarse. */
    private function estadosOpaViva(): array
    {
        return [
            TestOrdenPagoRepository::ESTADO_OPA_PENDIENTE,
            TestOrdenPagoRepository::ESTADO_OPA_APROBADO,
            TestOrdenPagoRepository::ESTADO_OPA_EN_PROCESO,
            TestOrdenPagoRepository::ESTADO_OPA_PAGO_PARCIAL,
        ];
    }

    /**
     * eCheq ya EMITIDOS, esperando que el banco los debite.
     *
     * Es la contracara del listado de pendientes de número: acá están los que ya salieron y sobre
     * los que corresponde acreditar (cuando el banco confirma) o rechazar (carga manual).
     *
     * Se incluyen los ACREDITADOS de los últimos días para que quede a la vista lo recién
     * confirmado y se pueda rechazar si el eCheq vuelve después.
     */
    public function listarEmitidos($idBanco = null, $numeroOpa = null, $idRazon = null)
    {
        return TesPagosParciales::query()
            ->select([
                'tb_tes_pago_parcial.id_pago_parcial',
                'tb_tes_pago_parcial.id_pago',
                'tb_tes_pago_parcial.numero_echeq',
                'tb_tes_pago_parcial.monto_pago',
                'tb_tes_pago_parcial.fecha_emision_echeq',
                'tb_tes_pago_parcial.fecha_confirma_pago',
                'tb_tes_pago_parcial.id_banco_emisor',
                'tb_tes_pago_parcial.id_cuenta_bancaria',
                'tb_tes_pago_parcial.id_estado_instrumento',
                'tb_tes_pago_parcial.motivo_rechazo',
                'tb_tes_orden_pago.id_orden_pago',
                'tb_tes_orden_pago.num_orden_pago',
                'tb_tes_orden_pago.tipo_factura',
                'tb_tes_orden_pago.id_proveedor',
                'tb_tes_orden_pago.id_prestador',
            ])
            ->join('tb_tes_pago', 'tb_tes_pago.id_pago', '=', 'tb_tes_pago_parcial.id_pago')
            ->join('tb_tes_orden_pago', 'tb_tes_orden_pago.id_orden_pago', '=', 'tb_tes_pago.id_orden_pago')
            ->whereIn('tb_tes_pago_parcial.id_estado_instrumento', [self::EMITIDO, self::ACREDITADO])
            ->when(!is_null($idBanco), fn($q) => $q->where('tb_tes_pago_parcial.id_banco_emisor', $idBanco))
            ->tap(fn($q) => $this->filtrarPorOpaYRazon($q, $numeroOpa, $idRazon, 'tb_tes_orden_pago.num_orden_pago', 'tb_tes_orden_pago.id_orden_pago'))
            ->with(['bancoEmisor', 'cuentaBancaria', 'estadoInstrumento', 'pago.opa.proveedor', 'pago.opa.prestador'])
            // Los que todavía esperan acreditación van primero: son los que requieren acción.
            ->orderBy('tb_tes_pago_parcial.id_estado_instrumento')
            ->orderBy('tb_tes_pago_parcial.fecha_emision_echeq')
            ->get();
    }

    /**
     * Nombre del beneficiario de una OP, para los listados y comprobantes.
     *
     * El tipo lo decide `tipo_factura` de la OP, no la presencia de `id_proveedor`/`id_prestador`:
     * hay filas sucias con **los dos** cargados, y elegir por coalesce devuelve el equivocado.
     *
     * Devuelve un texto siempre: en las OPs viejas cualquiera de las dos relaciones puede venir
     * en null, y un listado no es lugar para reventar.
     */
    public static function nombreBeneficiario($opa): string
    {
        if (is_null($opa)) {
            return 'SIN BENEFICIARIO';
        }

        $ente = strtoupper((string) $opa->tipo_factura) === 'PROVEEDOR'
            ? $opa->proveedor
            : $opa->prestador;

        $ente = $ente ?? $opa->proveedor ?? $opa->prestador;

        if (is_null($ente)) {
            return 'SIN BENEFICIARIO';
        }

        $cuit  = trim((string) ($ente->cuit ?? ''));
        $razon = trim((string) ($ente->razon_social ?? ''));

        return trim($cuit === '' ? $razon : "{$cuit} - {$razon}") ?: 'SIN BENEFICIARIO';
    }
}
