<?php

namespace App\Http\Controllers\Tesoreria\Repository\Ingresos;

use App\Http\Controllers\Contabilidad\Repository\AsientoContableRepository;
use App\Http\Controllers\Contabilidad\Repository\PeriodosContablesRepository;
use App\Models\Contabilidad\RazonCuentaContableEntity;
use App\Models\Tesoreria\Ingresos\TesIngAnticipoAplicacionEntity;
use App\Models\Tesoreria\Ingresos\TesIngClienteEntity;
use App\Models\Tesoreria\Ingresos\TesIngComprobanteEntity;
use App\Models\Tesoreria\Ingresos\TesIngImputacionEntity;
use App\Models\Tesoreria\Ingresos\TesIngOrdenCobranzaEntity;
use App\Models\Tesoreria\Ingresos\TesIngOrdenMedioEntity;
use App\Models\Tesoreria\Ingresos\TesIngRetencionEntity;
use App\Models\configuracion\RazonSocialModelo;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Orden de cobranza (circuito de ingresos, README 6, 7, 8 y 10.2).
 *
 * Ciclo: BORRADOR (se guarda y se retoma) → confirmar → APLICADA / APLICACION PARCIAL → anular.
 *
 * El borrador guarda TODO lo que el usuario cargó, incluidas las imputaciones y los anticipos que
 * piensa usar, pero eso es sólo un plan: los saldos de facturas (`saldo`) y de anticipos
 * (`saldo_anticipo`) se mueven recién al confirmar, con las filas bloqueadas y todo validado de
 * nuevo. Por eso cualquier consulta de cuenta corriente tiene que mirar sólo órdenes confirmadas
 * (estados 2 y 3) — ver ESTADOS_EFECTIVOS.
 *
 * Razón social: la cuenta bancaria, las facturas y los anticipos tienen que ser de la razón de la
 * orden (y del mismo cliente). Si falta un dato para validarlo, corta (ver CLAUDE.md).
 */
class TesIngOrdenCobranzaRepository
{
    public const BORRADOR = 1;
    public const APLICADA = 2;
    public const APLICACION_PARCIAL = 3;
    public const ANULADA = 4;
    public const ESTADOS_EFECTIVOS = [self::APLICADA, self::APLICACION_PARCIAL];

    // Transferencia y depósito acreditan directo en el banco. Cheque y eCheq entran a Valores a
    // depositar y pasan al banco con depositarValor() (README 13.2-7 y 13.4). Efectivo no se cobra.
    private const FORMAS_ADMITIDAS = [1 => 'Transferencia', 3 => 'Deposito', 2 => 'Cheque', 7 => 'eCheq'];
    public const FORMAS_VALOR = [2, 7];

    private $user;
    private $fechaActual;

    public function __construct(
        private AsientoContableRepository $asientos,
        private PeriodosContablesRepository $periodos
    ) {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }

    // ---------------------------------------------------------------- consultas

    public function clientes(?string $texto)
    {
        return TesIngClienteEntity::where('activo', 1)
            ->when($texto, fn($q) => $q->where(fn($w) => $w->where('razon_social', 'like', "%{$texto}%")
                ->orWhere('sigla', 'like', "%{$texto}%")
                // sin dígitos quedaría LIKE '%%' y traería todos
                ->when(preg_replace('/\D/', '', $texto), fn($q, $digitos) => $q->orWhere('cuit', 'like', "%{$digitos}%"))))
            ->orderBy('razon_social')
            ->limit(50)
            ->get(['id_cliente', 'cuit', 'razon_social', 'sigla', 'condicion_iva']);
    }

    /** FC/ND con saldo de la razón y el cliente, de la más vieja a la más nueva (orden FIFO). */
    public function facturasPendientes($idRazon, $idCliente)
    {
        return DB::table('tb_tes_ing_comprobante as c')
            ->join('tb_tes_ing_tipo_comprobante as t', 't.id_tipo_comprobante', '=', 'c.id_tipo_comprobante')
            ->where('c.id_razon', $idRazon)
            ->where('c.id_cliente', $idCliente)
            ->whereIn('t.clase', ['FC', 'ND'])
            ->where('c.anulado', 0)
            ->where('c.saldo', '>', 0)
            ->orderBy('c.fecha_emision')
            ->orderBy('c.id_comprobante')
            ->get(['c.id_comprobante', 't.descripcion as tipo', 't.clase', 'c.punto_venta', 'c.numero', 'c.fecha_emision',
                'c.periodo_desde', 'c.periodo_hasta', 'c.importe_total', 'c.saldo']);
    }

