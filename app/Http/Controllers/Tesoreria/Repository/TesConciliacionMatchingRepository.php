<?php

namespace App\Http\Controllers\Tesoreria\Repository;

use App\Models\Tesoreria\TesExtractosBancariosEntity;
use App\Models\Tesoreria\TesConciliacionMatcheoEntity;
use App\Models\Tesoreria\TesMovientosCuentaBancariaEntity;
use Carbon\Carbon;

class TesConciliacionMatchingRepository
{
    const VENTANA_DIAS = 7;

    /**
     * Corre el motor de matching sobre extractos puntuales (recién importados) o,
     * si no se pasan ids, sobre todos los que estén PENDIENTE/SUGERIDO sin confirmar.
     */
    public function ejecutarMatching(?array $idsExtracto = null): array
    {
        $query = TesExtractosBancariosEntity::whereIn('estado_conciliacion', ['PENDIENTE', 'SUGERIDO']);
        if (!empty($idsExtracto)) {
            $query->whereIn('id_extracto', $idsExtracto);
        }
        $extractos = $query->get();

        $procesados = 0;
        $conCandidato = 0;
        $sinCandidato = 0;

        foreach ($extractos as $extracto) {
            $procesados++;
            $resultado = $this->matchearExtracto($extracto);

            if ($resultado) {
                $conCandidato++;
            } else {
                $sinCandidato++;
            }
        }

        return [
            'analizados' => $procesados,
            'con_candidato' => $conCandidato,
            'sin_candidato' => $sinCandidato,
        ];
    }

    protected function matchearExtracto(TesExtractosBancariosEntity $extracto): bool
    {
        $resultado = $this->calcularMejorCandidato($extracto);

        TesConciliacionMatcheoEntity::updateOrCreate(
            ['id_extracto_bancario' => $extracto->id_extracto],
            [
                'id_movimiento_interno' => $resultado['movimiento']?->id_movimiento,
                'tipo_origen_interno' => $resultado['tipo_origen'],
                'score_obtenido' => $resultado['score'],
                'reglas_cumplidas' => $resultado['reglas'],
                'estado' => 0, // cargado/sugerido, pendiente de aprobación
                'fecha_matching' => Carbon::now('America/Argentina/Buenos_Aires'),
            ]
        );

        $extracto->score_matching = $resultado['score'];
        $extracto->estado_conciliacion = $resultado['movimiento'] ? 'SUGERIDO' : 'PENDIENTE';
        $extracto->save();

        return (bool) $resultado['movimiento'];
    }

    /**
     * Busca el mejor candidato para un extracto SIN persistir nada — sirve tanto para
     * el re-run sobre extractos ya guardados como para la previsualización de un import
     * que todavía no se grabó en la base.
     *
     * @return array{movimiento: ?TesMovientosCuentaBancariaEntity, score: int, reglas: array<string>, tipo_origen: ?string}
     */
    public function calcularMejorCandidato(TesExtractosBancariosEntity $extracto): array
    {
        $candidatos = $this->buscarCandidatos($extracto);

        $mejorScore = 0;
        $mejorMovimiento = null;
        $mejorReglas = [];

        foreach ($candidatos as $movimiento) {
            [$score, $reglas] = $this->calcularScore($extracto, $movimiento);
            if ($score > $mejorScore) {
                $mejorScore = $score;
                $mejorMovimiento = $movimiento;
                $mejorReglas = $reglas;
            }
        }

        $tipoOrigen = null;
        if ($mejorMovimiento) {
            $tipoOrigen = $mejorMovimiento->id_pago
                ? ($mejorMovimiento->pago && $mejorMovimiento->pago->opa ? 'ORDEN_PAGO' : 'PAGO')
                : 'OPERACION_MANUAL';
        }

        return [
            'movimiento' => $mejorMovimiento,
            'score' => $mejorScore,
            'reglas' => $mejorReglas,
            'tipo_origen' => $tipoOrigen,
        ];
    }

