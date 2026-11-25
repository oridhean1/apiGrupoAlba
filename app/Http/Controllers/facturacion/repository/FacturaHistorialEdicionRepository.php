<?php

namespace App\Http\Controllers\facturacion\repository;

use Illuminate\Support\Facades\DB;

/**
 * Historial de ediciones de facturas (R-00000694).
 *
 * Cada vez que se edita una factura se guarda una fila en tb_facturacion_historial_edicion
 * (quién, cuándo, de qué estado venía) y una fila por dato cambiado en
 * tb_facturacion_historial_cambio (valor anterior / valor nuevo, ya legibles: nombres, no ids).
 *
 * Si la factura de prestador estaba EN AUDITORIA (estado 1 o 5), al editarla vuelve a PENDIENTE
 * y se guarda en `auditoria_json` una copia de cómo estaba la liquidación (prácticas y
 * medicamentos con sus débitos) en ese momento, como referencia.
 *
 * Definido por el cliente (respuesta del 2026-11-25):
 *  - La liquidación cargada por auditoría SE MANTIENE: no se borra ni se modifica (ni líneas ni
 *    totales de liquidación). El área de auditoría la revisa y corrige desde Liquidaciones, que
 *    vuelve a pasar la factura a auditoría al guardar.
 *  - No se edita si la factura tiene orden de pago vigente (ver FacturacionProcesosController).
 *  - Dictámenes médicos y débitos internos quedan asociados a la factura (no se tocan).
 *
 * Nota: tb_facturacion_auditar / AuditarFacturaController no son la auditoría real (la tabla
 * está vacía en Alba y OSV y la columna monto_debitar no existe); por eso no se usan acá.
 */
class FacturaHistorialEdicionRepository
{
    const ESTADOS_EN_AUDITORIA = [1, 5];
    const ESTADO_PENDIENTE = 0;

    /** Campos de cabecera que se comparan, con la etiqueta que ve el usuario. */
    const CAMPOS_CABECERA = [
        'id_prestador' => 'Prestador',
        'id_proveedor' => 'Proveedor',
        'id_tipo_comprobante' => 'Tipo de comprobante',
        'tipo_letra' => 'Letra',
        'sucursal' => 'Punto de venta',
        'numero' => 'Número',
        'fecha_comprobante' => 'Fecha de comprobante',
        'fecha_vencimiento' => 'Fecha de vencimiento',
        'fecha_registra' => 'Fecha de registro',
        'periodo' => 'Período',
        'cae_cai' => 'CAE / CAI',
        'id_tipo_factura' => 'Tipo de factura',
        'id_tipo_imputacion_sintetizada' => 'Imputación contable',
        'cod_sindicato' => 'Locatario',
        'id_locatorio' => 'Razón social',
        'refacturacion' => 'Refacturación',
        'subtotal' => 'Subtotal',
        'total_iva' => 'Total IVA',
        'total_neto' => 'Monto',
        'observaciones_resumen' => 'Observaciones',
        'comprobante_relacionado' => 'Comprobante relacionado',
        'tipo_hospital' => 'Tipo de hospital',
        'cod_hospital' => 'Hospital',
    ];

    const CAMPOS_IMPORTE = ['subtotal', 'total_iva', 'total_neto'];
    const CAMPOS_FECHA = ['fecha_comprobante', 'fecha_vencimiento', 'fecha_registra'];

    /** Prestador puro: con prestador y sin proveedor (hay 2 en Alba con los dos; quedan como antes). */
    public function esFacturaPrestador($factura): bool
    {
        return !empty($factura->id_prestador) && empty($factura->id_proveedor);
    }

    public function estaEnAuditoria($estado): bool
    {
        return in_array((int) $estado, self::ESTADOS_EN_AUDITORIA, true);
    }