    /** Órdenes confirmadas con excedente sin aplicar, de la razón y el cliente. */
    public function anticiposDisponibles($idRazon, $idCliente, $excluirOrden = null)
    {
        return TesIngOrdenCobranzaEntity::where('id_razon', $idRazon)
            ->where('id_cliente', $idCliente)
            ->whereIn('id_estado', self::ESTADOS_EFECTIVOS)
            ->where('saldo_anticipo', '>', 0)
            ->when($excluirOrden, fn($q) => $q->where('id_orden_cobranza', '<>', $excluirOrden))
            ->orderBy('fecha_cobro')
            ->get(['id_orden_cobranza', 'numero', 'fecha_cobro', 'saldo_anticipo']);
    }

    public function listar(array $f)
    {
        return DB::table('tb_tes_ing_orden_cobranza as o')
            ->join('tb_tes_ing_cliente as cl', 'cl.id_cliente', '=', 'o.id_cliente')
            ->join('tb_razones_sociales as r', 'r.id_razon', '=', 'o.id_razon')
            ->join('tb_tes_ing_estado_orden_cobranza as e', 'e.id_estado', '=', 'o.id_estado')
            ->when($f['id_razon'] ?? null, fn($q, $v) => $q->where('o.id_razon', $v))
            ->when($f['id_cliente'] ?? null, fn($q, $v) => $q->where('o.id_cliente', $v))
            ->when($f['id_estado'] ?? null, fn($q, $v) => $q->where('o.id_estado', $v))
            ->when($f['desde'] ?? null, fn($q, $v) => $q->where('o.fecha_cobro', '>=', $v))
            ->when($f['hasta'] ?? null, fn($q, $v) => $q->where('o.fecha_cobro', '<=', $v))
            ->orderByDesc('o.id_orden_cobranza')
            ->limit(500)
            ->get([
                'o.id_orden_cobranza', 'o.numero', 'o.fecha_cobro', 'o.id_estado', 'e.descripcion as estado', 'e.name_class',
                'o.saldo_anticipo', 'o.id_razon', 'o.id_cliente', 'r.razon_social as razon', 'cl.razon_social as cliente', 'cl.cuit',
                DB::raw('(SELECT COALESCE(SUM(importe),0) FROM tb_tes_ing_orden_medio m WHERE m.id_orden_cobranza = o.id_orden_cobranza) AS importe_acreditado'),
                DB::raw('(SELECT COALESCE(SUM(importe),0) FROM tb_tes_ing_imputacion i WHERE i.id_orden_cobranza = o.id_orden_cobranza AND i.anulado = 0) AS importe_aplicado'),
            ]);
    }

    public function detalle($id): array
    {
        $o = TesIngOrdenCobranzaEntity::findOrFail($id);
        $t = $this->totales($o->id_orden_cobranza);

        return [
            'orden' => $o,
            'numero_formateado' => $o->numero ? $this->numeroFormateado($o->numero) : null,
            'estado' => DB::table('tb_tes_ing_estado_orden_cobranza')->where('id_estado', $o->id_estado)->first(),
            'razon' => RazonSocialModelo::where('id_razon', $o->id_razon)->first(['id_razon', 'razon_social', 'cuit']),
            'cliente' => TesIngClienteEntity::find($o->id_cliente),
            'medios' => DB::table('tb_tes_ing_orden_medio as m')
                ->join('tb_tes_formas_pago as f', 'f.id_forma_pago', '=', 'm.id_forma_pago')
                ->leftJoin('tb_tes_cuentas_bancarias as cb', 'cb.id_cuenta_bancaria', '=', 'm.id_cuenta_bancaria')
                ->leftJoin('tb_tes_cuentas_bancarias as cd', 'cd.id_cuenta_bancaria', '=', 'm.id_cuenta_deposito')
                ->where('m.id_orden_cobranza', $id)
                ->get(['m.*', 'f.tipo_pago', 'cb.nombre_cuenta', 'cd.nombre_cuenta as nombre_cuenta_deposito']),
            'retenciones' => TesIngRetencionEntity::where('id_orden_cobranza', $id)->get(),
            'anticipos' => DB::table('tb_tes_ing_anticipo_aplicacion as a')
                ->join('tb_tes_ing_orden_cobranza as o', 'o.id_orden_cobranza', '=', 'a.id_orden_anticipo')
                ->where('a.id_orden_cobranza', $id)
                ->get(['a.*', 'o.numero', 'o.fecha_cobro', 'o.saldo_anticipo']),
            'imputaciones' => DB::table('tb_tes_ing_imputacion as i')
                ->join('tb_tes_ing_comprobante as c', 'c.id_comprobante', '=', 'i.id_comprobante')
                ->join('tb_tes_ing_tipo_comprobante as t', 't.id_tipo_comprobante', '=', 'c.id_tipo_comprobante')
                ->where('i.id_orden_cobranza', $id)
                ->get(['i.*', 't.descripcion as tipo', 'c.punto_venta', 'c.numero', 'c.fecha_emision', 'c.importe_total', 'c.saldo']),
            'totales' => $t,
        ];
    }

    // ---------------------------------------------------------------- borrador

