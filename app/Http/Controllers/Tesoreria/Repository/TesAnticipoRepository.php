<?php

namespace App\Http\Controllers\Tesoreria\Repository;

use App\Models\Tesoreria\TesOrdenPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Anticipos a prestadores y proveedores (punto 8).
 *
 * El circuito, en criollo:
 *
 *   1. Se le paga a un prestador SIN que haya factura. Eso es una OP de tipo ANTICIPO: no tiene
 *      facturas, solo un importe. Se paga por el circuito normal de eCheq.
 *   2. Cuando el pago se acredita, ese dinero queda como SALDO A FAVOR del prestador.
 *   3. Cuando llegan las facturas, se crea una OP de tipo APLICACION que consume parte de ese
 *      saldo e imputa a las facturas. **No genera un pago nuevo**: la plata ya salió en el paso 1.
 *   4. Cuando el saldo llega a cero, el anticipo pasa a CONSUMIDA.
 *
 * ═══ CUIDADO con la palabra "anticipo" ═══
 *
 * `tb_tes_pago.anticipo` y `monto_anticipado` son un concepto VIEJO y distinto: pagar por
 * adelantado parte de una OPA que ya tiene facturas. Este repositorio no los toca. Ver la
 * migración 2026_09_03_100700.
 */
class TesAnticipoRepository
{
    const TIPO_NORMAL     = 'NORMAL';
    const TIPO_REEMPLAZO  = 'REEMPLAZO';
    const TIPO_ANTICIPO   = 'ANTICIPO';
    const TIPO_APLICACION = 'APLICACION';

    private $opaRepository;
    private $user;
    private $fechaActual;

    public function __construct(TestOrdenPagoRepository $opaRepository)
    {
        $this->opaRepository = $opaRepository;
        $this->user          = Auth::user();
        $this->fechaActual   = Carbon::now('America/Argentina/Buenos_Aires');
    }

    private static function aCentavos($monto): int
    {
        return (int) round(((float) $monto) * 100);
    }

    private static function aPesos(int $centavos): float
    {
        return round($centavos / 100, 2);
    }

    /**
     * Crea un anticipo: una OP sin facturas, por un importe, a nombre de un beneficiario.
     *
     * Nace en PENDIENTE y se paga por el circuito normal. Recién cuando el pago se acredita
     * genera saldo disponible — antes de eso no hay plata que aplicar.
     *
     * @param string $tipoBeneficiario 'PROVEEDOR' o 'PRESTADOR'
     */
    public function crearAnticipo($idBeneficiario, string $tipoBeneficiario, $monto, ?string $observaciones = null, array $cuotas = [], $idRazon = null): TesOrdenPagoEntity
    {
        $tipoBeneficiario = strtoupper(trim($tipoBeneficiario));

        if (!in_array($tipoBeneficiario, ['PROVEEDOR', 'PRESTADOR'], true)) {
            throw new \Exception('El tipo de beneficiario tiene que ser PROVEEDOR o PRESTADOR.');
        }

        if (empty($idBeneficiario)) {
            throw new \Exception('Hay que indicar el beneficiario del anticipo.');
        }

        if (self::aCentavos($monto) <= 0) {
            throw new \Exception('El monto del anticipo tiene que ser mayor a cero.');
        }

        // La razón social es OBLIGATORIA. Un anticipo no tiene facturas de donde derivarla, y sin
        // ella la guarda que controla de qué cuenta sale la plata no tiene contra qué comparar:
        // se podía pagar desde la cuenta de cualquier entidad del grupo. (2026-09-25)
        if (empty($idRazon)) {
            throw new \Exception('Indicá la razón social que paga el anticipo.');
        }

        if (!DB::table('tb_razones_sociales')->where('id_razon', $idRazon)->exists()) {
            throw new \Exception('La razón social indicada no existe.');
        }

        $anticipo = TesOrdenPagoEntity::create([
            'id_proveedor'         => $tipoBeneficiario === 'PROVEEDOR' ? $idBeneficiario : null,
            'id_prestador'         => $tipoBeneficiario === 'PRESTADOR' ? $idBeneficiario : null,
            'monto_orden_pago'     => $monto,
            'id_moneda'            => 1,
            'fecha_emision'        => $this->fechaActual->toDateString(),
            'fecha_vencimiento'    => $this->fechaActual->toDateString(),
            'fecha_probable_pago'  => $this->fechaActual->toDateString(),
            'id_estado_orden_pago' => TestOrdenPagoRepository::ESTADO_OPA_PENDIENTE,
            'monto_anticipado'     => 0,
            'observaciones'        => $observaciones,
            'cod_usuario'          => $this->user->cod_usuario ?? null,
            'fecha_genera'         => $this->fechaActual,
            'id_factura'           => null,
            'tipo_factura'         => $tipoBeneficiario,
            'tipo_opa'             => self::TIPO_ANTICIPO,
            'id_razon'             => $idRazon,
        ]);

        // El cronograma se define acá mismo, igual que en Generar OPA. Sin esto el anticipo nacía
        // como una OPA suelta, sin boleta, y no aparecía en Pagos hasta pasar por "Confirmar OPA"
        // en el Gestor: el operador lo creaba y no lo encontraba en ningún lado. (2026-09-25)
        $cuotas = collect($cuotas)
            ->map(fn($c) => (array) $c)
            ->filter(fn($c) => !empty($c['fecha_probable_pago']))
            ->values();

        if ($cuotas->isNotEmpty()) {
            $this->opaRepository->crearCronogramaDeOpa($anticipo, $cuotas->all());
        }

        // El num_orden_pago lo pone el trigger: sin refresh vuelve en null.
        return $anticipo->refresh();
    }

