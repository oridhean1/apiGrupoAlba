<?php

namespace App\Http\Controllers\Superdashboard\Repository;

use Illuminate\Support\Facades\DB;

/**
 * Datos de la pantalla Actividad del Superdashboard (R-00000601, copia del de OSPF).
 *
 * No hay un log único de eventos: el feed se arma uniendo las tablas de cada módulo por su
 * fecha de registro. Las fuentes con fecha sin hora (altas, OPAs, mesa de entrada…) cuentan
 * en los totales pero no en el mapa por hora.
 *
 * Padrón con el criterio de Alba: altas por tb_padron.fe_alta, bajas por tb_padron.fe_baja
 * (con su motivo) y modificaciones del historial (tb_auditoria_padron). Alba no usa cheques
 * ni liquidaciones de recetas: no se muestran (pedido de Leandro).
 */
class ActividadRepository
{
    /** Orden en que se muestran los tipos de operación. */
    public const TIPOS = ['alta', 'baja', 'mod', 'ing', 'fact', 'op', 'pago', 'asi', 'liq', 'rei', 'int', 'pro', 'mac', 'dis', 'fis'];

    /** tb_tes_estado_orden_pago: rechazada */
    private const OPA_NO_VIGENTE = [3];

    /** Importe liquidado de una factura (alias f): neto menos débitos. */
    private const MONTO_LIQUIDADO = '(IFNULL(f.total_neto, 0) - IFNULL(f.total_debitado_liquidacion, 0))';

    /** Campos técnicos que se reescriben en cada guardado del padrón y no son un cambio del usuario. */
    private const CAMPOS_EXCLUIDOS = ['id', 'id_usuario', 'fecha_carga', 'id_estado_super', 'fech_descarga'];

    /** Fechas "vacías" que usa el sistema en lugar de null. */
    private const FECHAS_VACIAS = ['1900-01-01', '1753-01-01'];

    private const ETIQUETAS = [
        'cuil_tit' => 'CUIL titular',
        'cuil_benef' => 'CUIL beneficiario',
        'id_tipo_documento' => 'Tipo de documento',
        'dni' => 'DNI',
        'nombre' => 'Nombre',
        'apellidos' => 'Apellidos',
        'id_sexo' => 'Sexo',
        'id_estado_civil' => 'Estado civil',
        'fe_nac' => 'Fecha de nacimiento',
        'id_nacionalidad' => 'Nacionalidad',
        'calle' => 'Calle',
        'numero' => 'Número',
        'piso' => 'Piso',
        'depto' => 'Departamento',
        'id_localidad' => 'Localidad',
        'id_partido' => 'Partido',
        'id_provincia' => 'Provincia',
        'telefono' => 'Teléfono',
        'fe_alta' => 'Fecha de alta',
        'id_tipo_beneficiario' => 'Tipo de beneficiario',
        'id_situacion_de_revista' => 'Situación de revista',
        'id_tipo_domicilio' => 'Tipo de domicilio',
        'id_parentesco' => 'Parentesco',
        'email' => 'Email',
        'celular' => 'Celular',
        'fe_baja' => 'Fecha de baja',
        'activo' => 'Estado',
        'id_cpostal' => 'Código postal',
        'observaciones' => 'Observaciones',
        'id_delegacion' => 'Filial',
        'domicilio_laboral' => 'Domicilio laboral',
        'domicilio_postal' => 'Domicilio postal',
        'id_locatario' => 'Obra social',
        'credencial' => 'Credencial',
        'patologia' => 'Patología',
        'medicacion' => 'Medicación',
        'discapacidad' => 'Discapacidad',
        'id_baja_motivos' => 'Motivo de baja',
        'id_comercial_origen' => 'Origen comercial',
        'id_comercial_caja' => 'Caja comercial',
    ];

    // ================================================================== eventos