    /**
     * Crea o reemplaza un borrador. $d: id_razon, id_cliente, fecha_cobro, observaciones,
     * medios [{id_forma_pago, id_cuenta_bancaria, referencia, importe}] — cheque / eCheq sin cuenta
     *   y con banco_emisor y fecha_pago_cheque (la cuenta se elige al depositarlo),
     * retenciones [{numero_certificado, fecha_retencion, importe}],
     * anticipos [{id_orden_anticipo, importe}], imputaciones [{id_comprobante, importe}].
     */
    public function guardarBorrador(array $d, $id = null): TesIngOrdenCobranzaEntity
    {
        return DB::transaction(function () use ($d, $id) {
            if ($id) {
                $orden = TesIngOrdenCobranzaEntity::lockForUpdate()->findOrFail($id);
                if ($orden->id_estado != self::BORRADOR) {
                    throw new Exception('Sólo se puede modificar una orden en borrador.');
                }
            } else {
                $orden = new TesIngOrdenCobranzaEntity([
                    'id_estado' => self::BORRADOR,
                    'cod_usuario' => $this->user->cod_usuario,
                    'fecha_registra' => $this->fechaActual,
                ]);
            }

            $orden->fill([
                'id_razon' => $d['id_razon'] ?? null,
                'id_cliente' => $d['id_cliente'] ?? null,
                'fecha_cobro' => $d['fecha_cobro'] ?? null,
                'observaciones' => $d['observaciones'] ?? null,
            ]);
            if ($id) {
                $orden->cod_usuario_modifica = $this->user->cod_usuario;
                $orden->fecha_modifica = $this->fechaActual;
            }

            $errores = $this->validarEstructura($orden, $d);
            if ($errores) {
                throw new Exception(implode(' ', $errores));
            }
            $orden->save();

            $idOrden = $orden->id_orden_cobranza;
            TesIngOrdenMedioEntity::where('id_orden_cobranza', $idOrden)->delete();
            TesIngRetencionEntity::where('id_orden_cobranza', $idOrden)->delete();
            TesIngAnticipoAplicacionEntity::where('id_orden_cobranza', $idOrden)->delete();
            TesIngImputacionEntity::where('id_orden_cobranza', $idOrden)->delete();

            foreach ($d['medios'] ?? [] as $m) {
                TesIngOrdenMedioEntity::create([
                    'id_orden_cobranza' => $idOrden,
                    'id_forma_pago' => $m['id_forma_pago'],
                    'id_cuenta_bancaria' => $this->esValor($m) ? null : $m['id_cuenta_bancaria'],
                    'referencia' => isset($m['referencia']) ? trim($m['referencia']) : null,
                    'banco_emisor' => $this->esValor($m) ? trim($m['banco_emisor']) : null,
                    'fecha_pago_cheque' => $this->esValor($m) ? ($m['fecha_pago_cheque'] ?? null) : null,
                    'importe' => round((float) $m['importe'], 2),
                ]);
            }
            foreach ($d['retenciones'] ?? [] as $r) {
                TesIngRetencionEntity::create([
                    'id_orden_cobranza' => $idOrden,
                    'tipo_retencion' => 'GANANCIAS',
                    'numero_certificado' => $r['numero_certificado'] ?? null,
                    'fecha_retencion' => $r['fecha_retencion'] ?? null,
                    'importe' => round((float) $r['importe'], 2),
                ]);
            }
            foreach ($d['anticipos'] ?? [] as $a) {
                TesIngAnticipoAplicacionEntity::create([
                    'id_orden_cobranza' => $idOrden,
                    'id_orden_anticipo' => $a['id_orden_anticipo'],
                    'importe' => round((float) $a['importe'], 2),
                    'cod_usuario' => $this->user->cod_usuario,
                    'fecha_registra' => $this->fechaActual,
                ]);
            }
            foreach ($d['imputaciones'] ?? [] as $i) {
                if ((float) ($i['importe'] ?? 0) <= 0) {
                    continue;
                }
                TesIngImputacionEntity::create([
                    'id_comprobante' => $i['id_comprobante'],
                    'id_orden_cobranza' => $idOrden,
                    'importe' => round((float) $i['importe'], 2),
                    'cod_usuario' => $this->user->cod_usuario,
                    'fecha_registra' => $this->fechaActual,
                ]);
            }

            return $orden->fresh();
        });
    }

    public function eliminarBorrador($id): void
    {
        DB::transaction(function () use ($id) {
            $orden = TesIngOrdenCobranzaEntity::lockForUpdate()->findOrFail($id);
            if ($orden->id_estado != self::BORRADOR) {
                throw new Exception('Sólo se puede eliminar una orden en borrador; una confirmada se anula.');
            }
            // medios, retenciones, imputaciones y aplicaciones caen por ON DELETE CASCADE
            $orden->delete();
        });
    }

    // ---------------------------------------------------------------- confirmar

