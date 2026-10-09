<?php
// OPAs automáticas (2026-10-09): listado del modal, marca en Generar OPA, anulación de a una y en lote,
// y que NO se anulen las que tienen boleta o son posteriores al corte. Todo en transacción + rollback.
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository as R;
use App\Http\Controllers\Tesoreria\Repository\FacturasOpaRepository as F;
$u = App\Models\User::query()->first(); Auth::setUser($u);
$ok=0;$ko=0; $chk=function($c,$m)use(&$ok,&$ko){ echo ($c?">>> OK  ":">>> FALLA ").' '.$m."\n"; $c?$ok++:$ko++; };
DB::beginTransaction();
try {
  $r = new R;
  $l = $r->listarOpasAutomaticas((object)['per_page'=>100]);
  echo "total automaticas: {$l['total']}, pagina: ".count($l['data'])."\n";
  $chk(count($l['data'])<=100, 'pagina de 100');
  $p = $r->listarOpasAutomaticas((object)['tipo'=>'PRESTADOR','buscar'=>'20160256193']);
  echo "DI BUONO: {$p['total']}\n";
  // una que NO es automatica (con boleta) debe rechazarse
  $conBoleta = DB::table('tb_tes_orden_pago as o')->where('id_estado_orden_pago',1)->whereExists(fn($q)=>$q->select(DB::raw(1))->from('tb_tes_pago as p')->whereColumn('p.id_orden_pago','o.id_orden_pago'))->value('id_orden_pago');
  if ($conBoleta) { $x=$r->anularOpasAutomaticas((object)['ids'=>[$conBoleta]]); $chk($x['anuladas']==0 && count($x['errores'])==1, "con boleta no se anula ({$conBoleta})"); }
  $nueva = DB::table('tb_tes_orden_pago')->where('fecha_genera','>=',R::CORTE_OPAS_AUTOMATICAS)->value('id_orden_pago');
  if ($nueva) { $x=$r->anularOpasAutomaticas((object)['ids'=>[$nueva]]); $chk($x['anuladas']==0, "posterior al corte no se anula"); }
  // factura trabada aparece en Generar OPA con su opa automatica
  $op = $p['data'][0] ?? $l['data'][0];
  $idf = DB::table('tb_tes_orden_pago_detalle')->where('id_orden_pago',$op->id_orden_pago)->value('id_factura');
  $fac = DB::table('tb_facturacion_datos')->where('id_factura',$idf)->first();
  $tipo = $op->tipo_factura;
  $gen = (new F)->listarFacturasParaOpa((object)['tipo'=>$tipo,'id_prestador'=>$tipo=='PROVEEDOR'?$fac->id_proveedor:$fac->id_prestador,'per_page'=>500]);
  $row = collect($gen['data'])->firstWhere('id_factura',$idf);
  $chk($row && $row->id_opa_automatica==$op->id_orden_pago && $row->num_opa_automatica, "factura $idf visible en Generar OPA con {$op->num_orden_pago}");
  // anular una
  $x=$r->anularOpasAutomaticas((object)['ids'=>[$op->id_orden_pago]]);
  $o=DB::table('tb_tes_orden_pago')->where('id_orden_pago',$op->id_orden_pago)->first();
  $chk($x['anuladas']==1 && $o->id_estado_orden_pago==3 && $o->cod_usuario_rechaza==$u->cod_usuario && str_contains($o->motivo_rechazo,'automática'), 'anulada con motivo y usuario');
  $gen = (new F)->listarFacturasParaOpa((object)['tipo'=>$tipo,'id_prestador'=>$tipo=='PROVEEDOR'?$fac->id_proveedor:$fac->id_prestador,'per_page'=>500]);
  $row = collect($gen['data'])->firstWhere('id_factura',$idf);
  $chk($row && !$row->id_opa_automatica && $row->saldo_pendiente>0, 'factura liberada con saldo '.($row->saldo_pendiente??'-'));
  // todas por filtro
  $antes=$r->listarOpasAutomaticas((object)['buscar'=>'20160256193'])['total'];
  $t0=microtime(true); $x=$r->anularOpasAutomaticas((object)['todas'=>true,'buscar'=>'20160256193']);
  $chk($x['anuladas']==$antes && $r->listarOpasAutomaticas((object)['buscar'=>'20160256193'])['total']==0, "todas por filtro: {$x['anuladas']} en ".round(microtime(true)-$t0,2).'s');
} catch (\Throwable $e) { echo 'EXC '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n"; $ko++; }
DB::rollBack();
echo "== $ok OK / $ko FALLAS\n";
