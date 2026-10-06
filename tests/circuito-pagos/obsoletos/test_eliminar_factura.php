<?php

use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::setUser(App\Models\User::find(2));
$repo = new TestOrdenPagoRepository();

// Simula lo que hace ahora deleteFacturaDetalle (endpoint "eliminar-factura")
$simularEliminar = function ($idFactura) use ($repo) {
    $opaVigente = $repo->findByOpaVigenteFactura($idFactura);
    if (is_null($opaVigente)) {
        return ['ok' => true, 'msg' => 'sin OPA vigente, se anula la factura directo'];
    }
    $r = $repo->findByAnularOpaDeFactura($idFactura, 'Anulación de la factura. Test');
    return ['ok' => $r['anulada'], 'msg' => $r['message']];
};

// A) factura con OPA individual sin pagos
DB::beginTransaction();
$a = DB::selectOne("
  SELECT d.id_factura, o.id_orden_pago, o.num_orden_pago
  FROM tb_tes_orden_pago o
  JOIN tb_tes_orden_pago_detalle d ON d.id_orden_pago = o.id_orden_pago
  WHERE o.id_estado_orden_pago <> 3
    AND (SELECT COUNT(*) FROM tb_tes_orden_pago_detalle x WHERE x.id_orden_pago=o.id_orden_pago)=1
    AND NOT EXISTS (SELECT 1 FROM tb_tes_pago p WHERE p.id_orden_pago=o.id_orden_pago AND p.id_estado_orden_pago<>3)
  LIMIT 1");
echo "A) Eliminar factura {$a->id_factura} (OPA individual {$a->num_orden_pago}, sin pagos)" . PHP_EOL;
$r = $simularEliminar($a->id_factura);
echo "   -> " . ($r['ok'] ? 'PERMITE' : 'BLOQUEA') . ": " . $r['msg'] . PHP_EOL;
echo "   estado OPA = " . DB::table('tb_tes_orden_pago')->where('id_orden_pago', $a->id_orden_pago)->value('id_estado_orden_pago') . " (3 = rechazada)" . PHP_EOL;
DB::rollBack();

// B) factura con OPA que TIENE pagos -> debe bloquear el borrado
DB::beginTransaction();
$b = DB::selectOne("
  SELECT o.id_factura, o.num_orden_pago, o.id_orden_pago
  FROM tb_tes_orden_pago o
  WHERE o.id_estado_orden_pago <> 3 AND o.id_factura IS NOT NULL
    AND EXISTS (SELECT 1 FROM tb_tes_pago p WHERE p.id_orden_pago=o.id_orden_pago AND p.id_estado_orden_pago<>3)
  LIMIT 1");
echo PHP_EOL . "B) Eliminar factura {$b->id_factura} (OPA {$b->num_orden_pago} CON pagos)" . PHP_EOL;
$r = $simularEliminar($b->id_factura);
echo "   -> " . ($r['ok'] ? 'PERMITE (MAL!)' : 'BLOQUEA (correcto)') . ": " . $r['msg'] . PHP_EOL;
echo "   estado OPA sigue = " . DB::table('tb_tes_orden_pago')->where('id_orden_pago', $b->id_orden_pago)->value('id_estado_orden_pago') . PHP_EOL;
DB::rollBack();

// C) factura dentro de OPA agrupada
DB::beginTransaction();
$c = DB::selectOne("
  SELECT d.id_factura, o.id_orden_pago, o.num_orden_pago, o.monto_orden_pago,
         (SELECT COUNT(*) FROM tb_tes_orden_pago_detalle x WHERE x.id_orden_pago=o.id_orden_pago) facturas
  FROM tb_tes_orden_pago o
  JOIN tb_tes_orden_pago_detalle d ON d.id_orden_pago = o.id_orden_pago
  WHERE o.id_estado_orden_pago <> 3
    AND (SELECT COUNT(*) FROM tb_tes_orden_pago_detalle x WHERE x.id_orden_pago=o.id_orden_pago) > 1
    AND NOT EXISTS (SELECT 1 FROM tb_tes_pago p WHERE p.id_orden_pago=o.id_orden_pago AND p.id_estado_orden_pago<>3)
  LIMIT 1");
echo PHP_EOL . "C) Eliminar factura {$c->id_factura} (dentro de OPA agrupada {$c->num_orden_pago}, {$c->facturas} facturas, \${$c->monto_orden_pago})" . PHP_EOL;
$r = $simularEliminar($c->id_factura);
$rest = DB::table('tb_tes_orden_pago_detalle')->where('id_orden_pago', $c->id_orden_pago)->count();
$montoRest = DB::table('tb_tes_orden_pago')->where('id_orden_pago', $c->id_orden_pago)->value('monto_orden_pago');
echo "   -> " . ($r['ok'] ? 'PERMITE' : 'BLOQUEA') . ": " . $r['msg'] . PHP_EOL;
echo "   OPA original quedo con {$rest} facturas (antes {$c->facturas}) y monto \${$montoRest}" . PHP_EOL;
DB::rollBack();

echo PHP_EOL . ">> ROLLBACK aplicado - base intacta" . PHP_EOL;