    /**
     * Foto de la factura tal como está en la base (cabecera + ítems + impuestos + descuentos).
     */
    public function capturarFactura($idFactura): array
    {
        $cabecera = (array) DB::table('tb_facturacion_datos')->where('id_factura', $idFactura)->first();

        $detalle = DB::table('tb_facturacion_detalle as d')
            ->leftJoin('tb_articulos_matriz as a', 'a.id_articulo', '=', 'd.id_articulo')
            ->where('d.id_factura', $idFactura)
            ->orderBy('d.id_detalle')
            ->get(['d.id_articulo', 'a.descripcion_articulo', 'd.cantidad', 'd.precio_neto', 'd.iva', 'd.total_importe', 'd.observaciones'])
            ->map(fn($r) => (array) $r)->all();

        $impuestos = DB::table('tb_facturacion_detalle_impuesto')
            ->where('id_factura', $idFactura)
            ->orderBy('id_detalle_impuesto')
            ->get(['impuesto', 'porcentaje', 'importe'])
            ->map(fn($r) => (array) $r)->all();

        $descuentos = DB::table('tb_facturacion_detalle_descuento')
            ->where('id_factura', $idFactura)
            ->get(['descuento', 'importe', 'observaciones'])
            ->map(fn($r) => (array) $r)->all();

        return compact('cabecera', 'detalle', 'impuestos', 'descuentos');
    }

    /**
     * Lista de [campo, valor_anterior, valor_nuevo] con los datos que cambiaron, ya legibles.
     */
    public function compararFotos(array $antes, array $despues): array
    {
        $cambios = [];

        foreach (self::CAMPOS_CABECERA as $campo => $etiqueta) {
            $valorAntes = $antes['cabecera'][$campo] ?? null;
            $valorDespues = $despues['cabecera'][$campo] ?? null;

            if ($this->normalizar($campo, $valorAntes) === $this->normalizar($campo, $valorDespues)) {
                continue;
            }

            $cambios[] = [
                'campo' => $etiqueta,
                'valor_anterior' => $this->legible($campo, $valorAntes),
                'valor_nuevo' => $this->legible($campo, $valorDespues),
            ];
        }

        $listas = [
            'detalle' => ['Ítems', fn($l) => $this->resumirItems($l)],
            'impuestos' => ['Impuestos', fn($l) => $this->resumirImpuestos($l)],
            'descuentos' => ['Descuentos', fn($l) => $this->resumirDescuentos($l)],
        ];
        foreach ($listas as $clave => [$etiqueta, $resumir]) {
            $textoAntes = $resumir($antes[$clave]);
            $textoDespues = $resumir($despues[$clave]);
            if ($textoAntes !== $textoDespues) {
                $cambios[] = ['campo' => $etiqueta, 'valor_anterior' => $textoAntes, 'valor_nuevo' => $textoDespues];
            }
        }

        return $cambios;
    }