    public function confirmar($id): array
    {
        return DB::transaction(function () use ($id) {
            $orden = TesIngOrdenCobranzaEntity::lockForUpdate()->findOrFail($id);
            if ($orden->id_estado != self::BORRADOR) {
                throw new Exception('La orden ya fue confirmada o anulada.');
            }

            $medios = TesIngOrdenMedioEntity::where('id_orden_cobranza', $id)->get();
            $retenciones = TesIngRetencionEntity::where('id_orden_cobranza', $id)->get();
            $aplicaciones = TesIngAnticipoAplicacionEntity::where('id_orden_cobranza', $id)->get();
            $imputaciones = TesIngImputacionEntity::where('id_orden_cobranza', $id)->get();

            $errores = $this->validarEstructura($orden, [
                'medios' => $medios->toArray(),
                'retenciones' => $retenciones->toArray(),
                'anticipos' => $aplicaciones->toArray(),
                'imputaciones' => $imputaciones->toArray(),
            ]);
            if ($errores) {
                throw new Exception(implode(' ', $errores));
            }

            // Saldos reales, con las filas bloqueadas: dos órdenes no pueden cobrar el mismo saldo.
            foreach ($imputaciones as $i) {
                $c = TesIngComprobanteEntity::lockForUpdate()->find($i->id_comprobante);
                if ((float) $i->importe > (float) $c->saldo + 0.009) {
                    throw new Exception("Se aplican {$i->importe} a la factura {$c->punto_venta}-{$c->numero} y su saldo es {$c->saldo}.");
                }
                $c->saldo = round((float) $c->saldo - (float) $i->importe, 2);
                $c->save();
            }
            foreach ($aplicaciones as $a) {
                $ant = TesIngOrdenCobranzaEntity::lockForUpdate()->find($a->id_orden_anticipo);
                if (!in_array($ant->id_estado, self::ESTADOS_EFECTIVOS) || (float) $a->importe > (float) $ant->saldo_anticipo + 0.009) {
                    throw new Exception("El anticipo {$this->numeroFormateado($ant->numero)} tiene disponible {$ant->saldo_anticipo} y se quieren usar {$a->importe}.");
                }
                $ant->saldo_anticipo = round((float) $ant->saldo_anticipo - (float) $a->importe, 2);
                $ant->save();
            }

            $t = $this->totales($id);
            if ($t['aplicado'] > $t['total_imputable'] + 0.009) {
                throw new Exception("Se aplican {$t['aplicado']} a facturas y la orden sólo cubre {$t['total_imputable']}.");
            }
            if ($t['anticipos'] > 0 && $t['excedente'] > 0.009) {
                throw new Exception('Se usa un anticipo y además sobra plata: aplicá menos anticipo o más facturas.');
            }
            if ($t['excedente'] > 0.009 && !$this->tieneCuenta($orden->id_razon, RazonCuentaContableEntity::TIPO_VENTA_ANTICIPO)) {
                throw new Exception('Sobran ' . $t['excedente'] . ' que quedarían como anticipo, y la razón social no tiene configurada la cuenta de anticipos de clientes. Por favor contacte con Contabilidad.');
            }

            $periodo = $this->periodoAbierto($orden->fecha_cobro, $orden->id_razon);

            $orden->numero = (int) TesIngOrdenCobranzaEntity::where('id_razon', $orden->id_razon)->lockForUpdate()->max('numero') + 1;
            $orden->saldo_anticipo = $t['excedente'];
            $orden->id_estado = $t['excedente'] > 0.009 ? self::APLICACION_PARCIAL : self::APLICADA;
            $orden->fecha_confirma = $this->fechaActual;
            $orden->cod_usuario_confirma = $this->user->cod_usuario;
            $orden->save();

            $cliente = TesIngClienteEntity::find($orden->id_cliente);
            $asiento = $this->asientos->crearAsientoCobranza([
                'id_orden_cobranza' => $orden->id_orden_cobranza,
                'id_razon' => $orden->id_razon,
                'numero' => $orden->numero,
                'cliente' => $cliente->razon_social,
                'cuit' => $cliente->cuit,
                'fecha_cobro' => $orden->fecha_cobro,
                'medios' => $medios->map(fn($m) => [
                    'id_cuenta_bancaria' => $m->id_cuenta_bancaria,
                    'es_valor' => $this->esValor($m->toArray()),
                    'importe' => (float) $m->importe,
                    'descripcion' => (self::FORMAS_ADMITIDAS[$m->id_forma_pago] ?? '') . ($m->referencia ? " {$m->referencia}" : ''),
                ])->all(),
                'retencion' => $t['retenciones'],
                'anticipos_aplicados' => $t['anticipos'],
                'aplicado' => $t['aplicado'],
                'excedente' => $t['excedente'],
            ], $periodo);

            $orden->id_asiento_contable = $asiento->id_asiento_contable;
            $orden->save();

            return ['orden' => $orden->fresh(), 'numero_formateado' => $this->numeroFormateado($orden->numero), 'totales' => $t, 'id_asiento_contable' => $asiento->id_asiento_contable];
        });
    }

