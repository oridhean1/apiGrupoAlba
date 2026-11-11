<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Fiscalizacion\DeudaEmpresaJob;

class ActualizarInteresesDeuda extends Command
{
    protected $signature = 'deudas:actualizar-intereses';
    protected $description = 'Recalcula diariamente los intereses de las deudas impagas y actualiza su estado.';

    public function handle()
    {
        $inicio = now();

        $job = new DeudaEmpresaJob([
            'job_name' => $this->signature,
            'started_at' => $inicio,
            'success' => false,
        ]);
        $job->save();

        try {
            $hoy = now()->toDateString();

            DB::table('tb_fisca_deudas_aportes_empresa')
                ->whereIn('estado', ['Vigente', 'Vencido'])
                ->orderBy('id_deuda')
                ->chunk(500, function ($deudas) use ($hoy) {

                    foreach ($deudas as $deuda) {
                        $saldoBase = ($deuda->monto_deuda ?? 0)
                            - ($deuda->monto_transferido ?? 0)
                            - ($deuda->monto_revertido ?? 0)
                            - ($deuda->monto_retenido ?? 0);

                        if ($saldoBase <= 0) {
                            DB::table('tb_fisca_deudas_aportes_empresa')
                                ->where('id_deuda', $deuda->id_deuda)
                                ->update([
                                    'estado' => 'Finalizado',
                                    'intereses' => 0,
                                    'fecha_recalculo' => now(),
                                ]);
                            continue;
                        }

                        $tasas = DB::table('tb_fisca_tasas_interes')
                            ->where('articulo_resolucion', 'Artículo 1°')
                            ->where('vigencia_inicio', '<=', $hoy)
                            ->orderBy('vigencia_inicio')
                            ->get();

                        if ($tasas->isEmpty()) {
                            continue;
                        }

                        $interesCalculado = 0;

                        foreach ($tasas as $tasa) {
                            $inicioTasa = Carbon::parse($deuda->fecha_vencimiento)->greaterThan(Carbon::parse($tasa->vigencia_inicio))
                                ? Carbon::parse($deuda->fecha_vencimiento)
                                : Carbon::parse($tasa->vigencia_inicio);

                            $finTasa = $tasa->vigencia_fin
                                ? Carbon::parse($tasa->vigencia_fin)
                                : Carbon::parse($hoy);

                            if ($finTasa->lt(Carbon::parse($deuda->fecha_vencimiento))) {
                                continue;
                            }

                            $fin = $finTasa->lessThan(Carbon::parse($hoy)) ? $finTasa : Carbon::parse($hoy);
                            $dias = max(0, $inicioTasa->diffInDays($fin));

                            $interesCalculado += $saldoBase * ($tasa->interes_diario ?? 0) * $dias;
                        }

                        DB::table('tb_fisca_deudas_aportes_empresa')
                            ->where('id_deuda', $deuda->id_deuda)
                            ->update([
                                'intereses' => round($interesCalculado, 2),
                                'monto_deuda' => round($saldoBase + $interesCalculado, 2),
                                'estado' => Carbon::parse($deuda->fecha_vencimiento)->lt($hoy)
                                    ? 'Vencido' : $deuda->estado,
                                'fecha_recalculo' => now(),
                            ]);
                    }
                });

            $procesadas = DB::table('tb_fisca_deudas_aportes_empresa')
                ->whereDate('fecha_recalculo', $hoy)
                ->count();

            $job->update([
                'finished_at' => now(),
                'success' => true,
                'message' => "Intereses actualizados para {$procesadas} deudas al {$hoy}.",
            ]);

            $this->info("Intereses actualizados para {$procesadas} deudas al {$hoy}.");
        } catch (\Exception $e) {
            $job->update([
                'finished_at' => now(),
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ]);

            $this->error('Error: ' . $e->getMessage());
        }

        return 0;
    }
}
