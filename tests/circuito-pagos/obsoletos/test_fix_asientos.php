<?php

use Illuminate\Support\Facades\DB;

$resumen = function (string $titulo) {
    echo $titulo . PHP_EOL;
    $rows = DB::select("
        SELECT a.asiento_modelo,
               d.id_detalle_plan,
               CASE WHEN d.monto_debe > 0 THEN 'DEBE' ELSE 'HABER' END AS lado,
               COUNT(*) AS n
        FROM tb_cont_asientos_contables_detalle d
        JOIN tb_cont_asientos_contables a ON a.id_asiento_contable = d.id_asiento_contable
        WHERE d.id_detalle_plan IN (35, 36, 1231, 1246)
        GROUP BY a.asiento_modelo, d.id_detalle_plan, lado
        ORDER BY a.asiento_modelo, d.id_detalle_plan
    ");
    foreach ($rows as $r) {
        echo '  ' . str_pad($r->asiento_modelo, 26)
            . 'plan=' . str_pad($r->id_detalle_plan, 6)
            . str_pad($r->lado, 7) . $r->n . ' filas' . PHP_EOL;
    }
    echo PHP_EOL;
};

$update = "
UPDATE tb_cont_asientos_contables_detalle d
JOIN tb_cont_asientos_contables a ON a.id_asiento_contable = d.id_asiento_contable
JOIN tb_cont_razon_cuenta_contable r
     ON r.id_razon = a.id_razon
    AND r.vigente = 1
    AND r.tipo_contraparte = CASE d.id_detalle_plan WHEN 35 THEN 'PRESTADOR' WHEN 36 THEN 'PROVEEDOR' END
SET d.id_detalle_plan = r.id_detalle_plan
WHERE d.id_detalle_plan IN (35, 36)
  AND ( (a.asiento_modelo = 'FACTURA'                 AND d.monto_haber > 0)
     OR (a.asiento_modelo = 'CONTRAASIENTO - FACTURA' AND d.monto_debe  > 0)
     OR (a.asiento_modelo = 'PAGO'                    AND d.monto_debe  > 0)
     OR (a.asiento_modelo = 'CONTRAASIENTO - PAGO'    AND d.monto_haber > 0) )";

DB::beginTransaction();
try {
    $resumen('ANTES:');
    $n = DB::update($update);
    echo '>> Filas actualizadas: ' . $n . PHP_EOL . PHP_EOL;
    $resumen('DESPUES:');

    // Idempotencia: correrlo de nuevo no debe tocar nada
    $n2 = DB::update($update);
    echo '>> Segunda corrida (debe ser 0): ' . $n2 . PHP_EOL . PHP_EOL;

    // Control: que ningun asiento haya quedado descuadrado
    $desc = DB::select("
        SELECT COUNT(*) AS n FROM (
            SELECT d.id_asiento_contable
            FROM tb_cont_asientos_contables_detalle d
            GROUP BY d.id_asiento_contable
            HAVING ABS(SUM(d.monto_debe) - SUM(d.monto_haber)) > 0.01
        ) x");
    echo '>> Asientos descuadrados (debe/haber): ' . $desc[0]->n . PHP_EOL;

    DB::rollBack();
    echo PHP_EOL . 'ROLLBACK aplicado - la base quedo intacta' . PHP_EOL;
} catch (\Throwable $e) {
    DB::rollBack();
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
}
