<?php

namespace App\Http\Controllers\Tesoreria\Repository\Ingresos;

use App\Http\Controllers\Contabilidad\Repository\AsientoContableRepository;
use App\Http\Controllers\Contabilidad\Repository\PeriodosContablesRepository;
use App\Imports\ComprobantesArcaEmitidosLector;
use App\Models\Contabilidad\RazonCuentaContableEntity;
use App\Models\configuracion\RazonSocialModelo;
use App\Models\Tesoreria\Ingresos\TesIngClienteEntity;
use App\Models\Tesoreria\Ingresos\TesIngComprobanteEntity;
use App\Models\Tesoreria\Ingresos\TesIngImportacionEntity;
use App\Models\Tesoreria\Ingresos\TesIngImputacionEntity;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Importación de comprobantes de venta desde ARCA (circuito de ingresos, README 5.1 y 9).
 *
 * `previsualizar()` no escribe nada. `confirmar()` vuelve a leer el archivo y a validar todo
 * (no confía en lo que devolvió la vista previa) y graba en una sola transacción: clientes nuevos,
 * comprobantes, la imputación de cada NC a su factura y el asiento de cada comprobante.
 *
 * Las NC necesitan la factura a la que aplican, que el export de ARCA no trae: llega en
 * `$asociaciones` = [fila => id_comprobante | 'fila:N'] ('fila:N' = una factura del mismo archivo).
 */
class TesIngComprobanteRepository
{
    private const MONEDAS_PESOS = ['PES', '$', 'PESOS', 'ARS'];

    private $user;
    private $fechaActual;

    public function __construct(
        private AsientoContableRepository $asientos,
        private PeriodosContablesRepository $periodos,
        private ComprobantesArcaEmitidosLector $lector
    ) {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }

    public function previsualizar(string $ruta, $idRazon, array $asociaciones = []): array
    {
        return $this->analizar($ruta, $idRazon, $asociaciones);
    }

    public function confirmar(string $ruta, string $nombreArchivo, $idRazon, array $asociaciones): array
    {
        $analisis = $this->analizar($ruta, $idRazon, $asociaciones);

        if (!empty($analisis['errores'])) {
            throw new Exception(implode(' ', $analisis['errores']));
        }

        $validas = array_values(array_filter($analisis['filas'], fn($f) => $f['estado'] === 'OK'));
        if (empty($validas)) {
            throw new Exception('No hay comprobantes válidos para importar.');
        }

        return DB::transaction(function () use ($analisis, $validas, $ruta, $nombreArchivo, $idRazon) {
            $importacion = TesIngImportacionEntity::create([
                'id_razon' => $idRazon,
                'nombre_archivo' => $nombreArchivo,
                'hash_archivo' => hash_file('sha256', $ruta),
                'filas_total' => count($analisis['filas']),
                'filas_importadas' => count($validas),
                'filas_observadas' => count($analisis['filas']) - count($validas),
                'cod_usuario' => $this->user->cod_usuario,
                'fecha_registra' => $this->fechaActual,
            ]);

            $clientes = [];
            $idPorFila = [];

            // Las facturas primero: una NC del mismo archivo puede apuntar a una de ellas.
            usort($validas, fn($a, $b) => ($a['clase'] === 'NC') <=> ($b['clase'] === 'NC'));

            foreach ($validas as $f) {
                $idCliente = $clientes[$f['cuit_receptor']] ??= $this->clienteParaCuit($f);

                $idAsociado = $f['id_comprobante_asociado']
                    ?? ($f['fila_asociada'] ? $idPorFila[$f['fila_asociada']] : null);

                $comprobante = TesIngComprobanteEntity::create([
                    'id_razon' => $idRazon,
                    'id_cliente' => $idCliente,
                    'id_tipo_comprobante' => $f['id_tipo_comprobante'],
                    'punto_venta' => $f['punto_venta'],
                    'numero' => $f['numero'],
                    'fecha_emision' => $f['fecha_emision'],
                    'importe_neto_gravado' => $f['importe_neto_gravado'],
                    'importe_no_gravado' => $f['importe_no_gravado'],
                    'importe_exento' => $f['importe_exento'],
                    'importe_iva' => $f['importe_iva'],
                    'importe_otros_tributos' => $f['importe_otros_tributos'],
                    'importe_total' => $f['importe_total'],
                    // NC: aplicarNotaCredito() la deja con lo que no pudo aplicar (saldo a favor).
                    'saldo' => $f['importe_total'],
                    'cae' => $f['cae'],
                    'id_comprobante_asociado' => $idAsociado,
                    'id_importacion' => $importacion->id_importacion,
                    'cod_usuario' => $this->user->cod_usuario,
                    'fecha_registra' => $this->fechaActual,
                ]);
                $idPorFila[$f['fila']] = $comprobante->id_comprobante;

                if ($f['clase'] === 'NC') {
                    $this->aplicarNotaCredito($comprobante, $idAsociado);
                }

                $asiento = $this->asientos->crearAsientoComprobanteVenta([
                    'id_comprobante' => $comprobante->id_comprobante,
                    'id_razon' => $idRazon,
                    'clase' => $f['clase'],
                    'descripcion' => $f['descripcion'],
                    'fecha_emision' => $f['fecha_emision'],
                    'cliente' => $f['denominacion_receptor'],
                    'cuit' => $f['cuit_receptor'],
                    'importe_ingreso' => $f['importe_neto_gravado'] + $f['importe_no_gravado'] + $f['importe_exento'],
                    'importe_iva' => $f['importe_iva'],
                    'importe_total' => $f['importe_total'],
                ], $f['id_periodo_contable']);

                $comprobante->id_asiento_contable = $asiento->id_asiento_contable;
                $comprobante->save();
            }

            return [
                'id_importacion' => $importacion->id_importacion,
                'importados' => count($validas),
                'observados' => count($analisis['filas']) - count($validas),
            ];
        });
    }