    /**
     * Copia de la liquidación (prácticas y medicamentos con sus líneas) tal como estaba al
     * editar, para el historial. Solo lee: no modifica nada. Devuelve null si no hay nada.
     */
    public function capturarAuditoria($idFactura): ?array
    {
        $factura = DB::table('tb_facturacion_datos')->where('id_factura', $idFactura)
            ->first(['total_facturado_liquidacion', 'total_aprobado_liquidacion', 'total_debitado_liquidacion', 'fecha_liquidacion']);

        $practicas = DB::table('tb_liquidaciones')->where('id_factura', $idFactura)->orderBy('id_liquidacion')->get();
        $idsPracticas = $practicas->pluck('id_liquidacion')->all();

        $medicamentos = DB::table('tb_liquidaciones_medicamentos')->where('id_factura', $idFactura)->orderBy('id_liquidacion')->get();
        $idsMedicamentos = $medicamentos->pluck('id_liquidacion')->all();

        if (empty($idsPracticas) && empty($idsMedicamentos) && (float) ($factura->total_debitado_liquidacion ?? 0) == 0) {
            return null;
        }

        // No se usan vw_detalle_liquidaciones / vw_detalle_medicamentos: la primera hace INNER JOIN
        // con tb_padron (si el afiliado ya no está, la línea —y su débito— desaparece) y la
        // segunda une el detalle con tb_liquidaciones en vez de tb_liquidaciones_medicamentos.
        // Acá todo es LEFT JOIN para no perder ninguna línea.
        $lineasPracticas = empty($idsPracticas) ? [] : DB::table('tb_liquidaciones_detalle as dt')
            ->join('tb_liquidaciones as lq', 'lq.id_liquidacion', '=', 'dt.id_liquidacion')
            ->leftJoin('tb_practicas_matriz as pr', 'pr.id_identificador_practica', '=', 'dt.id_identificador_practica')
            ->leftJoin('tb_padron as afi', 'afi.id', '=', 'lq.id_afiliado')
            ->leftJoin('tb_liquidaciones_tipo_motivos_debito as mb', 'mb.id_tipo_motivo_debito', '=', 'dt.id_tipo_motivo_debito')
            ->whereIn('dt.id_liquidacion', $idsPracticas)
            ->orderBy('dt.id_liquidacion')->orderBy('dt.id_detalle')
            ->get([
                'dt.id_detalle', 'dt.id_liquidacion', 'dt.fecha_prestacion', 'pr.codigo_practica',
                DB::raw('TRIM(pr.nombre_practica) as practica'),
                DB::raw("TRIM(CONCAT(IFNULL(afi.apellidos, ''), ' ', IFNULL(afi.nombre, ''))) as afiliado"),
                DB::raw('IFNULL(afi.dni, lq.dni_afiliado) as dni_afiliado'),
                'dt.cantidad', 'dt.monto_facturado', 'dt.monto_aprobado', 'dt.monto_debitado',
                'mb.descripcion_motivo as motivo_debito', 'dt.observacion_debito',
            ])->map(fn($r) => (array) $r)->all();

        $lineasMedicamentos = empty($idsMedicamentos) ? [] : DB::table('tb_liquidaciones_detalle_medicamentos as dt')
            ->leftJoin('tb_liquidaciones_matriz_medicamentos as med', 'med.id_medicamento', '=', 'dt.id_medicamento')
            ->leftJoin('tb_liquidaciones_tipo_motivos_debito as mb', 'mb.id_tipo_motivo_debito', '=', 'dt.id_tipo_motivo_debito')
            ->whereIn('dt.id_liquidacion', $idsMedicamentos)
            ->orderBy('dt.id_liquidacion')->orderBy('dt.id_detalle')
            ->get([
                'dt.*', 'med.nombre as medicamento', 'med.presentacion',
                'mb.descripcion_motivo as motivo_debito',
            ])->map(fn($r) => (array) $r)->all();

        return [
            'totales' => (array) $factura,
            'practicas' => $practicas->map(fn($r) => (array) $r)->all(),
            'lineas_practicas' => $lineasPracticas,
            // Copia cruda de tb_liquidaciones_detalle: la vista no trae todas las columnas.
            'lineas_practicas_tabla' => empty($idsPracticas) ? [] : DB::table('tb_liquidaciones_detalle')
                ->whereIn('id_liquidacion', $idsPracticas)->orderBy('id_detalle')
                ->get()->map(fn($r) => (array) $r)->all(),
            'medicamentos' => $medicamentos->map(fn($r) => (array) $r)->all(),
            'lineas_medicamentos' => $lineasMedicamentos,
        ];
    }

    /**
     * Deja la factura en PENDIENTE. Solo cambia el estado: la liquidación cargada y sus totales
     * se mantienen tal cual (decisión del cliente).
     */
    public function volverAPendiente($idFactura): void
    {
        DB::table('tb_facturacion_datos')->where('id_factura', $idFactura)
            ->update(['estado' => self::ESTADO_PENDIENTE]);
    }

    public function registrarEdicion($idFactura, $codUsuario, $fecha, $estadoAnterior, $estadoNuevo, array $cambios, ?array $auditoria): int
    {
        $idEdicion = DB::table('tb_facturacion_historial_edicion')->insertGetId([
            'id_factura' => $idFactura,
            'cod_usuario' => $codUsuario,
            'fecha' => $fecha,
            'estado_anterior' => (int) $estadoAnterior,
            'estado_nuevo' => (int) $estadoNuevo,
            'con_auditoria' => is_null($auditoria) ? 0 : 1,
            'total_debitado_al_editar' => (float) ($auditoria['totales']['total_debitado_liquidacion'] ?? 0),
            'auditoria_json' => is_null($auditoria) ? null : json_encode($auditoria, JSON_UNESCAPED_UNICODE),
        ]);

        foreach ($cambios as $cambio) {
            DB::table('tb_facturacion_historial_cambio')->insert([
                'id_edicion' => $idEdicion,
                'campo' => $cambio['campo'],
                'valor_anterior' => $cambio['valor_anterior'],
                'valor_nuevo' => $cambio['valor_nuevo'],
            ]);
        }

        return $idEdicion;
    }

