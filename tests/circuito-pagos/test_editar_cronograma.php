<?php
// Edicion del cronograma de una OPA ya creada (Gestor OPAs). Corre con rollback.
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use Illuminate\Support\Facades\DB;

$repo = app(TestOrdenPagoRepository::class);
$ok = 0; $fail = 0;
$check = function ($nombre, $cond, $detalle = '') use (&$ok, &$fail) {
    if ($cond) { $ok++; echo "  OK   $nombre\n"; }
    else { $fail++; echo "  FALLA $nombre  $detalle\n"; }
};

DB::beginTransaction();
try {
    // Una boleta viva SIN abonos: es el caso editable.
    $boleta = DB::table('tb_tes_pago as p')
        ->leftJoin('tb_tes_pago_parcial as pp', 'pp.id_pago', '=', 'p.id_pago')
        ->where('p.id_estado_orden_pago', '!=', 3)
        ->whereNull('pp.id_pago_parcial')
        ->select('p.id_pago', 'p.id_orden_pago')
        ->first();

    if (!$boleta) { echo "  SKIP: no hay boleta sin abonos en esta base\n"; }
    else {
        $antes = DB::table('tb_tes_fecha_probable_pago')->where('id_pago', $boleta->id_pago)->count();

        // Se mandan desordenadas a proposito: el orden_cuotas lo pone el calendario.
        $repo->editarCronogramaDeOpa($boleta->id_orden_pago, [
            ['fecha_probable_pago' => '2026-12-05'],
            ['fecha_probable_pago' => '2026-10-05'],
            ['fecha_probable_pago' => '2026-11-05'],
        ]);

        $fechas = DB::table('tb_tes_fecha_probable_pago')->where('id_pago', $boleta->id_pago)
            ->orderBy('orden_cuotas')->pluck('fecha_probable_pago')->all();

        $check('reemplaza el cronograma (habia ' . $antes . ')', count($fechas) === 3, json_encode($fechas));
        $check('orden_cuotas sigue al calendario, no al tipeo',
            $fechas === ['2026-10-05', '2026-11-05', '2026-12-05'], json_encode($fechas));

        $estado = DB::table('tb_tes_orden_pago')->where('id_orden_pago', $boleta->id_orden_pago)->value('id_estado_orden_pago');
        $check('la orden no quedo PENDIENTE', (int) $estado !== 1, 'estado=' . $estado);

        // Fechas repetidas: una fecha admite un solo abono.
        try {
            $repo->editarCronogramaDeOpa($boleta->id_orden_pago, [
                ['fecha_probable_pago' => '2026-10-05'],
                ['fecha_probable_pago' => '2026-10-05'],
            ]);
            $check('rechaza fechas repetidas', false, 'no corto');
        } catch (\Throwable $e) {
            $check('rechaza fechas repetidas', str_contains($e->getMessage(), 'repetidas'), $e->getMessage());
        }

        try {
            $repo->editarCronogramaDeOpa($boleta->id_orden_pago, []);
            $check('rechaza cronograma vacio', false, 'no corto');
        } catch (\Throwable $e) {
            $check('rechaza cronograma vacio', str_contains($e->getMessage(), 'al menos una'), $e->getMessage());
        }
    }

    // Una boleta CON abonos: no se puede tocar.
    $conAbonos = DB::table('tb_tes_pago as p')
        ->join('tb_tes_pago_parcial as pp', 'pp.id_pago', '=', 'p.id_pago')
        ->where('p.id_estado_orden_pago', '!=', 3)
        ->join('tb_tes_orden_pago as o', 'o.id_orden_pago', '=', 'p.id_orden_pago')
        ->whereNotIn('o.id_estado_orden_pago', [3, 5])
        ->select('p.id_pago', 'p.id_orden_pago')->first();

    if (!$conAbonos) { echo "  SKIP: no hay boleta con abonos\n"; }
    else {
        $fechasAntes = DB::table('tb_tes_fecha_probable_pago')->where('id_pago', $conAbonos->id_pago)->count();
        try {
            $repo->editarCronogramaDeOpa($conAbonos->id_orden_pago, [['fecha_probable_pago' => '2027-01-15']]);
            $check('no deja editar con pagos cargados', false, 'no corto');
        } catch (\Throwable $e) {
            $check('no deja editar con pagos cargados', str_contains($e->getMessage(), 'anularlos primero'), $e->getMessage());
        }
        $check('el cronograma quedo intacto tras el corte',
            DB::table('tb_tes_fecha_probable_pago')->where('id_pago', $conAbonos->id_pago)->count() === $fechasAntes);
    }

    // Una OPA PAGADA no se toca.
    $pagada = DB::table('tb_tes_orden_pago')->where('id_estado_orden_pago', 5)->value('id_orden_pago');
    if ($pagada) {
        try {
            $repo->editarCronogramaDeOpa($pagada, [['fecha_probable_pago' => '2027-01-15']]);
            $check('no deja editar una OPA pagada', false, 'no corto');
        } catch (\Throwable $e) {
            $check('no deja editar una OPA pagada', str_contains($e->getMessage(), 'ya pagada'), $e->getMessage());
        }
    }
} finally {
    DB::rollBack();
}

echo "  --- $ok OK / $fail FALLAS\n";