    /**
     * Aplica la NC a su factura hasta el saldo de ésta (con la fila bloqueada); lo que sobra queda
     * en `saldo` de la NC como saldo a favor del cliente.
     */
    private function aplicarNotaCredito(TesIngComprobanteEntity $nc, $idFactura): void
    {
        $factura = TesIngComprobanteEntity::where('id_comprobante', $idFactura)->lockForUpdate()->first();

        if (!$factura || $factura->id_razon != $nc->id_razon || $factura->id_cliente != $nc->id_cliente) {
            throw new Exception("La NC {$nc->punto_venta}-{$nc->numero} apunta a una factura de otro cliente o razón social.");
        }

        $aplica = round(min((float) $nc->importe_total, max(0, (float) $factura->saldo)), 2);
        if ($aplica > 0) {
            TesIngImputacionEntity::create([
                'id_comprobante' => $factura->id_comprobante,
                'id_comprobante_nc' => $nc->id_comprobante,
                'importe' => $aplica,
                'cod_usuario' => $this->user->cod_usuario,
                'fecha_registra' => $this->fechaActual,
            ]);
            $factura->saldo = round((float) $factura->saldo - $aplica, 2);
            $factura->save();
        }

        $nc->saldo = round((float) $nc->importe_total - $aplica, 2);
        $nc->save();
    }

    /**
     * Usa el saldo a favor de una NC para cancelar otra FC/ND del mismo cliente y razón social.
     * No lleva asiento: es Deudores contra Deudores del mismo cliente (el crédito ya quedó en
     * Deudores con el asiento de la NC).
     */
    public function aplicarSaldoNotaCredito($idNc, $idFactura, $importe): TesIngImputacionEntity
    {
        $importe = round((float) $importe, 2);
        if ($importe <= 0) {
            throw new Exception('El importe a aplicar tiene que ser mayor a cero.');
        }

        return DB::transaction(function () use ($idNc, $idFactura, $importe) {
            $nc = $this->comprobanteBloqueado($idNc, ['NC'], 'Nota de crédito');
            $factura = $this->comprobanteBloqueado($idFactura, ['FC', 'ND'], 'Factura');

            // Razón social y cliente de las dos puntas: si falta alguno, no coincide y corta.
            if (!$nc->id_razon || !$nc->id_cliente || $nc->id_razon != $factura->id_razon || $nc->id_cliente != $factura->id_cliente) {
                throw new Exception('La nota de crédito y la factura tienen que ser de la misma razón social y del mismo cliente.');
            }
            if ($importe > (float) $nc->saldo + 0.009) {
                throw new Exception("La NC {$nc->punto_venta}-{$nc->numero} tiene {$nc->saldo} a favor y se quieren aplicar {$importe}.");
            }
            if ($importe > (float) $factura->saldo + 0.009) {
                throw new Exception("La factura {$factura->punto_venta}-{$factura->numero} tiene saldo {$factura->saldo} y se quieren aplicar {$importe}.");
            }

            $nc->saldo = round((float) $nc->saldo - $importe, 2);
            $nc->save();
            $factura->saldo = round((float) $factura->saldo - $importe, 2);
            $factura->save();

            return TesIngImputacionEntity::create([
                'id_comprobante' => $factura->id_comprobante,
                'id_comprobante_nc' => $nc->id_comprobante,
                'importe' => $importe,
                'cod_usuario' => $this->user->cod_usuario,
                'fecha_registra' => $this->fechaActual,
            ]);
        });
    }

