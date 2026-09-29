<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Foto mensual del padrón (R-00000352, parte 2, solo ALBA).
 * Copia el estado actual de tb_padron a tb_padron_foto_mensual para el período indicado (por defecto el mes en curso).
 * Corre el día 5 de cada mes a la 1:00 (ver Kernel). Si la foto del período ya existe no la vuelve a generar.
 */
class FotoPadronMensual extends Command
{
    protected $signature = 'afiliados:foto-padron-mensual {--periodo= : Período YYYY-MM (por defecto el mes en curso)}';
    protected $description = 'Guarda la foto mensual del padrón completo de afiliados.';

    public function handle()
    {
        if (!Schema::hasTable('tb_padron_foto_mensual')) {
            $this->info('Foto mensual del padrón no habilitada en esta base.');
            return Command::SUCCESS;
        }

        $now = Carbon::now('America/Argentina/Buenos_Aires');
        $periodo = $this->option('periodo') ?: $now->format('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
            $this->error('Período inválido, use el formato YYYY-MM');
            return Command::FAILURE;
        }

        if (DB::table('tb_padron_foto_mensual')->where('periodo', $periodo)->exists()) {
            $this->info("La foto del período {$periodo} ya existe.");
            return Command::SUCCESS;
        }

        try {
            $cantidad = DB::affectingStatement(
                'INSERT INTO tb_padron_foto_mensual
                    (periodo, fecha_generacion, id_padron, dni, cuil_tit, cuil_benef, nombre, apellidos, id_parentesco,
                     activo, fe_alta, fe_baja, id_comercial_caja, id_comercial_origen, id_locatario)
                 SELECT ?, ?, id, dni, cuil_tit, cuil_benef, nombre, apellidos, id_parentesco,
                        activo, fe_alta, fe_baja, id_comercial_caja, id_comercial_origen, id_locatario
                 FROM tb_padron',
                [$periodo, $now->format('Y-m-d H:i:s')]
            );
        } catch (\Throwable $th) {
            Log::error("Foto mensual del padrón {$periodo}: " . $th->getMessage());
            $this->error($th->getMessage());
            return Command::FAILURE;
        }

        $this->info("Foto del período {$periodo} generada: {$cantidad} afiliados.");
        return Command::SUCCESS;
    }
}