    // ---------------------------------------------------------------- anular

    /**
     * Contraasiento + devuelve saldos a las facturas y a los anticipos usados. Si el excedente de
     * esta orden ya se usó en otra orden confirmada, corta: hay que anular primero aquella.
     * El contraasiento va con la fecha de anulación (pregunta 13 de la segunda ronda, pendiente).
     */
    public function anular($id, string $motivo): TesIngOrdenCobranzaEntity
    {
        return DB::transaction(function () use ($id, $motivo) {
            $orden = TesIngOrdenCobranzaEntity::lockForUpdate()->findOrFail($id);
            if (!in_array($orden->id_estado, self::ESTADOS_EFECTIVOS)) {
                throw new Exception('Sólo se puede anular una orden confirmada.');
            }

            $usadaEn = DB::table('tb_tes_ing_anticipo_aplicacion as a')
                ->join('tb_tes_ing_orden_cobranza as o', 'o.id_orden_cobranza', '=', 'a.id_orden_cobranza')
                ->where('a.id_orden_anticipo', $id)
                ->where('a.anulado', 0)
                ->whereIn('o.id_estado', self::ESTADOS_EFECTIVOS)
                ->pluck('o.numero');
            $depositados = TesIngOrdenMedioEntity::where('id_orden_cobranza', $id)->whereNotNull('id_asiento_deposito')->pluck('referencia');
            if ($depositados->isNotEmpty()) {
                throw new Exception('Hay valores ya depositados (' . $depositados->implode(', ') . '): anulá primero su depósito.');
            }

            if ($usadaEn->isNotEmpty()) {
                throw new Exception('El anticipo de esta orden se usó en ' . $usadaEn->map(fn($n) => $this->numeroFormateado($n))->implode(', ') . ': anulá primero esa(s) orden(es).');
            }

            foreach (TesIngImputacionEntity::where('id_orden_cobranza', $id)->where('anulado', 0)->get() as $i) {
                $c = TesIngComprobanteEntity::lockForUpdate()->find($i->id_comprobante);
                $c->saldo = round((float) $c->saldo + (float) $i->importe, 2);
                $c->save();
                $i->anulado = 1;
                $i->save();
            }
            foreach (TesIngAnticipoAplicacionEntity::where('id_orden_cobranza', $id)->where('anulado', 0)->get() as $a) {
                $ant = TesIngOrdenCobranzaEntity::lockForUpdate()->find($a->id_orden_anticipo);
                $ant->saldo_anticipo = round((float) $ant->saldo_anticipo + (float) $a->importe, 2);
                $ant->save();
                $a->anulado = 1;
                $a->save();
            }

            $hoy = $this->fechaActual->toDateString();
            if (!$orden->id_asiento_contable) {
                throw new Exception('La orden no tiene asiento contable registrado: no se puede generar el contraasiento.');
            }
            $contra = $this->asientos->contraasientoCompleto(
                $orden->id_asiento_contable,
                'ANULACION ' . $this->numeroFormateado($orden->numero) . ($motivo ? " - {$motivo}" : ''),
                $this->periodoAbierto($hoy, $orden->id_razon),
                $hoy
            );

            $orden->id_estado = self::ANULADA;
            $orden->saldo_anticipo = 0;
            $orden->id_asiento_anulacion = $contra->id_asiento_contable;
            $orden->fecha_anula = $this->fechaActual;
            $orden->cod_usuario_anula = $this->user->cod_usuario;
            $orden->motivo_anulacion = $motivo;
            $orden->save();

            return $orden->fresh();
        });
    }

    // ---------------------------------------------------------------- validación