    /**
     * Deshace una aplicación de saldo a favor hecha con aplicarSaldoNotaCredito(). La aplicación
     * original de la NC a su factura (la de la importación) no se deshace por acá.
     */
    public function desaplicarSaldoNotaCredito($idImputacion): void
    {
        DB::transaction(function () use ($idImputacion) {
            $i = TesIngImputacionEntity::lockForUpdate()->find($idImputacion);
            if (!$i || !$i->id_comprobante_nc || $i->anulado) {
                throw new Exception('Aplicación de nota de crédito inexistente o ya deshecha.');
            }
            $nc = $this->comprobanteBloqueado($i->id_comprobante_nc, ['NC'], 'Nota de crédito');
            if ($nc->id_comprobante_asociado == $i->id_comprobante) {
                throw new Exception('Es la aplicación de la NC a su propia factura: no se deshace desde acá.');
            }
            $factura = TesIngComprobanteEntity::lockForUpdate()->find($i->id_comprobante);

            $nc->saldo = round((float) $nc->saldo + (float) $i->importe, 2);
            $nc->save();
            $factura->saldo = round((float) $factura->saldo + (float) $i->importe, 2);
            $factura->save();
            $i->anulado = 1;
            $i->save();
        });
    }

    private function comprobanteBloqueado($id, array $clases, string $nombre): TesIngComprobanteEntity
    {
        $c = TesIngComprobanteEntity::lockForUpdate()->find($id);
        $clase = $c ? DB::table('tb_tes_ing_tipo_comprobante')->where('id_tipo_comprobante', $c->id_tipo_comprobante)->value('clase') : null;
        if (!$c || $c->anulado || !in_array($clase, $clases)) {
            throw new Exception("{$nombre} inexistente o anulada.");
        }
        return $c;
    }

    private function clienteParaCuit(array $f): int
    {
        $cliente = TesIngClienteEntity::where('cuit', $f['cuit_receptor'])->first();
        if ($cliente) {
            return $cliente->id_cliente;
        }

        // Alta con la denominación de ARCA. Si el CUIT es de una empresa del grupo, queda marcado.
        $razonGrupo = RazonSocialModelo::where('cuit', $f['cuit_receptor'])->value('id_razon');

        return TesIngClienteEntity::create([
            'cuit' => $f['cuit_receptor'],
            'razon_social' => $f['denominacion_receptor'] ?? $f['cuit_receptor'],
            'id_razon_grupo' => $razonGrupo,
            'activo' => 1,
            'cod_usuario' => $this->user->cod_usuario,
            'fecha_registra' => $this->fechaActual,
        ])->id_cliente;
    }