    /**
     * Unión de todas las operaciones registradas entre dos fechas, con las mismas columnas:
     * id, tipo, fecha, titulo, detalle, monto, cod_usuario, usuario (nombre libre si no hay código).
     */
    private function queryEventos(string $desde, string $hasta)
    {
        $d0 = $desde . ' 00:00:00';
        $d1 = $hasta . ' 23:59:59';
        $afiliado = "CONCAT('DNI ', p.dni, ' · ', TRIM(CONCAT(IFNULL(p.apellidos, ''), ' ', IFNULL(p.nombre, ''))))";
        $liquidado = self::MONTO_LIQUIDADO;

        $liquidaciones = DB::table('tb_liquidaciones')
            ->whereBetween('fecha_registra', [$d0, $d1])
            ->groupBy('id_factura', DB::raw('DATE(fecha_registra)'))
            ->select(['id_factura', DB::raw('MAX(fecha_registra) as fecha'), DB::raw('MAX(cod_usuario) as cod_usuario'),
                DB::raw('COUNT(*) as prestaciones')]);

        $q = [];

        $q[] = DB::table('tb_padron as p')
            ->whereBetween('p.fe_alta', [$desde, $hasta])
            ->selectRaw("CONCAT('alta-', p.id) id, 'alta' tipo, p.fe_alta fecha, 'Alta de afiliado' titulo,
                $afiliado detalle, NULL monto, p.id_usuario cod_usuario, NULL usuario");

        $q[] = DB::table('tb_padron as p')
            ->whereBetween('p.fe_baja', [$desde, $hasta])
            ->selectRaw("CONCAT('baja-', p.id) id, 'baja' tipo, p.fe_baja fecha, 'Baja de afiliado' titulo,
                $afiliado detalle, NULL monto, NULL cod_usuario, NULL usuario");

        // Las altas se registran con '-' en "antes": no son una modificación
        $q[] = DB::table('tb_auditoria_padron as a')
            ->leftJoin('tb_padron as p', 'p.id', '=', 'a.id_padron')
            ->whereBetween('a.fecha', [$d0, $d1])
            ->where(fn ($w) => $w->whereNull('a.antes')->orWhere('a.antes', '<>', '-'))
            ->selectRaw("CONCAT('mod-', a.id) id, 'mod' tipo, a.fecha, 'Modificación de padrón' titulo,
                $afiliado detalle, NULL monto, a.cod_usuario, NULL usuario");

        $q[] = DB::table('tb_mesa_entrada as me')
            ->whereBetween('me.fecha_carga', [$desde, $hasta])
            ->selectRaw("CONCAT('ing-', me.cod_mesa) id, 'ing' tipo, me.fecha_carga fecha, 'Ingreso de facturación' titulo,
                CONCAT_WS(' · ', NULLIF(me.emisor, ''), IF(me.nro_factura IS NULL OR me.nro_factura = '', NULL, CONCAT('Fact. ', me.nro_factura))) detalle,
                me.importe monto, me.cod_usuario, NULL usuario");

        $q[] = DB::table('tb_facturacion_datos as f')
            ->leftJoin('tb_prestador as pr', 'pr.cod_prestador', '=', 'f.id_prestador')
            ->leftJoin('tb_proveedor as pv', 'pv.cod_proveedor', '=', 'f.id_proveedor')
            ->where('f.estado', '<>', FinanzasRepository::FACTURA_ANULADA)
            ->whereBetween('f.fecha_registra', [$desde, $hasta])
            ->selectRaw("CONCAT('fact-', f.id_factura) id, 'fact' tipo, f.fecha_registra fecha, 'Factura cargada' titulo,
                CONCAT('C ', IFNULL(f.sucursal, ''), '-', IFNULL(f.numero, ''), ' · ', COALESCE(pr.razon_social, pv.razon_social, '')) detalle,
                f.total_neto monto, f.cod_usuario, NULL usuario");

        $q[] = DB::table('tb_tes_orden_pago as opa')
            ->leftJoin('tb_prestador as pr', 'pr.cod_prestador', '=', 'opa.id_prestador')
            ->leftJoin('tb_proveedor as pv', 'pv.cod_proveedor', '=', 'opa.id_proveedor')
            ->whereNotIn('opa.id_estado_orden_pago', self::OPA_NO_VIGENTE)
            ->whereBetween('opa.fecha_emision', [$d0, $d1])
            ->selectRaw("CONCAT('op-', opa.id_orden_pago) id, 'op' tipo, opa.fecha_emision fecha, 'OP generada' titulo,
                CONCAT('OP ', opa.id_orden_pago, IFNULL(CONCAT(' · ', COALESCE(pr.razon_social, pv.razon_social)), '')) detalle,
                opa.monto_orden_pago monto, opa.cod_usuario, NULL usuario");

        $q[] = DB::table('tb_tes_movimiento_cuenta_bancaria as m')
            ->join('tb_tes_pago as tp', 'tp.id_pago', '=', 'm.id_pago')
            ->leftJoin('tb_tes_orden_pago as opa', 'opa.id_orden_pago', '=', 'tp.id_orden_pago')
            ->leftJoin('tb_prestador as pr', 'pr.cod_prestador', '=', 'opa.id_prestador')
            ->leftJoin('tb_proveedor as pv', 'pv.cod_proveedor', '=', 'opa.id_proveedor')
            ->where('m.tipo_movimiento', 'EGRESO')
            ->whereBetween('m.fecha_movimiento', [$d0, $d1])
            ->selectRaw("CONCAT('pago-', m.id_movimiento) id, 'pago' tipo, m.fecha_movimiento fecha, 'Pago confirmado' titulo,
                CONCAT('OP ', IFNULL(tp.id_orden_pago, '-'), IFNULL(CONCAT(' · ', COALESCE(pr.razon_social, pv.razon_social)), '')) detalle,
                m.monto, m.id_usuario cod_usuario, NULL usuario");

        $q[] = DB::table('tb_cont_asientos_contables as a')
            ->where('a.vigente', 'ACTIVO')
            ->whereBetween('a.fecha_registra', [$d0, $d1])
            ->selectRaw("CONCAT('asi-', a.id_asiento_contable) id, 'asi' tipo, a.fecha_registra fecha, 'Asiento generado' titulo,
                CONCAT('#', IFNULL(a.numero, a.id_asiento_contable), ' · ', IFNULL(a.asiento_modelo, 'Automático'),
                    IFNULL(CONCAT(' · ', LEFT(NULLIF(a.asiento_leyenda, ''), 60)), '')) detalle,
                NULL monto, a.cod_usuario_crea cod_usuario, NULL usuario");

        $q[] = DB::query()->fromSub($liquidaciones, 'l')
            ->leftJoin('tb_facturacion_datos as f', 'f.id_factura', '=', 'l.id_factura')
            ->leftJoin('tb_prestador as pr', 'pr.cod_prestador', '=', 'f.id_prestador')
            ->selectRaw("CONCAT('liq-', l.id_factura, '-', DATE(l.fecha)) id, 'liq' tipo, l.fecha, 'Liquidación realizada' titulo,
                CONCAT('Prestador · ', IFNULL(pr.razon_social, CONCAT('factura ', l.id_factura)), ' · ', l.prestaciones, ' prest.') detalle,
                $liquidado monto, l.cod_usuario, NULL usuario");

        $q[] = DB::table('tb_reintegros as r')
            ->leftJoin('tb_padron as p', 'p.id', '=', 'r.id_afiliados')
            ->whereBetween('r.fecha_carga', [$desde, $hasta])
            ->selectRaw("CONCAT('rei-', r.nro_reintegro) id, 'rei' tipo, r.fecha_carga fecha, 'Reintegro cargado' titulo,
                $afiliado detalle, r.importe_solicitado monto, r.id_usuario cod_usuario, NULL usuario");

        $q[] = DB::table('tb_internaciones as i')
            ->leftJoin('tb_prestador as pr', 'pr.cod_prestador', '=', 'i.cod_prestador')
            ->where('i.vigente', 1)
            ->whereBetween('i.fecha_ingresa', [$desde, $hasta])
            ->selectRaw("CONCAT('int-', i.cod_internacion) id, 'int' tipo, i.fecha_ingresa fecha, 'Internación registrada' titulo,
                CONCAT('DNI ', IFNULL(i.dni_afiliado, '-'), IFNULL(CONCAT(' · ', pr.razon_social), '')) detalle,
                NULL monto, i.cod_usuario_registra cod_usuario, NULL usuario");

        $q[] = DB::table('tb_protesis as pt')
            ->leftJoin('tb_prestador as pr', 'pr.cod_prestador', '=', 'pt.cod_prestador')
            ->whereBetween('pt.fecha_emision', [$desde, $hasta])
            ->selectRaw("CONCAT('pro-', pt.id_protesis) id, 'pro' tipo, pt.fecha_emision fecha, 'Solicitud de prótesis' titulo,
                CONCAT('DNI ', IFNULL(pt.dni_afiliado, '-'), IFNULL(CONCAT(' · ', pr.razon_social), '')) detalle,
                pt.importe_total monto, pt.cod_usuario_registra cod_usuario, NULL usuario");

        $q[] = DB::table('tb_medicacion_alto_costo as mac')
            ->whereBetween('mac.fecha_registro', [$d0, $d1])
            ->selectRaw("CONCAT('mac-', mac.id_medicacion_alto_costo) id, 'mac' tipo, mac.fecha_registro fecha,
                'Medicación alto costo' titulo, CONCAT('DNI ', IFNULL(mac.dni_afiliado, '-')) detalle,
                mac.importe_cubierto monto, mac.id_usuario cod_usuario, NULL usuario");

        $q[] = DB::table('tb_discapacidad as d')
            ->whereBetween('d.fecha_registra', [$desde, $hasta])
            ->selectRaw("CONCAT('dis-', d.id_discapacidad) id, 'dis' tipo, d.fecha_registra fecha, 'Prestación de discapacidad' titulo,
                IFNULL(d.razon_social_prestador, 'Discapacidad') detalle,
                d.monto_comprobante monto, d.cod_usuario, NULL usuario");

        $q[] = DB::table('tb_fisca_cobranzas as fc')
            ->leftJoin('tb_empresa as e', 'e.id_empresa', '=', 'fc.id_empresa')
            ->whereBetween('fc.fecha_creacion', [$d0, $d1])
            ->selectRaw("CONCAT('fisc-', fc.id_cobranza) id, 'fis' tipo, fc.fecha_creacion fecha, 'Cobranza de fiscalización' titulo,
                IFNULL(e.razon_social, 'Empresa') detalle, fc.cobro_total monto, NULL cod_usuario, fc.usuario");

        $q[] = DB::table('tb_fisca_expedientes as fe')
            ->leftJoin('tb_empresa as e', 'e.id_empresa', '=', 'fe.id_empresa')
            ->whereBetween('fe.fecha_creacion', [$desde, $hasta])
            ->selectRaw("CONCAT('fise-', fe.id_expediente) id, 'fis' tipo, fe.fecha_creacion fecha, 'Expediente de fiscalización' titulo,
                IFNULL(e.razon_social, 'Empresa') detalle, NULL monto, fe.id_usuario cod_usuario, NULL usuario");

        $q[] = DB::table('tb_fisca_seguimiento_intimacion as fi')
            ->leftJoin('tb_empresa as e', 'e.id_empresa', '=', 'fi.id_empresa')
            ->whereBetween('fi.fecha_inicio_gestion', [$d0, $d1])
            ->selectRaw("CONCAT('fisi-', fi.id_intimacion) id, 'fis' tipo, fi.fecha_inicio_gestion fecha, 'Intimación' titulo,
                IFNULL(e.razon_social, 'Empresa') detalle, fi.total_deuda_ospf monto, fi.id_usuario cod_usuario, NULL usuario");

        $union = array_shift($q);
        foreach ($q as $sub) {
            $union->unionAll($sub);
        }

        return $union;
    }

    /** Cantidad y monto por tipo de operación entre dos fechas. */
    public function getConteos(string $desde, string $hasta): array
    {
        $filas = DB::query()->fromSub($this->queryEventos($desde, $hasta), 'e')
            ->groupBy('tipo')
            ->get(['tipo', DB::raw('COUNT(*) as cantidad'), DB::raw('SUM(monto) as monto')])
            ->keyBy('tipo');

        return collect(self::TIPOS)->map(fn ($t) => [
            'id' => $t,
            'cantidad' => (int) ($filas[$t]->cantidad ?? 0),
            'monto' => round((float) ($filas[$t]->monto ?? 0), 2),
        ])->all();
    }

    /** Operaciones de un día por tipo y hora (08 a 19 h): [tipo => [12 cantidades]]. */
    public function getPorHora(string $fecha): array
    {
        $filas = DB::query()->fromSub($this->queryEventos($fecha, $fecha), 'e')
            ->whereRaw('HOUR(fecha) BETWEEN 8 AND 19')
            ->groupBy('tipo', DB::raw('HOUR(fecha)'))
            ->get(['tipo', DB::raw('HOUR(fecha) as hora'), DB::raw('COUNT(*) as cantidad')]);

        $mapa = array_fill_keys(self::TIPOS, array_fill(0, 12, 0));
        foreach ($filas as $f) {
            $mapa[$f->tipo][(int) $f->hora - 8] = (int) $f->cantidad;
        }

        return $mapa;
    }

    /** Últimas operaciones (de los últimos 14 días) con el nombre del usuario. */
    public function getFeed(string $hasta, int $limite): array
    {
        $desde = date('Y-m-d', strtotime($hasta . ' -14 days'));

        return DB::query()->fromSub($this->queryEventos($desde, $hasta), 'e')
            ->leftJoin('tb_usuarios as u', 'u.cod_usuario', '=', 'e.cod_usuario')
            ->orderByDesc('e.fecha')
            ->orderByDesc('e.id')
            ->limit($limite)
            ->get(['e.id', 'e.tipo', 'e.fecha', 'e.titulo', 'e.detalle', 'e.monto',
                DB::raw("COALESCE(e.usuario, u.nombre_apellidos, u.email, 'sistema') as usuario")])
            ->map(fn ($e) => [
                'id' => $e->id,
                'tipo' => $e->tipo,
                'fecha' => $e->fecha,
                'titulo' => $e->titulo,
                'detalle' => $e->detalle,
                'monto' => $e->monto === null ? null : round((float) $e->monto, 2),
                'usuario' => $e->usuario,
            ])
            ->all();
    }

    /** Usuarios con más operaciones en el período y su perfil. */
    public function getUsuarios(string $desde, string $hasta, int $limite): array
    {
        $operaciones = DB::query()->fromSub($this->queryEventos($desde, $hasta), 'e')
            ->leftJoin('tb_usuarios as u', 'u.cod_usuario', '=', 'e.cod_usuario')
            ->leftJoin('tb_perfiles as pf', 'pf.cod_perfil', '=', 'u.cod_perfil')
            ->where(fn ($w) => $w->whereNotNull('e.cod_usuario')->orWhereNotNull('e.usuario'))
            ->select([
                DB::raw("COALESCE(e.usuario, u.nombre_apellidos, u.email, e.cod_usuario) as usuario"),
                DB::raw("IFNULL(pf.nombre_perfil, 'Fiscalización') as area"),
            ]);

        return DB::query()->fromSub($operaciones, 'o')
            ->groupBy('usuario')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit($limite)
            ->get(['usuario', DB::raw('MAX(area) as area'), DB::raw('COUNT(*) as cantidad')])
            ->map(fn ($u) => ['usuario' => $u->usuario, 'area' => $u->area, 'cantidad' => (int) $u->cantidad])
            ->all();
    }

    // ================================================================== padrón

    /** Afiliados activos (tb_padron.activo, lo mantiene el evento desactivar_padron_por_fecha). */
    public function getPadronActivo(): int
    {
        return (int) DB::table('tb_padron')
            ->where('activo', 1)
            ->distinct()
            ->count('dni');
    }

    /** Altas y bajas por día entre dos fechas: [fecha => [altas, bajas]]. */
    public function getAltasBajasPorDia(string $desde, string $hasta): array
    {
        $altas = DB::table('tb_padron')
            ->whereBetween('fe_alta', [$desde, $hasta])
            ->select([DB::raw('fe_alta as fecha'), DB::raw('1 as alta'), DB::raw('0 as baja')]);

        $bajas = DB::table('tb_padron')
            ->whereBetween('fe_baja', [$desde, $hasta])
            ->select([DB::raw('fe_baja as fecha'), DB::raw('0 as alta'), DB::raw('1 as baja')]);

        return DB::query()->fromSub($altas->unionAll($bajas), 'x')
            ->groupBy('fecha')
            ->get(['fecha', DB::raw('SUM(alta) as altas'), DB::raw('SUM(baja) as bajas')])
            ->mapWithKeys(fn ($f) => [$f->fecha => ['altas' => (int) $f->altas, 'bajas' => (int) $f->bajas]])
            ->all();
    }

    /** Motivos de baja del período (tb_bajas_motivos), de mayor a menor. */
    public function getMotivosBaja(string $desde, string $hasta, int $limite): array
    {
        return DB::table('tb_padron as p')
            ->leftJoin('tb_bajas_motivos as bm', 'bm.id_baja_motivos', '=', 'p.id_baja_motivos')
            ->whereBetween('p.fe_baja', [$desde, $hasta])
            ->groupBy(DB::raw("IFNULL(TRIM(bm.motivo_baja), 'SIN MOTIVO CARGADO')"))
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit($limite)
            ->get([DB::raw("IFNULL(TRIM(bm.motivo_baja), 'SIN MOTIVO CARGADO') as motivo"), DB::raw('COUNT(*) as cantidad')])
            // "BAJA POR FALTA DE APORTES" → "FALTA DE APORTES": entra en la columna del panel
            ->map(fn ($m) => ['motivo' => preg_replace('/^BAJA POR /', '', $m->motivo), 'cantidad' => (int) $m->cantidad])
            ->all();
    }

    /**
     * Campos más modificados del padrón en el período, comparando el antes y el ahora (JSON)
     * de tb_auditoria_padron.
     */
    public function getTiposModificacion(string $desde, string $hasta, int $limite): array
    {
        $registros = DB::table('tb_auditoria_padron')
            ->whereBetween('fecha', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->get(['antes', 'ahora']);

        $conteo = [];
        foreach ($registros as $r) {
            $antes = json_decode($r->antes, true);
            $ahora = json_decode($r->ahora, true);
            if (!is_array($antes) || !is_array($ahora)) {
                continue;
            }
            $antes = self::aplanar($antes);
            $ahora = self::aplanar($ahora);
            foreach (array_unique(array_merge(array_keys($antes), array_keys($ahora))) as $campo) {
                if (in_array($campo, self::CAMPOS_EXCLUIDOS, true)
                    || self::normalizar($antes[$campo] ?? null) === self::normalizar($ahora[$campo] ?? null)) {
                    continue;
                }
                $etiqueta = self::ETIQUETAS[$campo] ?? $campo;
                $conteo[$etiqueta] = ($conteo[$etiqueta] ?? 0) + 1;
            }
        }
        arsort($conteo);

        return collect(array_slice($conteo, 0, $limite, true))
            ->map(fn ($cantidad, $campo) => ['campo' => $campo, 'cantidad' => $cantidad])
            ->values()
            ->all();
    }

    /** El historial guarda un objeto {campo: valor} o una lista de objetos [{campo: valor}, ...]. */
    private static function aplanar(array $datos): array
    {
        $valores = [];
        foreach ($datos as $clave => $valor) {
            if (is_int($clave) && is_array($valor)) {
                $valores = array_merge($valores, $valor);
            } elseif (is_string($clave)) {
                $valores[$clave] = $valor;
            }
        }

        return $valores;
    }

    private static function normalizar($valor): ?string
    {
        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }
        if ($valor === null || is_array($valor)) {
            return $valor === null ? null : json_encode($valor);
        }
        $valor = trim((string) $valor);
        if ($valor === '' || strtolower($valor) === 'null' || $valor === 'undefined') {
            return null;
        }
        // "2026-05-07 00:00:00" y "2026-05-07" son la misma fecha
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T]00:00:00(\.0+)?Z?$/', $valor, $m)) {
            $valor = $m[1];
        }

        return in_array($valor, self::FECHAS_VACIAS, true) ? null : $valor;
    }

    // ================================================================== circuito, asientos

    /** Circuito documental del período: cantidad y monto de cada etapa. */
    public function getCircuitoDocumental(string $desde, string $hasta): array
    {
        $auditada = implode(',', FinanzasRepository::FACTURA_AUDITADA);

        $mesa = DB::table('tb_mesa_entrada')
            ->whereBetween('fecha_carga', [$desde, $hasta])
            ->selectRaw('COUNT(*) c, SUM(importe) m')->first();

        $facturas = DB::table('tb_facturacion_datos as f')
            ->where('f.estado', '<>', FinanzasRepository::FACTURA_ANULADA)
            ->whereBetween('f.fecha_registra', [$desde, $hasta])
            ->selectRaw("COUNT(*) c, SUM(f.total_neto) m,
                SUM(f.estado IN ($auditada)) ca,
                SUM(IF(f.estado IN ($auditada), f.total_neto, 0)) ma")
            ->first();

        $facturasLiquidadas = DB::table('tb_liquidaciones')
            ->whereBetween('fecha_registra', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->distinct()
            ->select('id_factura');
        $liquidadas = DB::query()->fromSub($facturasLiquidadas, 'l')
            ->leftJoin('tb_facturacion_datos as f', 'f.id_factura', '=', 'l.id_factura')
            ->selectRaw('COUNT(*) c, SUM(' . self::MONTO_LIQUIDADO . ') m')->first();

        $opas = DB::table('tb_tes_orden_pago')
            ->whereNotIn('id_estado_orden_pago', self::OPA_NO_VIGENTE)
            ->whereBetween('fecha_emision', [$desde, $hasta])
            ->selectRaw('COUNT(*) c, SUM(monto_orden_pago) m')->first();

        $pagos = DB::table('tb_tes_movimiento_cuenta_bancaria')
            ->where('tipo_movimiento', 'EGRESO')
            ->whereNotNull('id_pago')
            ->whereBetween('fecha_movimiento', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->selectRaw('COUNT(DISTINCT id_pago) c, SUM(monto) m')->first();

        $etapa = fn ($nombre, $c, $m) => ['etapa' => $nombre, 'cantidad' => (int) $c, 'monto' => round((float) $m, 2)];

        return [
            $etapa('Facturación ingresada', $mesa->c, $mesa->m),
            $etapa('Facturas cargadas', $facturas->c, $facturas->m),
            $etapa('Auditadas', $facturas->ca, $facturas->ma),
            $etapa('Liquidadas', $liquidadas->c, $liquidadas->m),
            $etapa('OP generadas', $opas->c, $opas->m),
            $etapa('Pagadas', $pagos->c, $pagos->m),
        ];
    }

    /** Asientos vigentes del período por origen, con el total del Debe y los manuales. */
    public function getAsientos(string $desde, string $hasta): array
    {
        $base = DB::table('tb_cont_asientos_contables')
            ->where('vigente', 'ACTIVO')
            ->whereBetween('fecha_registra', [$desde . ' 00:00:00', $hasta . ' 23:59:59']);

        $origen = "CASE
            WHEN asiento_modelo IN ('PAGO', 'MOVIMIENTO ENTRE CUENTAS') THEN 'Tesorería (pagos, movimientos)'
            WHEN asiento_modelo = 'FACTURA' THEN 'Facturación (devengamiento)'
            WHEN asiento_modelo = 'DISCAPACIDAD' THEN 'Discapacidad'
            WHEN asiento_modelo = 'ASIENTO MANUAL' OR id_tipo_asiento = 4 THEN 'Manuales'
            WHEN asiento_modelo LIKE 'CONTRAASIENTO%' THEN 'Contraasientos'
            ELSE 'Otros automáticos' END";

        $porOrigen = DB::query()->fromSub((clone $base)->select(DB::raw("$origen as origen")), 'x')
            ->groupBy('origen')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get(['origen', DB::raw('COUNT(*) as cantidad')]);

        $debe = DB::table('tb_cont_asientos_contables_detalle as d')
            ->joinSub((clone $base)->select('id_asiento_contable'), 'a', 'a.id_asiento_contable', '=', 'd.id_asiento_contable')
            ->selectRaw('SUM(d.monto_debe) debe, SUM(d.monto_haber) haber')
            ->first();

        return [
            'total' => (int) $porOrigen->sum('cantidad'),
            'por_origen' => $porOrigen->map(fn ($o) => ['origen' => $o->origen, 'cantidad' => (int) $o->cantidad])->all(),
            'debe' => round((float) ($debe->debe ?? 0), 2),
            'haber' => round((float) ($debe->haber ?? 0), 2),
            'manuales' => (int) ($porOrigen->firstWhere('origen', 'Manuales')->cantidad ?? 0),
        ];
    }

    /** Últimas liquidaciones de prestadores (por factura). */
    public function getUltimasLiquidaciones(string $hasta, int $limite): array
    {
        $liquidado = self::MONTO_LIQUIDADO;

        return DB::table('tb_liquidaciones as l')
            ->leftJoin('tb_facturacion_datos as f', 'f.id_factura', '=', 'l.id_factura')
            ->leftJoin('tb_prestador as pr', 'pr.cod_prestador', '=', 'f.id_prestador')
            ->where('l.fecha_registra', '<=', $hasta . ' 23:59:59')
            ->where('l.fecha_registra', '>=', date('Y-m-d', strtotime($hasta . ' -60 days')))
            ->groupBy('l.id_factura')
            ->orderByDesc(DB::raw('MAX(l.fecha_registra)'))
            ->limit($limite)
            ->get([
                DB::raw('MAX(l.fecha_registra) as fecha'),
                DB::raw("MAX(IFNULL(pr.razon_social, CONCAT('Factura ', l.id_factura))) as nombre"),
                DB::raw("CONCAT(IFNULL(CONCAT('período ', MAX(f.periodo), ' · '), ''), COUNT(*), ' prestaciones') as detalle"),
                DB::raw("MAX($liquidado) as monto"),
                DB::raw("IF(MAX(IFNULL(f.total_debitado_liquidacion, 0)) > 0, 'Con débitos', 'Aprobada') as estado"),
            ])
            ->map(fn ($l) => [
                'nombre' => $l->nombre,
                'detalle' => $l->detalle,
                'monto' => round((float) $l->monto, 2),
                'estado' => $l->estado,
                'fecha' => $l->fecha,
            ])
            ->all();
    }
}