    /**
     * Lo que se puede validar sin mirar saldos: datos obligatorios, que todo sea de la razón y el
     * cliente de la orden, medios admitidos e importes. Corre al guardar el borrador y otra vez al
     * confirmar.
     */
    private function validarEstructura(TesIngOrdenCobranzaEntity $orden, array $d): array
    {
        $e = [];
        if (empty($orden->id_razon)) $e[] = 'Falta la razón social.';
        if (empty($orden->id_cliente)) $e[] = 'Falta el cliente.';
        if (empty($orden->fecha_cobro)) $e[] = 'Falta la fecha de cobro.';
        if ($e) return $e;

        if (!RazonSocialModelo::where('id_razon', $orden->id_razon)->where('activo', 1)->exists()) $e[] = 'Razón social inexistente o inactiva.';
        if (!TesIngClienteEntity::where('id_cliente', $orden->id_cliente)->where('activo', 1)->exists()) $e[] = 'Cliente inexistente o inactivo.';

        $medios = $d['medios'] ?? [];
        $anticipos = $d['anticipos'] ?? [];
        if (empty($medios) && empty($anticipos)) {
            $e[] = 'La orden necesita al menos un medio de cobro (o un anticipo a aplicar).';
        }

        $valoresVistos = [];
        foreach ($medios as $n => $m) {
            $r = 'Medio ' . ($n + 1) . ': ';
            if (!isset(self::FORMAS_ADMITIDAS[$m['id_forma_pago'] ?? 0])) {
                $e[] = $r . 'se admite transferencia, depósito, cheque o eCheq (no se cobra en efectivo).';
            }
            if ((float) ($m['importe'] ?? 0) <= 0) $e[] = $r . 'el importe tiene que ser mayor a cero.';
            if ($this->esValor($m)) {
                // Cheque / eCheq: entra a Valores a depositar; la cuenta propia se elige al depositar.
                $numero = trim((string) ($m['referencia'] ?? ''));
                $banco = trim((string) ($m['banco_emisor'] ?? ''));
                if ($numero === '') $e[] = $r . 'falta el número del cheque.';
                if ($banco === '') $e[] = $r . 'falta el banco emisor del cheque.';
                if ($numero !== '' && $banco !== '') {
                    $clave = mb_strtoupper("{$banco}|{$numero}");
                    if (isset($valoresVistos[$clave])) $e[] = $r . "el cheque {$numero} está cargado dos veces.";
                    $valoresVistos[$clave] = true;
                    // Unicidad: el mismo cheque (banco + número) no entra en dos órdenes vivas de la razón.
                    $repetido = DB::table('tb_tes_ing_orden_medio as m')
                        ->join('tb_tes_ing_orden_cobranza as o', 'o.id_orden_cobranza', '=', 'm.id_orden_cobranza')
                        ->whereIn('m.id_forma_pago', self::FORMAS_VALOR)
                        ->where('o.id_razon', $orden->id_razon)
                        ->whereIn('o.id_estado', self::ESTADOS_EFECTIVOS)
                        ->when($orden->id_orden_cobranza, fn($q) => $q->where('o.id_orden_cobranza', '<>', $orden->id_orden_cobranza))
                        ->where('m.referencia', $numero)
                        ->whereRaw('UPPER(TRIM(m.banco_emisor)) = ?', [mb_strtoupper($banco)])
                        ->value('o.numero');
                    if ($repetido) $e[] = $r . "el cheque {$numero} de {$banco} ya está registrado en {$this->numeroFormateado($repetido)}.";
                }
                if (!$this->tieneCuenta($orden->id_razon, RazonCuentaContableEntity::TIPO_VENTA_VALORES_DEP)) {
                    $e[] = $r . 'la razón social no tiene configurada la cuenta de Valores a depositar.';
                }
                continue;
            }
            if (empty($m['id_cuenta_bancaria'])) {
                $e[] = $r . 'falta la cuenta bancaria.';
                continue;
            }
            $cuenta = DB::table('tb_tes_cuentas_bancarias')->where('id_cuenta_bancaria', $m['id_cuenta_bancaria'])->first();
            if (!$cuenta || !$cuenta->activo) {
                $e[] = $r . 'cuenta bancaria inexistente o inactiva.';
            } elseif ((int) $cuenta->id_razon !== (int) $orden->id_razon) {
                $e[] = $r . "la cuenta {$cuenta->nombre_cuenta} es de otra razón social.";
            }
        }

        $retenciones = $d['retenciones'] ?? [];
        foreach ($retenciones as $n => $r) {
            if ((float) ($r['importe'] ?? 0) <= 0) $e[] = 'Retención ' . ($n + 1) . ': el importe tiene que ser mayor a cero.';
        }
        if ($retenciones && !$this->tieneCuenta($orden->id_razon, RazonCuentaContableEntity::TIPO_VENTA_RET_GANANCIAS)) {
            $e[] = 'La razón social no tiene configurada la cuenta de retención de Ganancias.';
        }

        $vistos = [];
        foreach ($anticipos as $a) {
            $ant = TesIngOrdenCobranzaEntity::find($a['id_orden_anticipo'] ?? 0);
            if (!$ant || ($orden->id_orden_cobranza && $ant->id_orden_cobranza == $orden->id_orden_cobranza)) {
                $e[] = 'Anticipo inexistente.';
                continue;
            }
            if ($ant->id_razon != $orden->id_razon || $ant->id_cliente != $orden->id_cliente) {
                $e[] = "El anticipo {$this->numeroFormateado($ant->numero)} es de otra razón social o de otro cliente.";
            }
            if (!in_array($ant->id_estado, self::ESTADOS_EFECTIVOS)) $e[] = 'Sólo se aplican anticipos de órdenes confirmadas.';
            if ((float) ($a['importe'] ?? 0) <= 0) $e[] = 'El importe de anticipo a aplicar tiene que ser mayor a cero.';
            if (isset($vistos['a' . $ant->id_orden_cobranza])) $e[] = 'El mismo anticipo está cargado dos veces.';
            $vistos['a' . $ant->id_orden_cobranza] = true;
        }
        if ($anticipos && !$this->tieneCuenta($orden->id_razon, RazonCuentaContableEntity::TIPO_VENTA_ANTICIPO)) {
            $e[] = 'La razón social no tiene configurada la cuenta de anticipos de clientes.';
        }

        foreach ($d['imputaciones'] ?? [] as $i) {
            if ((float) ($i['importe'] ?? 0) <= 0) continue;
            $c = DB::table('tb_tes_ing_comprobante as c')
                ->join('tb_tes_ing_tipo_comprobante as t', 't.id_tipo_comprobante', '=', 'c.id_tipo_comprobante')
                ->where('c.id_comprobante', $i['id_comprobante'] ?? 0)
                ->first(['c.*', 't.clase']);
            if (!$c) {
                $e[] = 'Factura inexistente.';
                continue;
            }
            $desc = "{$c->punto_venta}-{$c->numero}";
            if ($c->id_razon != $orden->id_razon || $c->id_cliente != $orden->id_cliente) $e[] = "La factura {$desc} es de otra razón social o de otro cliente.";
            if (!in_array($c->clase, ['FC', 'ND'])) $e[] = "{$desc}: sólo se cobran facturas y notas de débito.";
            if ($c->anulado) $e[] = "La factura {$desc} está anulada.";
            if (isset($vistos['c' . $c->id_comprobante])) $e[] = "La factura {$desc} está cargada dos veces.";
            $vistos['c' . $c->id_comprobante] = true;
        }

        return $e;
    }