    /**
     * Historial de una factura para mostrar en pantalla, de la edición más nueva a la más vieja.
     */
    public function listarPorFactura($idFactura): array
    {
        $ediciones = DB::table('tb_facturacion_historial_edicion as h')
            ->leftJoin('tb_usuarios as u', 'u.cod_usuario', '=', 'h.cod_usuario')
            ->where('h.id_factura', $idFactura)
            ->orderByDesc('h.fecha')->orderByDesc('h.id_edicion')
            ->get(['h.*', 'u.nombre_apellidos as usuario']);

        if ($ediciones->isEmpty()) {
            return [];
        }

        $cambios = DB::table('tb_facturacion_historial_cambio')
            ->whereIn('id_edicion', $ediciones->pluck('id_edicion')->all())
            ->orderBy('id_cambio')
            ->get()
            ->groupBy('id_edicion');

        return $ediciones->map(function ($e) use ($cambios) {
            $auditoria = $e->auditoria_json ? json_decode($e->auditoria_json, true) : null;

            return [
                'id_edicion' => $e->id_edicion,
                'fecha' => $e->fecha,
                'usuario' => $e->usuario ?? ('Usuario ' . $e->cod_usuario),
                'estado_anterior' => $this->nombreEstado($e->estado_anterior),
                'estado_nuevo' => $this->nombreEstado($e->estado_nuevo),
                'con_auditoria' => (bool) $e->con_auditoria,
                'total_debitado_al_editar' => (float) $e->total_debitado_al_editar,
                'cambios' => ($cambios[$e->id_edicion] ?? collect())->map(fn($c) => [
                    'campo' => $c->campo,
                    'valor_anterior' => $c->valor_anterior,
                    'valor_nuevo' => $c->valor_nuevo,
                ])->values()->all(),
                'debitos' => $this->debitosAlEditar($auditoria),
            ];
        })->all();
    }

    // ───────────────────────── helpers ─────────────────────────

    /** Líneas de la liquidación al momento de editar, para mostrar (prácticas y medicamentos). */
    private function debitosAlEditar(?array $auditoria): array
    {
        if (is_null($auditoria)) {
            return [];
        }

        $practicas = collect($auditoria['lineas_practicas'] ?? [])->map(fn($l) => [
            'tipo' => 'Práctica',
            'afiliado' => trim(($l['afiliado'] ?? '') . (empty($l['dni_afiliado']) ? '' : ' (' . $l['dni_afiliado'] . ')')),
            'fecha' => $l['fecha_prestacion'] ?? null,
            'descripcion' => trim(($l['codigo_practica'] ?? '') . ' ' . ($l['practica'] ?? '')),
            'cantidad' => $l['cantidad'] ?? null,
            'monto_facturado' => (float) ($l['monto_facturado'] ?? 0),
            'monto_aprobado' => (float) ($l['monto_aprobado'] ?? 0),
            'monto_debitado' => (float) ($l['monto_debitado'] ?? 0),
            'motivo' => $l['motivo_debito'] ?? null,
            'observacion' => $l['observacion_debito'] ?? null,
        ]);

        // tb_liquidaciones_detalle_medicamentos no tiene débito por línea: el débito de
        // medicamentos está en la cabecera de cada liquidación (total_debitado).
        $medicamentos = collect($auditoria['medicamentos'] ?? [])->map(function ($m) use ($auditoria) {
            $lineas = collect($auditoria['lineas_medicamentos'] ?? [])->where('id_liquidacion', $m['id_liquidacion']);
            return [
                'tipo' => 'Medicamentos',
                'afiliado' => null,
                'fecha' => $m['fecha_venta'] ?? null,
                'descripcion' => $lineas->pluck('medicamento')->filter()->implode(', '),
                'cantidad' => $lineas->sum('cantidad'),
                'monto_facturado' => (float) ($m['total_facturado'] ?? 0),
                'monto_aprobado' => (float) ($m['total_aprobado'] ?? 0),
                'monto_debitado' => (float) ($m['total_debitado'] ?? 0),
                'motivo' => $lineas->pluck('motivo_debito')->filter()->unique()->implode(', '),
                'observacion' => $m['observaciones_debito'] ?? null,
            ];
        });

        return $practicas->concat($medicamentos)->values()->all();
    }