    /**
     * Saldo todavía disponible de un anticipo.
     *
     * Es lo que se le pagó realmente **menos** lo que ya se aplicó a facturas. Se calcula, no se
     * guarda: un campo "saldo" se desfasa en cuanto una aplicación se anula por otro camino.
     */
    public function saldoDisponible($idAnticipo): float
    {
        $anticipo = TesOrdenPagoEntity::find($idAnticipo);

        if (is_null($anticipo) || $anticipo->tipo_opa !== self::TIPO_ANTICIPO) {
            return 0.0;
        }

        if ((int) $anticipo->id_estado_orden_pago === TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO) {
            return 0.0;
        }

        $pagado = self::aCentavos($this->opaRepository->montoPagadoOpa($idAnticipo));
        $aplicado = self::aCentavos($this->totalAplicado($idAnticipo));

        return self::aPesos(max(0, $pagado - $aplicado));
    }

    /** Suma de las aplicaciones vivas de un anticipo. Las anuladas no consumen saldo. */
    public function totalAplicado($idAnticipo): float
    {
        return (float) TesOrdenPagoEntity::where('id_opa_anticipo', $idAnticipo)
            ->where('tipo_opa', self::TIPO_APLICACION)
            ->where('id_estado_orden_pago', '!=', TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO)
            ->sum('monto_orden_pago');
    }