    private function totales($idOrden): array
    {
        $medios = (float) TesIngOrdenMedioEntity::where('id_orden_cobranza', $idOrden)->sum('importe');
        $ret = (float) TesIngRetencionEntity::where('id_orden_cobranza', $idOrden)->sum('importe');
        $ant = (float) TesIngAnticipoAplicacionEntity::where('id_orden_cobranza', $idOrden)->where('anulado', 0)->sum('importe');
        $apl = (float) TesIngImputacionEntity::where('id_orden_cobranza', $idOrden)->where('anulado', 0)->sum('importe');
        $total = round($medios + $ret + $ant, 2);

        return [
            'acreditado' => round($medios, 2),
            'retenciones' => round($ret, 2),
            'anticipos' => round($ant, 2),
            'total_imputable' => $total,
            'aplicado' => round($apl, 2),
            'excedente' => round(max(0, $total - $apl), 2),
        ];
    }

    private function periodoAbierto($fecha, $idRazon)
    {
        $fecha = Carbon::parse($fecha)->format('Y-m-d');
        $yymm = substr($fecha, 2, 2) . substr($fecha, 5, 2);
        $periodo = $this->periodos->findByExistsPeriodoActivo($yymm, $idRazon);
        if (!$periodo) {
            throw new Exception("El período contable {$yymm} no está abierto para la razón social.");
        }
        return $periodo->id_periodo_contable;
    }

    private function esValor(array $m): bool
    {
        return in_array((int) ($m['id_forma_pago'] ?? 0), self::FORMAS_VALOR, true);
    }

    // ---------------------------------------------------------------- valores a depositar

    /** Cheques / eCheq de órdenes confirmadas. estado: pendientes (default) | depositados | todos. */
    public function valores(array $f): array
    {
        $estado = $f['estado'] ?? 'pendientes';

        return DB::table('tb_tes_ing_orden_medio as m')
            ->join('tb_tes_ing_orden_cobranza as o', 'o.id_orden_cobranza', '=', 'm.id_orden_cobranza')
            ->join('tb_tes_ing_cliente as c', 'c.id_cliente', '=', 'o.id_cliente')
            ->join('tb_tes_formas_pago as fp', 'fp.id_forma_pago', '=', 'm.id_forma_pago')
            ->leftJoin('tb_tes_cuentas_bancarias as cd', 'cd.id_cuenta_bancaria', '=', 'm.id_cuenta_deposito')
            ->whereIn('m.id_forma_pago', self::FORMAS_VALOR)
            ->whereIn('o.id_estado', self::ESTADOS_EFECTIVOS)
            ->when(!empty($f['id_razon']), fn($q) => $q->where('o.id_razon', $f['id_razon']))
            ->when($estado === 'pendientes', fn($q) => $q->whereNull('m.id_asiento_deposito'))
            ->when($estado === 'depositados', fn($q) => $q->whereNotNull('m.id_asiento_deposito'))
            ->orderByRaw('COALESCE(m.fecha_pago_cheque, o.fecha_cobro)')
            ->get([
                'm.id_orden_medio', 'm.id_forma_pago', 'fp.tipo_pago', 'm.referencia', 'm.banco_emisor', 'm.fecha_pago_cheque',
                'm.importe', 'm.id_cuenta_deposito', 'cd.nombre_cuenta as nombre_cuenta_deposito', 'm.fecha_deposito',
                'o.id_orden_cobranza', 'o.id_razon', 'o.numero', 'o.fecha_cobro', 'c.razon_social as cliente', 'c.cuit',
            ])
            ->map(function ($r) {
                $r->numero_formateado = $this->numeroFormateado($r->numero);
                return $r;
            })
            ->all();
    }