    protected function buscarCandidatos(TesExtractosBancariosEntity $extracto)
    {
        if (!$extracto->fecha) {
            return collect();
        }

        $desde = Carbon::parse($extracto->fecha)->subDays(self::VENTANA_DIAS)->toDateString();
        $hasta = Carbon::parse($extracto->fecha)->addDays(self::VENTANA_DIAS)->toDateString();

        if (!$extracto->id_cuenta_bancaria) {
            return collect(); // no se pudo resolver la cuenta propia para esta fila del extracto
        }

        // Movimientos ya confirmados contra otro extracto no vuelven a ofrecerse como candidato.
        $idsYaConfirmados = TesConciliacionMatcheoEntity::where('estado', 1)
            ->whereNotNull('id_movimiento_interno')
            ->pluck('id_movimiento_interno');

        return TesMovientosCuentaBancariaEntity::with(['pago.opa.proveedor', 'pago.opa.prestador', 'operacion'])
            ->where('id_cuenta_bancaria', $extracto->id_cuenta_bancaria)
            ->whereBetween('fecha_movimiento', [$desde, $hasta])
            ->whereNotIn('id_movimiento', $idsYaConfirmados)
            ->get();
    }

    /**
     * @return array{0: int, 1: array<string>} [score, reglas cumplidas]
     */
    protected function calcularScore(TesExtractosBancariosEntity $extracto, TesMovientosCuentaBancariaEntity $movimiento): array
    {
        $score = 0;
        $reglas = [];

        // Importe exacto
        if ($this->montosIguales($extracto->importe, $movimiento->monto)) {
            $score += 50;
            $reglas[] = 'Importe exacto';
        }

        // Fecha exacta / ±2 días (excluyentes)
        if ($extracto->fecha && $movimiento->fecha_movimiento) {
            $dias = abs(Carbon::parse($extracto->fecha)->diffInDays(Carbon::parse($movimiento->fecha_movimiento)));
            if ($dias === 0) {
                $score += 20;
                $reglas[] = 'Fecha exacta';
            } elseif ($dias <= 2) {
                $score += 10;
                $reglas[] = 'Fecha ± 2 días';
            }
        }

        // La OP puede ser de un proveedor o de un prestador; ambos tienen cuit/razon_social.
        $contraparte = $movimiento->pago?->opa?->proveedor ?? $movimiento->pago?->opa?->prestador;

        // Referencia coincide
        if (!empty($extracto->referencia) && $this->referenciaCoincide($extracto, $movimiento)) {
            $score += 20;
            $reglas[] = 'Referencia coincide';
        }

        // CUIT coincide
        if (!empty($extracto->detalle_cuit) && $contraparte && $contraparte->cuit === $extracto->detalle_cuit) {
            $score += 20;
            $reglas[] = 'CUIT coincide';
        }

        // Proveedor/Prestador coincide (nombre similar)
        if (!empty($extracto->detalle_nombre) && $contraparte && $this->textoSimilar($extracto->detalle_nombre, $contraparte->razon_social, 70)) {
            $score += 15;
            $reglas[] = 'Proveedor coincide';
        }

        // Concepto similar
        $conceptoExtracto = trim(($extracto->concepto ?? '') . ' ' . ($extracto->detalle ?? ''));
        $conceptoMovimiento = trim(($movimiento->descripcion ?? '') . ' ' . ($movimiento->operacion?->observaciones ?? ''));
        if ($conceptoExtracto && $conceptoMovimiento && $this->textoSimilar($conceptoExtracto, $conceptoMovimiento, 40)) {
            $score += 10;
            $reglas[] = 'Concepto similar';
        }

        return [min(100, $score), $reglas];
    }

    protected function montosIguales($a, $b): bool
    {
        return abs(abs((float) $a) - abs((float) $b)) < 0.01;
    }

    protected function referenciaCoincide(TesExtractosBancariosEntity $extracto, TesMovientosCuentaBancariaEntity $movimiento): bool
    {
        $referencia = preg_replace('/\D/', '', (string) $extracto->referencia);
        if (strlen($referencia) < 4) {
            return false; // referencias muy cortas dan falsos positivos
        }

        $candidatosTexto = [
            $movimiento->pago?->num_pago,
            $movimiento->pago?->opa?->num_orden_pago,
            (string) $movimiento->id_operacion,
        ];

        foreach ($candidatosTexto as $texto) {
            if ($texto && str_contains((string) $texto, $referencia)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Similitud de texto simple basada en similar_text (porcentaje).
     */
    protected function textoSimilar(string $a, string $b, float $umbralPorcentaje): bool
    {
        $a = mb_strtoupper(trim($a));
        $b = mb_strtoupper(trim($b));
        if (!$a || !$b) {
            return false;
        }

        similar_text($a, $b, $porcentaje);
        return $porcentaje >= $umbralPorcentaje;
    }
}
