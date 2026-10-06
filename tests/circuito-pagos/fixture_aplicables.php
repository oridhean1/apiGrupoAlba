<?php
// Fixture compartido de los tests de anticipos: un prestador + UNA razon social con al menos $n
// facturas a las que todavia se les puede aplicar saldo.
//
// Usa el MISMO criterio que la pantalla: VF, con saldo imputable y de la MISMA razon social que el
// anticipo. Antes no miraba la razon y los tests creaban el anticipo con "la primera razon de la
// tabla": podian armar justo el cruce entre entidades que la regla prohibe. (2026-10-01)
use App\Http\Controllers\Tesoreria\Repository\TesAnticipoRepository;
use Illuminate\Support\Facades\DB;

if (!function_exists('prestadorConAplicables')) {
    function prestadorConAplicables(TesAnticipoRepository $ant, int $n = 2, float $minSaldo = 0.01): ?array
    {
        $candidatos = DB::table('tb_facturacion_datos')
            ->whereNotNull('id_prestador')->whereNotNull('id_locatorio')
            ->where('id_tipo_factura', '!=', 16)->where('estado', 3)->where('total_neto', '>', 0)
            ->select('id_prestador', 'id_locatorio')->distinct()->limit(600)->get();

        foreach ($candidatos as $c) {
            $f = collect($ant->facturasAplicables($c->id_prestador, 'PRESTADOR', $c->id_locatorio))
                ->filter(fn($x) => $x['saldo'] >= $minSaldo)->values();
            if ($f->count() >= $n) {
                return ['id_prestador' => $c->id_prestador, 'id_razon' => (int) $c->id_locatorio, 'facturas' => $f->all()];
            }
        }

        return null;
    }
}
