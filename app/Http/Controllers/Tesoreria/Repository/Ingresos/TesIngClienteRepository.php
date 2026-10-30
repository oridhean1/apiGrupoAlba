<?php

namespace App\Http\Controllers\Tesoreria\Repository\Ingresos;

use App\Models\configuracion\RazonSocialModelo;
use App\Models\Tesoreria\Ingresos\TesIngClienteEntity;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Clientes del circuito de ingresos (obras sociales y empresas del grupo) y su cuenta corriente.
 */
class TesIngClienteRepository
{
    public const CONDICIONES_IVA = ['IVA Responsable Inscripto', 'IVA Sujeto Exento', 'Monotributista', 'Consumidor Final'];

    // Órdenes que cuentan para saldos (APLICADA, APLICACION PARCIAL). Ver TesIngOrdenCobranzaRepository.
    private const ESTADOS_EFECTIVOS = [2, 3];

    private $user;
    private $fechaActual;

    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }

    public function listar(?string $texto, bool $incluirInactivos)
    {
        return DB::table('tb_tes_ing_cliente as c')
            ->leftJoin('tb_razones_sociales as r', 'r.id_razon', '=', 'c.id_razon_grupo')
            ->leftJoin('tb_obras_sociales as o', 'o.id_obra', '=', 'c.id_obra')
            ->when(!$incluirInactivos, fn($q) => $q->where('c.activo', 1))
            ->when($texto, fn($q) => $q->where(fn($w) => $w->where('c.razon_social', 'like', "%{$texto}%")
                ->orWhere('c.sigla', 'like', "%{$texto}%")
                // sin dígitos quedaría LIKE '%%' y traería todos
                ->when(preg_replace('/\D/', '', $texto), fn($q, $digitos) => $q->orWhere('c.cuit', 'like', "%{$digitos}%"))))
            ->orderBy('c.razon_social')
            ->get(['c.*', 'r.razon_social as empresa_grupo', 'o.denominacion as obra_padron', 'o.rnos']);
    }

    /** Crea o modifica. El CUIT es la identidad: único y con dígito verificador válido. */
    public function guardar(array $d, $id = null): TesIngClienteEntity
    {
        $cuit = preg_replace('/\D/', '', (string) ($d['cuit'] ?? ''));
        if (!self::cuitValido($cuit)) {
            throw new Exception('CUIT inválido.');
        }
        if (empty(trim($d['razon_social'] ?? ''))) {
            throw new Exception('Falta la razón social.');
        }
        if (!empty($d['condicion_iva']) && !in_array($d['condicion_iva'], self::CONDICIONES_IVA)) {
            throw new Exception('Condición frente al IVA no válida.');
        }
        $repetido = TesIngClienteEntity::where('cuit', $cuit)->when($id, fn($q) => $q->where('id_cliente', '<>', $id))->first();
        if ($repetido) {
            throw new Exception("Ya existe un cliente con ese CUIT ({$repetido->razon_social}).");
        }

        $datos = [
            'cuit' => $cuit,
            'razon_social' => mb_strtoupper(trim($d['razon_social'])),
            'sigla' => trim($d['sigla'] ?? '') ?: null,
            'condicion_iva' => ($d['condicion_iva'] ?? null) ?: null,
            'id_obra' => ($d['id_obra'] ?? null) ?: null,
            // Se deriva del CUIT: no se carga a mano.
            'id_razon_grupo' => RazonSocialModelo::where('cuit', $cuit)->value('id_razon'),
            'activo' => isset($d['activo']) ? (int) (bool) $d['activo'] : 1,
        ];

        if ($id) {
            $cliente = TesIngClienteEntity::findOrFail($id);
            $cliente->fill($datos + ['cod_usuario_modifica' => $this->user->cod_usuario, 'fecha_modifica' => $this->fechaActual]);
            $cliente->save();
            return $cliente;
        }

        return TesIngClienteEntity::create($datos + ['cod_usuario' => $this->user->cod_usuario, 'fecha_registra' => $this->fechaActual]);
    }

    public function obrasSociales(?string $texto)
    {
        return DB::table('tb_obras_sociales')
            ->when($texto, fn($q) => $q->where(fn($w) => $w->where('denominacion', 'like', "%{$texto}%")->orWhere('sigla', 'like', "%{$texto}%")->orWhere('rnos', 'like', "%{$texto}%")))
            ->orderBy('sigla')
            ->limit(50)
            ->get(['id_obra', 'rnos', 'sigla', 'denominacion']);
    }

    /**
     * Cuenta corriente del cliente en una razón social.
     *
     * Debe: FC / ND. Haber: NC y lo que entró por cada orden confirmada (medios + retención).
     * El anticipo aplicado no mueve el saldo del cliente (sólo pasa crédito a una factura), por eso
     * no suma. Las órdenes anuladas no figuran (orden y contraasiento se cancelan).
     *
     * Saldo positivo = nos debe; negativo = a favor del cliente. Control: tiene que dar igual a
     * Σ saldo de facturas − Σ anticipos disponibles (`control`).
     */
    public function cuentaCorriente($idRazon, $idCliente, ?string $hasta = null): array
    {
        $comprobantes = DB::table('tb_tes_ing_comprobante as c')
            ->join('tb_tes_ing_tipo_comprobante as t', 't.id_tipo_comprobante', '=', 'c.id_tipo_comprobante')
            ->where('c.id_razon', $idRazon)->where('c.id_cliente', $idCliente)->where('c.anulado', 0)
            ->when($hasta, fn($q) => $q->where('c.fecha_emision', '<=', $hasta))
            ->get(['c.id_comprobante', 'c.fecha_emision', 't.descripcion', 't.clase', 'c.punto_venta', 'c.numero', 'c.importe_total', 'c.saldo', 'c.id_comprobante_asociado']);

        $ordenes = DB::table('tb_tes_ing_orden_cobranza as o')
            ->where('o.id_razon', $idRazon)->where('o.id_cliente', $idCliente)
            ->whereIn('o.id_estado', self::ESTADOS_EFECTIVOS)
            ->when($hasta, fn($q) => $q->where('o.fecha_cobro', '<=', $hasta))
            ->get(['o.id_orden_cobranza', 'o.numero', 'o.fecha_cobro', 'o.saldo_anticipo',
                DB::raw('(SELECT COALESCE(SUM(importe),0) FROM tb_tes_ing_orden_medio m WHERE m.id_orden_cobranza = o.id_orden_cobranza) AS medios'),
                DB::raw('(SELECT COALESCE(SUM(importe),0) FROM tb_tes_ing_retencion r WHERE r.id_orden_cobranza = o.id_orden_cobranza) AS retencion'),
                DB::raw('(SELECT COALESCE(SUM(importe),0) FROM tb_tes_ing_anticipo_aplicacion a WHERE a.id_orden_cobranza = o.id_orden_cobranza AND a.anulado = 0) AS anticipos'),
                DB::raw("(SELECT GROUP_CONCAT(CONCAT(LPAD(c.punto_venta,5,'0'),'-',LPAD(c.numero,8,'0')) SEPARATOR ', ') FROM tb_tes_ing_imputacion i JOIN tb_tes_ing_comprobante c ON c.id_comprobante = i.id_comprobante WHERE i.id_orden_cobranza = o.id_orden_cobranza AND i.anulado = 0) AS facturas"),
            ]);

        $nro = fn($pv, $n) => sprintf('%05d-%08d', $pv, $n);
        $movs = [];
        foreach ($comprobantes as $c) {
            $esNc = $c->clase === 'NC';
            $movs[] = [
                'fecha' => $c->fecha_emision,
                'tipo' => $c->clase,
                'comprobante' => "{$c->descripcion} {$nro($c->punto_venta, $c->numero)}",
                'detalle' => $esNc && $c->id_comprobante_asociado
                    ? 'Aplica a ' . optional($comprobantes->firstWhere('id_comprobante', $c->id_comprobante_asociado), fn($f) => $nro($f->punto_venta, $f->numero))
                    : ($esNc ? '' : 'Saldo ' . number_format((float) $c->saldo, 2, ',', '.')),
                'debe' => $esNc ? 0 : (float) $c->importe_total,
                'haber' => $esNc ? (float) $c->importe_total : 0,
                'orden' => 1,
                'id' => $c->id_comprobante,
            ];
        }
        foreach ($ordenes as $o) {
            $partes = [];
            if ((float) $o->retencion) $partes[] = 'retención ' . number_format((float) $o->retencion, 2, ',', '.');
            if ((float) $o->anticipos) $partes[] = 'anticipo aplicado ' . number_format((float) $o->anticipos, 2, ',', '.');
            if ($o->facturas) $partes[] = 'a ' . $o->facturas;
            if ((float) $o->saldo_anticipo) $partes[] = 'anticipo disponible ' . number_format((float) $o->saldo_anticipo, 2, ',', '.');
            $movs[] = [
                'fecha' => $o->fecha_cobro,
                'tipo' => 'OC',
                'comprobante' => 'OC-' . str_pad($o->numero, 6, '0', STR_PAD_LEFT),
                'detalle' => ucfirst(implode(' · ', $partes)),
                'debe' => 0,
                'haber' => round((float) $o->medios + (float) $o->retencion, 2),
                'orden' => 2,
                'id' => $o->id_orden_cobranza,
            ];
        }

        usort($movs, fn($a, $b) => [$a['fecha'], $a['orden'], $a['id']] <=> [$b['fecha'], $b['orden'], $b['id']]);
        $saldo = 0;
        foreach ($movs as &$m) {
            $saldo = round($saldo + $m['debe'] - $m['haber'], 2);
            $m['saldo'] = $saldo;
        }
        unset($m);

        $pendientes = $this->pendientesPorAntiguedad($comprobantes);
        $anticipos = round($ordenes->sum(fn($o) => (float) $o->saldo_anticipo), 2);
        $deuda = round(array_sum(array_column($pendientes['facturas'], 'saldo')), 2);

        return [
            'movimientos' => $movs,
            'saldo' => $saldo,
            'deuda_facturas' => $deuda,
            'anticipos_disponibles' => $anticipos,
            // Si no da 0, algún saldo guardado se desfasó de sus movimientos (sólo tiene sentido sin `hasta`).
            'control' => $hasta ? null : round($saldo - ($deuda - $anticipos), 2),
            'antiguedad' => $pendientes['tramos'],
            'pendientes' => $pendientes['facturas'],
        ];
    }

    private function pendientesPorAntiguedad($comprobantes): array
    {
        $hoy = $this->fechaActual->copy()->startOfDay();
        $tramos = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '+90' => 0];
        $facturas = [];

        foreach ($comprobantes as $c) {
            if ($c->clase === 'NC' || (float) $c->saldo <= 0) continue;
            $dias = Carbon::parse($c->fecha_emision)->startOfDay()->diffInDays($hoy, false);
            $tramo = $dias <= 30 ? '0-30' : ($dias <= 60 ? '31-60' : ($dias <= 90 ? '61-90' : '+90'));
            $tramos[$tramo] = round($tramos[$tramo] + (float) $c->saldo, 2);
            $facturas[] = [
                'id_comprobante' => $c->id_comprobante,
                'comprobante' => sprintf('%s %05d-%08d', $c->descripcion, $c->punto_venta, $c->numero),
                'fecha_emision' => $c->fecha_emision,
                'dias' => (int) $dias,
                'tramo' => $tramo,
                'importe_total' => (float) $c->importe_total,
                'saldo' => (float) $c->saldo,
            ];
        }
        usort($facturas, fn($a, $b) => $b['dias'] <=> $a['dias']);

        return ['tramos' => $tramos, 'facturas' => $facturas];
    }

    public static function cuitValido(?string $cuit): bool
    {
        if (!$cuit || !preg_match('/^\d{11}$/', $cuit)) {
            return false;
        }
        $suma = 0;
        foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $p) {
            $suma += $cuit[$i] * $p;
        }
        $dv = 11 - ($suma % 11);
        $dv = $dv === 11 ? 0 : ($dv === 10 ? 9 : $dv);
        return $dv === (int) $cuit[10];
    }
}
