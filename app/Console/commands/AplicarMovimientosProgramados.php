<?php

namespace App\Console\Commands;

use App\Mail\MovimientosProgramadosErrorMail;
use App\Models\afiliado\AfiliadoMovimientoProgramadoEntity;
use App\Models\afiliado\AfiliadoPadronEntity;
use App\Models\AuditoriaPadronModelo;
use App\Models\PadronComercialModelo;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Aplica los traspasos entre Orígenes programados desde Comercial (R-00000352).
 * Corre el día 1 de cada mes a la 1:00 (ver Kernel). Por cada afiliado con un ALTA pendiente
 * cuya fecha ya llegó, pasa caja y origen al nuevo valor en tb_padron_comercial y tb_padron,
 * y marca como APLICADO el par BAJA/ALTA. Si algo falla lo marca ERROR y notifica por mail.
 */
class AplicarMovimientosProgramados extends Command
{
    protected $signature = 'afiliados:aplicar-movimientos-programados';
    protected $description = 'Aplica los traspasos de afiliados entre Orígenes cuya fecha de vigencia ya llegó.';

    public function handle()
    {
        if (!AfiliadoMovimientoProgramadoEntity::habilitado()) {
            $this->info('Traspasos programados no habilitados en esta base.');
            return Command::SUCCESS;
        }

        $now = Carbon::now('America/Argentina/Buenos_Aires');
        $errores = [];
        $aplicados = 0;

        $altas = AfiliadoMovimientoProgramadoEntity::where('tipo_movimiento', 'ALTA')
            ->where('estado', 'PENDIENTE')
            ->where('fecha_vigencia', '<=', $now->format('Y-m-d'))
            ->orderBy('id')
            ->get();

        foreach ($altas as $alta) {
            $baja = AfiliadoMovimientoProgramadoEntity::where('id_vinculo', $alta->id_vinculo)
                ->where('dni', $alta->dni)
                ->where('tipo_movimiento', 'BAJA')
                ->where('estado', 'PENDIENTE')
                ->first();

            DB::beginTransaction();
            try {
                $comercial = PadronComercialModelo::where('dni', $alta->dni)->first();
                $afiliado = AfiliadoPadronEntity::where('dni', $alta->dni)->first();

                if (!$baja) {
                    throw new \Exception('No se encontró el movimiento de baja vinculado');
                }
                if (!$comercial && !$afiliado) {
                    throw new \Exception('El afiliado ya no existe en el padrón');
                }
                $actual = $comercial ?? $afiliado;
                if ($actual->activo != 1) {
                    throw new \Exception('El afiliado no está activo');
                }
                // Si alguien cambió el origen a mano después de programar el traspaso, no se pisa
                if ($actual->id_comercial_origen != $baja->id_comercial_origen) {
                    throw new \Exception('El origen actual del afiliado no coincide con el origen de baja programado');
                }

                $cambios = [
                    'id_comercial_caja' => $alta->id_comercial_caja,
                    'id_comercial_origen' => $alta->id_comercial_origen,
                ];
                if ($comercial) {
                    PadronComercialModelo::where('id', $comercial->id)->update($cambios);
                }
                if ($afiliado) {
                    AfiliadoPadronEntity::where('id', $afiliado->id)->update($cambios);
                }

                AuditoriaPadronModelo::create([
                    'fecha' => $now->format('Y-m-d H:i:s'),
                    'antes' => json_encode([
                        'id_comercial_caja' => $baja->id_comercial_caja,
                        'id_comercial_origen' => $baja->id_comercial_origen,
                    ]),
                    'ahora' => json_encode($cambios),
                    'id_padron' => $alta->dni,
                    'cod_usuario' => $alta->cod_usuario,
                ]);

                AfiliadoMovimientoProgramadoEntity::whereIn('id', [$baja->id, $alta->id])->update([
                    'estado' => 'APLICADO',
                    'fecha_aplicacion' => $now->format('Y-m-d H:i:s'),
                ]);

                DB::commit();
                $aplicados++;
            } catch (\Throwable $th) {
                DB::rollBack();
                $ids = $baja ? [$baja->id, $alta->id] : [$alta->id];
                AfiliadoMovimientoProgramadoEntity::whereIn('id', $ids)->update([
                    'estado' => 'ERROR',
                    'detalle_error' => mb_substr($th->getMessage(), 0, 500),
                    'fecha_aplicacion' => $now->format('Y-m-d H:i:s'),
                ]);
                $errores[] = [
                    'dni' => $alta->dni,
                    'cuil_tit' => $alta->cuil_tit,
                    'fecha_vigencia' => $alta->fecha_vigencia,
                    'detalle' => $th->getMessage(),
                ];
            }
        }

        $this->info("Traspasos aplicados: {$aplicados}. Con error: " . count($errores));

        if (count($errores) > 0) {
            $this->notificarErrores($errores);
        }

        return Command::SUCCESS;
    }

    private function notificarErrores(array $errores)
    {
        Log::warning('Traspasos programados con error', $errores);

        $destinatarios = array_filter((array) config('mail.notificar_movimientos_programados'));
        if (count($destinatarios) == 0) {
            return;
        }

        try {
            Mail::to($destinatarios)->send(new MovimientosProgramadosErrorMail($errores));
        } catch (\Throwable $th) {
            Log::error('No se pudo notificar los traspasos con error: ' . $th->getMessage());
        }
    }
}