    private function analizar(string $ruta, $idRazon, array $asociaciones): array
    {
        $razon = RazonSocialModelo::where('id_razon', $idRazon)->where('activo', 1)->first();
        if (!$razon || empty($razon->cuit)) {
            throw new Exception('Razón social inexistente, inactiva o sin CUIT.');
        }

        $leido = $this->lector->leer($ruta);
        $errores = [];

        // Que el archivo sea de la razón elegida: si no se puede verificar, corta (no se asume).
        if (empty($leido['cuit_emisor'])) {
            $errores[] = 'No se encontró el CUIT del emisor en el título del archivo: no se puede verificar que sea de ' . $razon->razon_social . '.';
        } elseif ($leido['cuit_emisor'] !== preg_replace('/\D/', '', $razon->cuit)) {
            $errores[] = "El archivo es del CUIT {$leido['cuit_emisor']}, no de {$razon->razon_social} ({$razon->cuit}).";
        }

        foreach ([RazonCuentaContableEntity::TIPO_VENTA_DEUDOR, RazonCuentaContableEntity::TIPO_VENTA_INGRESO] as $tipo) {
            if (!$this->tieneCuenta($idRazon, $tipo)) {
                $errores[] = "{$razon->razon_social} no tiene configurada la cuenta contable {$tipo}.";
            }
        }
        $tieneCuentaIva = $this->tieneCuenta($idRazon, RazonCuentaContableEntity::TIPO_VENTA_IVA);

        $tipos = DB::table('tb_tes_ing_tipo_comprobante')->where('vigente', 1)->get()->keyBy('id_tipo_comprobante');
        $clientes = TesIngClienteEntity::whereIn('cuit', array_column($leido['filas'], 'cuit_receptor'))->get()->keyBy('cuit');
        $periodos = [];
        $vistos = [];

        $filas = [];
        foreach ($leido['filas'] as $f) {
            $e = [];
            $tipo = $tipos[$f['id_tipo_comprobante']] ?? null;
            $f['clase'] = $tipo->clase ?? null;
            $f['letra'] = $tipo->letra ?? null;
            $f['descripcion'] = ($tipo->descripcion ?? $f['tipo_texto']) . ' ' . sprintf('%05d-%08d', $f['punto_venta'], $f['numero']);

            if (!$tipo) $e[] = "Tipo de comprobante \"{$f['tipo_texto']}\" no admitido (sólo FC, NC y ND A/B).";
            if (!$f['fecha_emision']) $e[] = 'Fecha inválida.';
            if (!$f['punto_venta'] || !$f['numero']) $e[] = 'Punto de venta o número inválido.';
            if ($f['numero_hasta'] && $f['numero_hasta'] != $f['numero']) $e[] = 'Rango de comprobantes (desde ≠ hasta): no admitido.';
            if ($f['moneda'] && !in_array(mb_strtoupper($f['moneda']), self::MONEDAS_PESOS)) $e[] = "Moneda {$f['moneda']}: sólo pesos.";
            if (!TesIngClienteRepository::cuitValido($f['cuit_receptor'])) $e[] = 'CUIT del receptor inválido.';

            $importes = ['importe_neto_gravado', 'importe_no_gravado', 'importe_exento', 'importe_otros_tributos', 'importe_iva', 'importe_total'];
            if (in_array(null, array_map(fn($k) => $f[$k], $importes), true)) {
                $e[] = 'Importe inválido.';
            } else {
                if ($f['importe_total'] <= 0) $e[] = 'Importe total debe ser mayor a cero.';
                if ($f['importe_otros_tributos'] > 0) $e[] = 'Tiene otros tributos: todavía no hay cuenta contable para imputarlos.';
                if ($f['importe_iva'] > 0 && !$tieneCuentaIva) $e[] = 'Tiene IVA y la razón social no tiene configurada la cuenta de IVA débito fiscal.';
                $suma = $f['importe_neto_gravado'] + $f['importe_no_gravado'] + $f['importe_exento'] + $f['importe_otros_tributos'] + $f['importe_iva'];
                if (abs($suma - $f['importe_total']) > 0.009) $e[] = 'La suma de neto, no gravado, exento, IVA y tributos no da el total.';
            }

            $clave = "{$f['id_tipo_comprobante']}-{$f['punto_venta']}-{$f['numero']}";
            if (isset($vistos[$clave])) $e[] = "Repetido en el archivo (fila {$vistos[$clave]}).";
            $vistos[$clave] ??= $f['fila'];

            if ($tipo && $f['punto_venta'] && $f['numero'] && TesIngComprobanteEntity::where([
                'id_razon' => $idRazon,
                'id_tipo_comprobante' => $f['id_tipo_comprobante'],
                'punto_venta' => $f['punto_venta'],
                'numero' => $f['numero'],
            ])->exists()) {
                $e[] = 'Ya importado.';
            }

            $f['id_periodo_contable'] = null;
            if ($f['fecha_emision']) {
                $yymm = substr($f['fecha_emision'], 2, 2) . substr($f['fecha_emision'], 5, 2);
                $periodos[$yymm] ??= $this->periodos->findByExistsPeriodoActivo($yymm, $idRazon)?->id_periodo_contable ?? false;
                $f['id_periodo_contable'] = $periodos[$yymm] ?: null;
                if (!$f['id_periodo_contable']) $e[] = "El período contable {$yymm} no está abierto para {$razon->razon_social}.";
            }

            $cliente = $clientes[$f['cuit_receptor']] ?? null;
            $f['id_cliente'] = $cliente->id_cliente ?? null;
            $f['cliente_nuevo'] = !$cliente;
            $f['avisos'] = $cliente ? [] : ['Cliente nuevo: se da de alta con la denominación de ARCA.'];
            $f['errores'] = $e;
            $filas[] = $f;
        }

        $filas = $this->resolverAsociaciones($filas, $idRazon, $asociaciones);

        foreach ($filas as &$f) {
            $f['estado'] = empty($f['errores']) ? 'OK' : 'OBSERVADA';
        }

        return [
            'razon' => ['id_razon' => $razon->id_razon, 'razon_social' => $razon->razon_social, 'cuit' => $razon->cuit],
            'cuit_emisor' => $leido['cuit_emisor'],
            'errores' => $errores,
            'filas' => $filas,
        ];
    }