    /**
     * Aplica saldo de un anticipo a un conjunto de facturas.
     *
     * Crea una OP de tipo APLICACION que imputa a esas facturas y apunta al anticipo. **No crea
     * ningún pago**: la plata salió cuando se pagó el anticipo, y volver a registrarla acá la
     * contaría dos veces en la cuenta corriente.
     *
     * @param array $lineas [['id_factura' => int, 'monto' => float], ...]
     */
    public function aplicarAFacturas($idAnticipo, array $lineas, ?string $observaciones = null): TesOrdenPagoEntity
    {
        return DB::transaction(function () use ($idAnticipo, $lineas, $observaciones) {
            $anticipo = TesOrdenPagoEntity::find($idAnticipo);

            if (is_null($anticipo)) {
                throw new \Exception("No se encontró el anticipo {$idAnticipo}.");
            }

            if ($anticipo->tipo_opa !== self::TIPO_ANTICIPO) {
                throw new \Exception("La orden {$anticipo->num_orden_pago} no es un anticipo.");
            }

            if (empty($lineas)) {
                throw new \Exception('Hay que indicar al menos una factura.');
            }

            $totalCentavos = 0;

            foreach ($lineas as $l) {
                if (empty($l['id_factura'])) {
                    throw new \Exception('Cada línea necesita su factura.');
                }

                $c = self::aCentavos($l['monto'] ?? 0);

                if ($c <= 0) {
                    throw new \Exception('El monto a aplicar de cada factura tiene que ser mayor a cero.');
                }

                $totalCentavos += $c;
            }

            $disponible = self::aCentavos($this->saldoDisponible($idAnticipo));

            if ($totalCentavos > $disponible) {
                throw new \Exception(
                    'El anticipo no tiene saldo suficiente: disponible $'
                    . number_format(self::aPesos($disponible), 2, ',', '.')
                    . ', se intenta aplicar $' . number_format(self::aPesos($totalCentavos), 2, ',', '.') . '.'
                );
            }

            // Una factura no puede recibir saldo si ya está imputada en una OP viva: sería
            // pagarla dos veces. Se chequea antes de crear nada.
            foreach ($lineas as $l) {
                $vigente = $this->opaRepository->findByOpaVigenteFactura($l['id_factura']);

                if (!is_null($vigente)) {
                    throw new \Exception(
                        "La factura {$l['id_factura']} ya está en la orden de pago "
                        . "{$vigente->num_orden_pago}: no se le puede aplicar el anticipo."
                    );
                }
            }

            $aplicacion = TesOrdenPagoEntity::create([
                'id_proveedor'         => $anticipo->id_proveedor,
                'id_prestador'         => $anticipo->id_prestador,
                'monto_orden_pago'     => self::aPesos($totalCentavos),
                'id_moneda'            => $anticipo->id_moneda,
                'fecha_emision'        => $this->fechaActual->toDateString(),
                'fecha_vencimiento'    => $this->fechaActual->toDateString(),
                'fecha_probable_pago'  => $this->fechaActual->toDateString(),
                'id_estado_orden_pago' => TestOrdenPagoRepository::ESTADO_OPA_PENDIENTE,
                'monto_anticipado'     => 0,
                'observaciones'        => $observaciones,
                'cod_usuario'          => $this->user->cod_usuario ?? null,
                'fecha_genera'         => $this->fechaActual,
                'id_factura'           => count($lineas) === 1 ? $lineas[0]['id_factura'] : null,
                'tipo_factura'         => $anticipo->tipo_factura,
                'tipo_opa'             => self::TIPO_APLICACION,
                'id_opa_anticipo'      => $anticipo->id_orden_pago,
            ]);

            foreach ($lineas as $l) {
                TesOrdenPagoDetalleEntity::create([
                    'id_orden_pago' => $aplicacion->id_orden_pago,
                    'id_factura'    => $l['id_factura'],
                    'monto_factura' => self::aPesos(self::aCentavos($l['monto'])),
                    'tipo_factura'  => $anticipo->tipo_factura,
                    'factura_unida' => count($lineas) > 1 ? 1 : 0,
                ]);
            }

            $this->opaRepository->sincronizarPuenteDesdeDetalle($aplicacion->id_orden_pago);
            $this->opaRepository->recalcularEstadoOpa($aplicacion->id_orden_pago);
            $this->actualizarEstadoAnticipo($idAnticipo);

            return $aplicacion->refresh();
        });
    }

    /**
     * Pasa el anticipo a CONSUMIDA cuando ya no le queda saldo, y lo devuelve a PAGADO si una
     * anulación le liberó saldo de vuelta.
     *
     * Sólo toca anticipos que ya se cobraron: uno sin pagar sigue su ciclo normal.
     */
    public function actualizarEstadoAnticipo($idAnticipo): int
    {
        $anticipo = TesOrdenPagoEntity::find($idAnticipo);

        if (is_null($anticipo) || $anticipo->tipo_opa !== self::TIPO_ANTICIPO) {
            return 0;
        }

        if ((int) $anticipo->id_estado_orden_pago === TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO) {
            return TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO;
        }

        $pagado = self::aCentavos($this->opaRepository->montoPagadoOpa($idAnticipo));

        if ($pagado <= 0) {
            return (int) $anticipo->id_estado_orden_pago;
        }

        $disponible = self::aCentavos($this->saldoDisponible($idAnticipo));

        $nuevo = $disponible <= 0
            ? TestOrdenPagoRepository::ESTADO_OPA_CONSUMIDA
            : TestOrdenPagoRepository::ESTADO_OPA_PAGADO;

        if ((int) $anticipo->id_estado_orden_pago !== $nuevo) {
            $anticipo->id_estado_orden_pago = $nuevo;
            $anticipo->save();
        }

        return $nuevo;
    }

