<?php
// Cada filtro de cada pantalla del circuito, por la API real (rutas + middleware + controlador),
// con los parámetros armados como los arma el front (campos vacíos = ''). Para cada filtro:
// trae algo, y TODAS las filas cumplen la condición. (2026-10-06)
require_once __DIR__ . '/_http.php';
use Illuminate\Support\Facades\DB;

$r = [];
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";
$caso = function (string $nombre, bool $cumple, string $det = '') use (&$r, $ok) {
    echo "--- {$nombre}" . ($det ? " — {$det}" : '') . "\n";
    $r[] = $cumple;
    echo $ok($cumple);
};
$todas = fn(array $filas, callable $f) => count($filas) > 0 && count(array_filter($filas, $f)) === count($filas);
// La base compara sin acentos ("MÉDICOS" coincide con "MEDIC"): el test también.
$sinAcento = fn($t) => strtolower(\Illuminate\Support\Str::ascii((string) $t));
$contiene = fn($texto, $buscado) => str_contains($sinAcento($texto), $sinAcento($buscado));

// ================================================================ Gestor OPAs
$opaBase = ['estado' => '', 'desde' => '2020-01-01', 'hasta' => '2030-12-31', 'beneficiario' => '', 'tipo' => '',
    'id_locatorio' => '', 'num_orden_pago' => '', 'n_factura' => '', 'id_tipo_imputacion' => '', 'pago_urgente' => '',
    'monto_desde' => '', 'monto_hasta' => ''];
$opa = fn(array $p) => filas(apiGet('/api/v1/tesoreria/consultar-opa', array_merge($opaBase, $p))[1]);