    /**
     * Deposita un cheque / eCheq en una cuenta propia: Banco (D) contra Valores a depositar (H), con
     * la fecha de depósito. La cuenta tiene que ser de la razón de la orden: si no, corta.
     */
    public function depositarValor($idMedio, $idCuenta, $fecha): TesIngOrdenMedioEntity
    {
        return DB::transaction(function () use ($idMedio, $idCuenta, $fecha) {
            $m = TesIngOrdenMedioEntity::lockForUpdate()->findOrFail($idMedio);
            if (!$this->esValor($m->toArray())) throw new Exception('Sólo se depositan cheques y eCheq.');
            if ($m->id_asiento_deposito) throw new Exception("El cheque {$m->referencia} ya está depositado.");

            $orden = TesIngOrdenCobranzaEntity::lockForUpdate()->findOrFail($m->id_orden_cobranza);
            if (!in_array($orden->id_estado, self::ESTADOS_EFECTIVOS)) throw new Exception('La orden del cheque no está confirmada (o fue anulada).');
            if (empty($fecha)) throw new Exception('Falta la fecha de depósito.');
            if (Carbon::parse($fecha)->lt(Carbon::parse($orden->fecha_cobro))) throw new Exception('La fecha de depósito no puede ser anterior a la de cobro.');

            $cuenta = DB::table('tb_tes_cuentas_bancarias')->where('id_cuenta_bancaria', $idCuenta)->first();
            if (!$cuenta || !$cuenta->activo) throw new Exception('Cuenta bancaria inexistente o inactiva.');
            if (empty($orden->id_razon) || (int) $cuenta->id_razon !== (int) $orden->id_razon) {
                throw new Exception("La cuenta {$cuenta->nombre_cuenta} es de otra razón social que la orden del cheque.");
            }

            $cliente = TesIngClienteEntity::find($orden->id_cliente);
            $asiento = $this->asientos->crearAsientoDepositoValor([
                'id_orden_medio' => $m->id_orden_medio,
                'id_razon' => $orden->id_razon,
                'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria,
                'nombre_cuenta' => $cuenta->nombre_cuenta,
                'importe' => (float) $m->importe,
                'descripcion' => (self::FORMAS_ADMITIDAS[$m->id_forma_pago] ?? '') . " {$m->referencia} {$m->banco_emisor} - {$this->numeroFormateado($orden->numero)} - {$cliente->razon_social}",
                'fecha' => Carbon::parse($fecha)->toDateString(),
            ], $this->periodoAbierto($fecha, $orden->id_razon));

            $m->id_cuenta_deposito = $cuenta->id_cuenta_bancaria;
            $m->fecha_deposito = Carbon::parse($fecha)->toDateString();
            $m->id_asiento_deposito = $asiento->id_asiento_contable;
            $m->cod_usuario_deposita = $this->user->cod_usuario;
            $m->fecha_registra_deposito = $this->fechaActual;
            $m->save();

            return $m->fresh();
        });
    }

    /** Deshace un depósito con contraasiento (fecha de hoy); el cheque vuelve a cartera. */
    public function anularDepositoValor($idMedio): TesIngOrdenMedioEntity
    {
        return DB::transaction(function () use ($idMedio) {
            $m = TesIngOrdenMedioEntity::lockForUpdate()->findOrFail($idMedio);
            if (!$m->id_asiento_deposito) throw new Exception('El cheque no está depositado.');
            $orden = TesIngOrdenCobranzaEntity::findOrFail($m->id_orden_cobranza);

            $hoy = $this->fechaActual->toDateString();
            $this->asientos->contraasientoCompleto(
                $m->id_asiento_deposito,
                "ANULACION DEPOSITO {$m->referencia}",
                $this->periodoAbierto($hoy, $orden->id_razon),
                $hoy
            );

            $m->id_cuenta_deposito = null;
            $m->fecha_deposito = null;
            $m->id_asiento_deposito = null;
            $m->cod_usuario_deposita = null;
            $m->fecha_registra_deposito = null;
            $m->save();

            return $m->fresh();
        });
    }

    private function tieneCuenta($idRazon, string $tipo): bool
    {
        return RazonCuentaContableEntity::where('id_razon', $idRazon)
            ->where('tipo_contraparte', $tipo)
            ->where('vigente', 1)
            ->whereNotNull('id_detalle_plan')
            ->exists();
    }

    private function numeroFormateado($numero): string
    {
        return 'OC-' . str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
    }
}