    /**
     * NC (obligatoria) y ND (opcional): la factura asociada tiene que ser del mismo cliente, razón y
     * letra, con o sin saldo. Si la NC supera el saldo (descontando otras NC del archivo a la misma),
     * el excedente queda como saldo a favor en la NC.
     */
    private function resolverAsociaciones(array $filas, $idRazon, array $asociaciones): array
    {
        $facturasDelArchivo = array_filter($filas, fn($f) => $f['clase'] === 'FC' && empty($f['errores']));
        $saldoUsado = [];

        foreach ($filas as &$f) {
            $f['candidatas'] = [];
            $f['id_comprobante_asociado'] = null;
            $f['fila_asociada'] = null;

            if (!in_array($f['clase'], ['NC', 'ND']) || !empty($f['errores'])) {
                continue;
            }

            $enBase = TesIngComprobanteEntity::query()
                ->join('tb_tes_ing_tipo_comprobante as t', 't.id_tipo_comprobante', '=', 'tb_tes_ing_comprobante.id_tipo_comprobante')
                ->where('tb_tes_ing_comprobante.id_razon', $idRazon)
                ->where('tb_tes_ing_comprobante.id_cliente', $f['id_cliente'] ?? 0)
                ->where('t.clase', 'FC')
                ->where('t.letra', $f['letra'])
                ->where('tb_tes_ing_comprobante.anulado', 0)
                // también las ya cobradas: la NC puede aplicar a una factura sin saldo
                ->orderByDesc('tb_tes_ing_comprobante.saldo')
                ->orderBy('fecha_emision')
                ->get(['id_comprobante', 'punto_venta', 'numero', 'fecha_emision', 'importe_total', 'saldo']);

            foreach ($enBase as $c) {
                $f['candidatas'][] = [
                    'ref' => (string) $c->id_comprobante,
                    'descripcion' => sprintf('FC %s %05d-%08d', $f['letra'], $c->punto_venta, $c->numero),
                    'fecha_emision' => $c->fecha_emision,
                    'importe_total' => (float) $c->importe_total,
                    'saldo' => (float) $c->saldo,
                ];
            }
            foreach ($facturasDelArchivo as $fc) {
                if ($fc['cuit_receptor'] === $f['cuit_receptor'] && $fc['letra'] === $f['letra']) {
                    $f['candidatas'][] = [
                        'ref' => 'fila:' . $fc['fila'],
                        'descripcion' => $fc['descripcion'] . " (fila {$fc['fila']} de este archivo)",
                        'fecha_emision' => $fc['fecha_emision'],
                        'importe_total' => $fc['importe_total'],
                        'saldo' => $fc['importe_total'],
                    ];
                }
            }

            $ref = isset($asociaciones[$f['fila']]) ? (string) $asociaciones[$f['fila']] : null;
            if ($ref === null || $ref === '') {
                if ($f['clase'] === 'NC') {
                    $f['errores'][] = empty($f['candidatas'])
                        ? 'NC sin factura a la que aplicar: el cliente no tiene facturas con saldo de la misma letra.'
                        : 'Elegir la factura a la que aplica la NC.';
                }
                continue;
            }

            $elegida = collect($f['candidatas'])->firstWhere('ref', $ref);
            if (!$elegida) {
                $f['errores'][] = 'La factura elegida no es del mismo cliente, razón social y letra.';
                continue;
            }

            if (str_starts_with($ref, 'fila:')) {
                $f['fila_asociada'] = (int) substr($ref, 5);
            } else {
                $f['id_comprobante_asociado'] = (int) $ref;
            }

            // Lo que la NC no puede aplicar a su factura (ya cobrada) queda como saldo a favor del
            // cliente en la propia NC (README 9, respuesta 6 de la segunda ronda).
            if ($f['clase'] === 'NC') {
                $disponible = max(0, round($elegida['saldo'] - ($saldoUsado[$ref] ?? 0), 2));
                $aplica = min($f['importe_total'], $disponible);
                $saldoUsado[$ref] = ($saldoUsado[$ref] ?? 0) + $aplica;
                if ($f['importe_total'] > $aplica + 0.009) {
                    $f['avisos'][] = 'Aplica ' . number_format($aplica, 2, ',', '.') . ' a ' . $elegida['descripcion']
                        . '; quedan ' . number_format($f['importe_total'] - $aplica, 2, ',', '.') . ' como saldo a favor del cliente.';
                }
            }
        }

        return $filas;
    }

    private function tieneCuenta($idRazon, string $tipo): bool
    {
        return RazonCuentaContableEntity::where('id_razon', $idRazon)
            ->where('tipo_contraparte', $tipo)
            ->where('vigente', 1)
            ->whereNotNull('id_detalle_plan')
            ->exists();
    }
}