$muestra = DB::selectOne("SELECT o.id_orden_pago, o.num_orden_pago, o.id_estado_orden_pago, p.cuit, p.razon_social,
        f.numero, f.id_locatorio, DATE(o.fecha_genera) fecha
    FROM tb_tes_orden_pago o JOIN tb_prestador p ON p.cod_prestador = o.id_prestador
    JOIN tb_tes_orden_pago_detalle d ON d.id_orden_pago = o.id_orden_pago
    JOIN tb_facturacion_datos f ON f.id_factura = d.id_factura
    WHERE o.id_estado_orden_pago <> 3 ORDER BY o.id_orden_pago DESC LIMIT 1");

$f = $opa(['estado' => (string) $muestra->id_estado_orden_pago]);
$caso('OPA estado', $todas($f, fn($x) => $x['id_estado_orden_pago'] == $muestra->id_estado_orden_pago), count($f) . ' filas');

$f = $opa(['beneficiario' => $muestra->cuit]);
$caso('OPA beneficiario por CUIT', $todas($f, fn($x) => ($x['prestador']['cuit'] ?? $x['proveedor']['cuit'] ?? '') == $muestra->cuit), count($f) . ' filas');

$pedazo = trim(mb_substr($muestra->razon_social, 3, 6));
$f = $opa(['beneficiario' => $pedazo]);
$caso("OPA beneficiario por parte del nombre (\"{$pedazo}\")", $todas($f, fn($x) => $contiene(($x['prestador']['razon_social'] ?? '') . ' ' . ($x['proveedor']['razon_social'] ?? ''), $pedazo)), count($f) . ' filas');

$f = $opa(['tipo' => 'PRESTADOR']);
$caso('OPA tipo PRESTADOR', $todas($f, fn($x) => $x['tipo_factura'] === 'PRESTADOR'), count($f) . ' filas');

$razonDe = function ($x) {
    $rs = array_unique(array_filter(array_map(fn($d) => $d['detallefc']['id_locatorio'] ?? null, $x['opadetalle'] ?? [])));
    return $rs ?: ($x['id_razon'] ? [$x['id_razon']] : []);
};
$rz = (int) $muestra->id_locatorio;
$f = $opa(['id_locatorio' => (string) $rz]);
$caso("OPA razón social ({$rz})", $todas($f, fn($x) => in_array($rz, $razonDe($x))), count($f) . ' filas');
$antRazon = DB::table('tb_tes_orden_pago')->where('tipo_opa', 'ANTICIPO')->where('id_razon', $rz)->where('id_estado_orden_pago', '<>', 3)->value('id_orden_pago');
if ($antRazon) {
    $caso('OPA razón social incluye los ANTICIPOS de esa razón', in_array($antRazon, array_column($f, 'id_orden_pago')), "anticipo {$antRazon}");
}

$cons = substr($muestra->num_orden_pago, strrpos($muestra->num_orden_pago, '-') + 1);
$f = $opa(['num_orden_pago' => $cons]);
$caso("OPA N° de orden (\"{$cons}\")", $todas($f, fn($x) => (int) substr($x['num_orden_pago'], strrpos($x['num_orden_pago'], '-') + 1) === (int) $cons), count($f) . ' filas');

$f = $opa(['n_factura' => $muestra->numero]);
$caso("OPA N° factura (\"{$muestra->numero}\")", $todas($f, fn($x) => in_array($muestra->numero, array_map(fn($d) => $d['detallefc']['numero'] ?? null, $x['opadetalle'] ?? []))), count($f) . ' filas');

$f = $opa(['desde' => $muestra->fecha, 'hasta' => $muestra->fecha]);
$caso("OPA rango de fechas ({$muestra->fecha})", $todas($f, fn($x) => substr($x['fecha_genera'], 0, 10) === $muestra->fecha), count($f) . ' filas');

$f = $opa(['desde' => $muestra->fecha, 'hasta' => '']);
$caso('OPA solo "desde" (sin "hasta") también filtra', $todas($f, fn($x) => substr($x['fecha_genera'], 0, 10) >= $muestra->fecha), count($f) . ' filas');

$vieja = DB::table('tb_tes_orden_pago')->where('fecha_genera', '<', '2026-01-01')->where('id_estado_orden_pago', '<>', 3)->orderBy('id_orden_pago')->first();
if ($vieja) {
    $cv = substr($vieja->num_orden_pago, strrpos($vieja->num_orden_pago, '-') + 1);
    $f = $opa(['num_orden_pago' => $cv, 'desde' => date('Y-m-d', strtotime('-20 days')), 'hasta' => date('Y-m-d')]);
    $caso("OPA N° de orden encuentra una orden VIEJA aunque el rango de fechas de la pantalla no la incluya ({$vieja->num_orden_pago})",
        in_array($vieja->id_orden_pago, array_column($f, 'id_orden_pago')), count($f) . ' filas');
}

// ================================================================ Pago Prestador
$pagBase = ['estado' => '', 'desde' => '2020-01-01', 'hasta' => '2030-12-31', 'beneficiario' => '', 'monto_desde' => '',
    'monto_hasta' => '', 'tipo' => 'PRESTADOR', 'pago_urgente' => '', 'id_locatario' => '', 'id_tipo_imputacion' => '',
    'numero' => '', 'numero_opa' => ''];
$pag = fn(array $p) => filas(apiGet('/api/v1/tesoreria/consultar-pagos', array_merge($pagBase, $p))[1]);

$m = DB::selectOne("SELECT b.id_pago, b.id_estado_orden_pago, o.num_orden_pago, p.cuit, p.razon_social, f.numero
    FROM tb_tes_pago b JOIN tb_tes_orden_pago o ON o.id_orden_pago = b.id_orden_pago
    JOIN tb_prestador p ON p.cod_prestador = o.id_prestador
    JOIN tb_tes_orden_pago_detalle d ON d.id_orden_pago = o.id_orden_pago
    JOIN tb_facturacion_datos f ON f.id_factura = d.id_factura
    JOIN tb_tes_fecha_probable_pago fp ON fp.id_pago = b.id_pago
    WHERE b.tipo_factura = 'PRESTADOR' ORDER BY b.id_pago DESC LIMIT 1");

$f = $pag(['estado' => (string) $m->id_estado_orden_pago]);
$caso('PAGO estado', $todas($f, fn($x) => $x['id_estado_orden_pago'] == $m->id_estado_orden_pago), count($f) . ' filas');

$f = $pag(['beneficiario' => $m->cuit]);
$caso('PAGO beneficiario por CUIT', $todas($f, fn($x) => ($x['opa']['prestador']['cuit'] ?? '') == $m->cuit), count($f) . ' filas');

$f = $pag(['beneficiario' => mb_substr($m->razon_social, 0, 8)]);
$caso('PAGO beneficiario por nombre', $todas($f, fn($x) => stripos($x['opa']['prestador']['razon_social'] ?? '', mb_substr($m->razon_social, 0, 8)) !== false), count($f) . ' filas');

$cons = substr($m->num_orden_pago, strrpos($m->num_orden_pago, '-') + 1);
$f = $pag(['numero_opa' => $cons]);
$caso("PAGO N° OPA (\"{$cons}\")", $todas($f, fn($x) => (int) substr($x['opa']['num_orden_pago'], strrpos($x['opa']['num_orden_pago'], '-') + 1) === (int) $cons), count($f) . ' filas');

$f = $pag(['numero' => $m->numero]);
$caso("PAGO N° factura (\"{$m->numero}\")", $todas($f, fn($x) => in_array($m->numero, array_map(fn($d) => $d['detallefc']['numero'] ?? null, $x['detalleopa'] ?? []))), count($f) . ' filas');

$f = $pag(['id_locatario' => (string) $rz]);
$caso("PAGO razón social ({$rz})", $todas($f, function ($x) use ($rz) {
    $rs = array_filter(array_map(fn($d) => $d['detallefc']['id_locatorio'] ?? null, $x['detalleopa'] ?? []));
    return in_array($rz, $rs) || (($x['opa']['id_razon'] ?? null) == $rz);
}), count($f) . ' filas');
$bolAnt = DB::table('tb_tes_pago as b')->join('tb_tes_orden_pago as o', 'o.id_orden_pago', '=', 'b.id_orden_pago')
    ->where('o.tipo_opa', 'ANTICIPO')->where('o.id_razon', $rz)->where('b.tipo_factura', 'PRESTADOR')->value('b.id_pago');
if ($bolAnt) {
    $caso('PAGO razón social incluye las boletas de ANTICIPOS de esa razón', in_array($bolAnt, array_column($f, 'id_pago')), "boleta {$bolAnt}");
}

$vb = DB::table('tb_tes_pago as b')->join('tb_tes_orden_pago as o', 'o.id_orden_pago', '=', 'b.id_orden_pago')
    ->join('tb_tes_fecha_probable_pago as fp', 'fp.id_pago', '=', 'b.id_pago')
    ->where('b.tipo_factura', 'PRESTADOR')->where('fp.fecha_probable_pago', '<', '2026-06-01')
    ->select('b.id_pago', 'o.num_orden_pago')->first();
if ($vb) {
    $cv = substr($vb->num_orden_pago, strrpos($vb->num_orden_pago, '-') + 1);
    $f = $pag(['numero_opa' => $cv, 'desde' => date('Y-m-d', strtotime('-3 weeks')), 'hasta' => date('Y-m-d', strtotime('+1 month'))]);
    $caso("PAGO N° OPA encuentra una boleta VIEJA aunque el rango de fechas de la pantalla no la incluya ({$vb->num_orden_pago})",
        in_array($vb->id_pago, array_column($f, 'id_pago')), count($f) . ' filas');
}

// ================================================================ Pago Proveedor
$prov = filas(apiGet('/api/v1/tesoreria/consultar-pagos', array_merge($pagBase, ['tipo' => 'PROVEEDOR']))[1]);
$caso('PAGO PROVEEDOR solo trae boletas de proveedor', $todas($prov, fn($x) => $x['tipo_factura'] === 'PROVEEDOR') || count($prov) === 0, count($prov) . ' filas');

// ================================================================ Generar OPA
$fpo = fn(array $p) => filas(apiGet('/api/v1/tesoreria/facturas-para-opa', array_merge(['tipo' => 'PRESTADOR', 'page' => 1, 'per_page' => 200], $p))[1]);
$fm = $fpo([]);
if ($fm) {
    $x0 = $fm[0];
    $f = $fpo(['cuit' => $x0['cuit']]);
    $caso('GENERAR OPA por CUIT', $todas($f, fn($x) => $x['cuit'] == $x0['cuit']), count($f) . ' filas');
    $f = $fpo(['id_locatorio' => (string) $x0['id_locatorio']]);
    $caso('GENERAR OPA por razón social', $todas($f, fn($x) => $x['id_locatorio'] == $x0['id_locatorio']), count($f) . ' filas');
    $f = $fpo(['numero_factura' => $x0['numero']]);
    $caso('GENERAR OPA por N° factura', $todas($f, fn($x) => $x['numero'] == $x0['numero']), count($f) . ' filas');
    $f = $fpo(['periodo' => $x0['periodo']]);
    $caso('GENERAR OPA por período', $todas($f, fn($x) => $x['periodo'] == $x0['periodo']), count($f) . ' filas');
    $f = $fpo(['razon_social' => mb_substr($x0['razon_social'], 0, 6)]);
    $caso('GENERAR OPA por nombre del prestador', $todas($f, fn($x) => stripos($x['razon_social'] . $x['nombre_fantasia'], mb_substr($x0['razon_social'], 0, 6)) !== false), count($f) . ' filas');
    $caso('GENERAR OPA solo facturas con saldo', $todas($fm, fn($x) => $x['saldo_pendiente'] > 0), count($fm) . ' filas');
}

// ================================================================ Carga de eCheq
$em = fn(array $p) => filas(apiGet('/api/v1/tesoreria/instrumentos-pago/emitidos', $p)[1]);
$e0 = $em([]);
if ($e0) {
    $x0 = $e0[0];
    $cons = substr($x0['num_orden_pago'], strrpos($x0['num_orden_pago'], '-') + 1);
    $f = $em(['numero_opa' => $cons]);
    $caso("ECHEQ por N° OPA (\"{$cons}\")", $todas($f, fn($x) => (int) substr($x['num_orden_pago'], strrpos($x['num_orden_pago'], '-') + 1) === (int) $cons), count($f) . ' filas');
    $banco = $x0['id_banco_emisor'] ?? null;
    if ($banco) {
        $f = $em(['id_banco' => (string) $banco]);
        $caso('ECHEQ por banco', $todas($f, fn($x) => $x['id_banco_emisor'] == $banco), count($f) . ' filas');
    }
    $f = $em(['id_razon' => '2']);
    $caso('ECHEQ por razón social (sin errores)', is_array($f), count($f) . ' filas');
}

// ================================================================ Anticipos
$an = fn(array $p) => filas(apiGet('/api/v1/tesoreria/anticipos', $p)[1]);
$a0 = $an([]);
if ($a0) {
    $x0 = $a0[0];
    $f = $an(['texto' => $x0['cuit']]);
    $caso('ANTICIPOS por CUIT', $todas($f, fn($x) => $x['cuit'] == $x0['cuit']), count($f) . ' filas');
    $f = $an(['texto' => $x0['num_orden_pago']]);
    $caso('ANTICIPOS por N° de OPA', $todas($f, fn($x) => $x['num_orden_pago'] == $x0['num_orden_pago']), count($f) . ' filas');
    $f = $an(['texto' => mb_substr($x0['razon_social'], 2, 6)]);
    $caso('ANTICIPOS por parte del nombre', $todas($f, fn($x) => stripos($x['razon_social'], mb_substr($x0['razon_social'], 2, 6)) !== false), count($f) . ' filas');
    $f = $an(['solo_con_saldo' => '1']);
    $caso('ANTICIPOS solo con saldo', count($f) === 0 || $todas($f, fn($x) => $x['saldo'] > 0), count($f) . ' filas');
    $f = $an(['tipo_beneficiario' => 'PRESTADOR']);
    $caso('ANTICIPOS tipo PRESTADOR', $todas($f, fn($x) => $x['tipo_beneficiario'] === 'PRESTADOR'), count($f) . ' filas');
}

$c = count(array_filter($r));
echo "\n=== {$c}/" . count($r) . ' OK ' . ($c === count($r) ? '' : '<<< HAY FALLAS') . "\n";