    /**
     * Anticipos de un beneficiario con saldo todavía disponible.
     *
     * Es lo que hay que mostrarle al operador cuando va a imputar facturas nuevas.
     */
    /**
     * Todos los anticipos, de todos los beneficiarios, para el listado de la pantalla.
     *
     * `anticiposConSaldo()` exige un beneficiario: sirve para el detalle de uno, no para abrir la
     * pantalla y ver qué hay. Sin esto el operador tenía que adivinar a quién buscar. (2026-09-25)
     *
     * Trae **todos los vigentes**, no sólo los que tienen saldo. El motivo es concreto: un
     * anticipo recién creado tiene saldo 0 —no genera saldo hasta que se lo PAGA por el circuito
     * normal—, así que filtrando por saldo el operador lo crea y lo ve desaparecer. Cada fila trae
     * su `situacion` para que la pantalla muestre en qué etapa está. Con `$soloConSaldo = true` se
     * acota a lo aplicable.
     */
    public function listarAnticipos(
        ?string $tipoBeneficiario = null,
        ?string $texto = null,
        bool $soloConSaldo = false
    ): array {
        $tipoBeneficiario = $tipoBeneficiario ? strtoupper(trim($tipoBeneficiario)) : null;

        $query = TesOrdenPagoEntity::where('tipo_opa', self::TIPO_ANTICIPO)
            ->whereNotIn('id_estado_orden_pago', [
                TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO,
                TestOrdenPagoRepository::ESTADO_OPA_CONSUMIDA,
            ])
            ->with(['prestador', 'proveedor', 'estado']);

        if ($tipoBeneficiario === 'PRESTADOR') {
            $query->whereNotNull('id_prestador');
        } elseif ($tipoBeneficiario === 'PROVEEDOR') {
            $query->whereNotNull('id_proveedor');
        }

        // El texto busca por razón social o CUIT del beneficiario, y también por número de OPA:
        // es lo que el operador tiene a mano cuando viene con un papel en la mano.
        if (!empty(trim((string) $texto))) {
            $t = '%' . trim($texto) . '%';

            $query->where(function ($q) use ($t) {
                $q->where('num_orden_pago', 'like', $t)
                    ->orWhereHas('prestador', fn($p) => $p->where('razon_social', 'like', $t)->orWhere('cuit', 'like', $t))
                    ->orWhereHas('proveedor', fn($p) => $p->where('razon_social', 'like', $t)->orWhere('cuit', 'like', $t));
            });
        }

        $salida = [];

        foreach ($query->orderByDesc('fecha_genera')->get() as $a) {
            $saldo = $this->saldoDisponible($a->id_orden_pago);

            if ($soloConSaldo && $saldo <= 0) {
                continue;
            }

            // Un anticipo genera saldo recién cuando se PAGA: mientras tanto es una promesa.
            $pagado       = $this->opaRepository->montoPagadoOpa($a->id_orden_pago) > 0.01;
            $esPrestador  = !is_null($a->id_prestador);
            $beneficiario = $esPrestador ? $a->prestador : $a->proveedor;

            $salida[] = [
                'id_orden_pago'      => $a->id_orden_pago,
                'num_orden_pago'     => $a->num_orden_pago,
                'fecha'              => $a->fecha_genera,
                'tipo_beneficiario'  => $esPrestador ? 'PRESTADOR' : 'PROVEEDOR',
                'id_beneficiario'    => $esPrestador ? $a->id_prestador : $a->id_proveedor,
                'razon_social'       => $beneficiario->razon_social ?? 'SIN BENEFICIARIO',
                'cuit'               => $beneficiario->cuit ?? '',
                'monto'              => (float) $a->monto_orden_pago,
                'aplicado'           => $this->totalAplicado($a->id_orden_pago),
                'saldo'              => $saldo,
                'id_estado_orden_pago' => (int) $a->id_estado_orden_pago,
                'estado'             => $a->estado->descripcion_estado ?? '',
                'pagado'             => $pagado,
                // En qué etapa está, ya resuelto acá para que la pantalla no lo re-derive:
                //   SIN PAGAR -> se creó pero todavía no se pagó, así que no genera saldo.
                //   CON SALDO -> pagado y con plata para aplicar a facturas.
                //   APLICADO  -> se aplicó todo; queda listado hasta que pase a CONSUMIDA.
                'situacion'          => !$pagado ? 'SIN PAGAR' : ($saldo > 0.01 ? 'CON SALDO' : 'APLICADO'),
                'observaciones'      => $a->observaciones,
            ];
        }

        return $salida;
    }