    private function normalizar(string $campo, $valor)
    {
        if (is_null($valor) || $valor === '') {
            return null;
        }
        if (in_array($campo, self::CAMPOS_IMPORTE, true)) {
            return number_format((float) $valor, 2, '.', '');
        }
        if (in_array($campo, self::CAMPOS_FECHA, true)) {
            return substr((string) $valor, 0, 10);
        }
        return trim((string) $valor);
    }

    private function legible(string $campo, $valor): ?string
    {
        $valor = $this->normalizar($campo, $valor);
        if (is_null($valor)) {
            return null;
        }

        if (in_array($campo, self::CAMPOS_IMPORTE, true)) {
            return '$' . number_format((float) $valor, 2, ',', '.');
        }
        if (in_array($campo, self::CAMPOS_FECHA, true)) {
            $partes = explode('-', $valor);
            return count($partes) === 3 ? "{$partes[2]}/{$partes[1]}/{$partes[0]}" : $valor;
        }
        if ($campo === 'refacturacion') {
            return $valor === '1' ? 'SI' : 'NO';
        }

        $nombre = match ($campo) {
            'id_prestador' => DB::table('tb_prestador')->where('cod_prestador', $valor)->value('razon_social'),
            'id_proveedor' => DB::table('tb_proveedor')->where('cod_proveedor', $valor)->value('razon_social'),
            'id_tipo_comprobante' => DB::table('tb_facturacion_tipo_comprobantes')->where('id_tipo_comprobante', $valor)->value('descripcion'),
            'id_tipo_factura' => DB::table('tb_facturacion_tipo')->where('id_tipo_factura', $valor)->value('descripcion'),
            'id_tipo_imputacion_sintetizada' => DB::table('tb_facturacion_tipo_imputacion_sintetizada')->where('id_tipo_imputacion_sintetizada', $valor)->value('descripcion'),
            'cod_sindicato' => DB::table('tb_locatorio')->where('id_locatorio', $valor)->value('locatorio'),
            'id_locatorio' => DB::table('tb_razones_sociales')->where('id_razon', $valor)->value('razon_social'),
            default => null,
        };

        return $nombre ?? $valor;
    }

    private function importe($valor): string
    {
        return '$' . number_format((float) $valor, 2, ',', '.');
    }

    private function resumirItems(array $lineas): string
    {
        if (empty($lineas)) {
            return 'Sin ítems';
        }
        return collect($lineas)->map(function ($l) {
            $nombre = $l['descripcion_articulo'] ?? ('Artículo ' . $l['id_articulo']);
            return "{$nombre} x" . (float) $l['cantidad'] . ' ' . $this->importe($l['total_importe']);
        })->implode('; ');
    }

    private function resumirImpuestos(array $lineas): string
    {
        if (empty($lineas)) {
            return 'Sin impuestos';
        }
        return collect($lineas)->map(fn($l) => "{$l['impuesto']} " . (float) $l['porcentaje'] . '% ' . $this->importe($l['importe']))->implode('; ');
    }

    private function resumirDescuentos(array $lineas): string
    {
        if (empty($lineas)) {
            return 'Sin descuentos';
        }
        return collect($lineas)->map(fn($l) => "{$l['descuento']} " . $this->importe($l['importe']))->implode('; ');
    }

    private function nombreEstado($estado): string
    {
        return match ((int) $estado) {
            0 => 'PENDIENTE',
            1, 5 => 'EN AUDITORIA',
            2 => 'CERRADA',
            3 => 'VALORIZACION FINAL',
            4 => 'ANULADO',
            default => (string) $estado,
        };
    }
}