    public function anticiposConSaldo($idBeneficiario, string $tipoBeneficiario): array
    {
        $tipoBeneficiario = strtoupper(trim($tipoBeneficiario));
        $campo = $tipoBeneficiario === 'PROVEEDOR' ? 'id_proveedor' : 'id_prestador';

        $anticipos = TesOrdenPagoEntity::where('tipo_opa', self::TIPO_ANTICIPO)
            ->where($campo, $idBeneficiario)
            ->whereNotIn('id_estado_orden_pago', [
                TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO,
                TestOrdenPagoRepository::ESTADO_OPA_CONSUMIDA,
            ])
            ->orderBy('fecha_genera')
            ->get();

        $salida = [];

        foreach ($anticipos as $a) {
            $saldo = $this->saldoDisponible($a->id_orden_pago);

            if ($saldo <= 0) {
                continue;
            }

            $salida[] = [
                'id_orden_pago'  => $a->id_orden_pago,
                'num_orden_pago' => $a->num_orden_pago,
                'fecha'          => $a->fecha_genera,
                'monto'          => (float) $a->monto_orden_pago,
                'aplicado'       => $this->totalAplicado($a->id_orden_pago),
                'saldo'          => $saldo,
                'observaciones'  => $a->observaciones,
            ];
        }

        return $salida;
    }

    /**
     * Facturas a las que se les puede aplicar saldo de anticipo.
     *
     * Son las del beneficiario que NO están en ninguna OP viva: si ya están en una orden, esa
     * orden se va a pagar por su propio camino y aplicarles el anticipo las pagaría dos veces.
     * Es la misma condición que valida `aplicarAFacturas()`, para que la pantalla no ofrezca
     * algo que el backend después rechaza.
     *
     * Devuelve el saldo de cada una para poder proponer el monto a aplicar.
     */
    public function facturasAplicables($idBeneficiario, string $tipoBeneficiario): array
    {
        $tipoBeneficiario = strtoupper(trim($tipoBeneficiario));
        $campo = $tipoBeneficiario === 'PROVEEDOR' ? 'id_proveedor' : 'id_prestador';

        $facturas = DB::table('tb_facturacion_datos as f')
            ->where("f.{$campo}", $idBeneficiario)
            ->where('f.estado', '!=', 4)
            ->where('f.total_neto', '>', 0)
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('tb_tes_orden_pago_detalle as d')
                    ->join('tb_tes_orden_pago as o', 'o.id_orden_pago', '=', 'd.id_orden_pago')
                    ->whereColumn('d.id_factura', 'f.id_factura')
                    ->where('o.id_estado_orden_pago', '!=', TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO);
            })
            ->select([
                'f.id_factura', 'f.numero', 'f.periodo', 'f.fecha_comprobante', 'f.estado',
                'f.total_neto', 'f.total_debitado_liquidacion',
            ])
            ->orderBy('f.fecha_comprobante')
            ->orderBy('f.id_factura')
            ->limit(200)
            ->get();

        return $facturas->map(function ($f) {
            $saldo = self::aPesos(
                self::aCentavos($f->total_neto) - self::aCentavos($f->total_debitado_liquidacion ?? 0)
            );

            return [
                'id_factura'        => $f->id_factura,
                'numero'            => $f->numero,
                'periodo'           => $f->periodo,
                'fecha_comprobante' => $f->fecha_comprobante,
                'estado'            => $f->estado,
                'total_neto'        => (float) $f->total_neto,
                'saldo'             => $saldo,
            ];
        })->filter(fn($f) => $f['saldo'] > 0)->values()->all();
    }

    /** Saldo a favor total de un beneficiario, sumando todos sus anticipos vivos. */
    public function saldoAFavor($idBeneficiario, string $tipoBeneficiario): float
    {
        $total = 0;

        foreach ($this->anticiposConSaldo($idBeneficiario, $tipoBeneficiario) as $a) {
            $total += self::aCentavos($a['saldo']);
        }

        return self::aPesos($total);
    }
}
