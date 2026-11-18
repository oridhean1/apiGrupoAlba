<?php

namespace App\Http\Controllers\Tesoreria\Repository;

use App\Models\Tesoreria\TesExtractosBancariosEntity;
use App\Models\Tesoreria\TesConciliacionMatcheoEntity;
use App\Models\Tesoreria\TesMovientosCuentaBancariaEntity;
use App\Models\Tesoreria\TesPagosParciales;
use Illuminate\Support\Facades\DB;
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
                'id_pago_parcial' => $resultado['abono']?->id_pago_parcial,
                'tipo_origen_interno' => $resultado['tipo_origen'],
                'score_obtenido' => $resultado['score'],
                'reglas_cumplidas' => $resultado['reglas'],
                'estado' => 0, // cargado/sugerido, pendiente de aprobación
                'fecha_matching' => Carbon::now('America/Argentina/Buenos_Aires'),
            ]
        );

        $extracto->score_matching = $resultado['score'];
        $hay = $resultado['movimiento'] || $resultado['abono'];
        $extracto->estado_conciliacion = $hay ? 'SUGERIDO' : 'PENDIENTE';
        $extracto->save();

        return (bool) $hay;
    }

    /**
     * Busca el mejor candidato para un extracto SIN persistir nada — sirve tanto para
     * el re-run sobre extractos ya guardados como para la previsualización de un import
     * que todavía no se grabó en la base.
     *
     * @return array{movimiento: ?TesMovientosCuentaBancariaEntity, score: int, reglas: array<string>, tipo_origen: ?string}
     */
    public function calcularMejorCandidato(TesExtractosBancariosEntity $extracto, array $excluirIds = [], array $excluirAbonos = []): array
    {
        $candidatos = $this->buscarCandidatos($extracto)
            ->reject(fn($m) => in_array($m->id_movimiento, $excluirIds));

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

        // eCheq emitido todavía sin acreditar: no tiene movimiento, se acredita al confirmar (E4).
        $mejorAbono = null;
        foreach ($this->buscarEcheqsPorAcreditar($extracto)->reject(fn($a) => in_array($a->id_pago_parcial, $excluirAbonos)) as $abono) {
            [$score, $reglas] = $this->calcularScoreEcheq($extracto, $abono);
            if ($score >= $mejorScore) { // a igual puntaje gana el eCheq: banco+número+monto exacto
                $mejorScore = $score;
                $mejorAbono = $abono;
                $mejorReglas = $reglas;
            }
        }
        if ($mejorAbono) {
            return [
                'movimiento' => null,
                'abono' => $mejorAbono,
                'score' => $mejorScore,
                'reglas' => $mejorReglas,
                'tipo_origen' => 'ECHEQ_A_ACREDITAR',
            ];
        }

        $tipoOrigen = null;
        if ($mejorMovimiento) {
            $tipoOrigen = $mejorMovimiento->id_pago
                ? ($mejorMovimiento->pago && $mejorMovimiento->pago->opa ? 'ORDEN_PAGO' : 'PAGO')
                : ($mejorMovimiento->id_orden_medio ? 'COBRANZA' : 'OPERACION_MANUAL');
        }

        return [
            'movimiento' => $mejorMovimiento,
            'abono' => null,
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

        $query = TesMovientosCuentaBancariaEntity::with(['pago.opa.proveedor', 'pago.opa.prestador', 'pago.pagosParciales', 'operacion', 'medio.orden.cliente'])
            ->where('id_cuenta_bancaria', $extracto->id_cuenta_bancaria)
            ->whereBetween('fecha_movimiento', [$desde, $hasta . ' 23:59:59'])
            ->whereNotIn('id_movimiento', $idsYaConfirmados);

        // Sentido: un débito del extracto sólo se cruza con un EGRESO y un crédito con un INGRESO.
        // Así la devolución de un pago anulado o de un eCheq rechazado no se sugiere contra un débito.
        $tipo = self::tipoMovimientoDeImporte($extracto->importe);
        if ($tipo) {
            $query->where('tipo_movimiento', $tipo);
        }

        return $query->get();
    }

    /**
     * eCheq emitidos por esta cuenta, con su pago ejecutado y número real, que todavía no se
     * acreditaron. Criterio del cliente (R-00000689 P1): banco (cuenta) + número + monto exacto.
     * Sólo los del circuito nuevo (con evento EMISION): los anteriores ya tienen su débito como
     * movimiento del pago y se concilian contra ese.
     */
    protected function buscarEcheqsPorAcreditar(TesExtractosBancariosEntity $extracto)
    {
        if (!$extracto->id_cuenta_bancaria || self::tipoMovimientoDeImporte($extracto->importe) !== 'EGRESO') {
            return collect();
        }
        $tomados = TesConciliacionMatcheoEntity::where('estado', 1)->whereNotNull('id_pago_parcial')->pluck('id_pago_parcial');

        return TesPagosParciales::with(['pago.opa.proveedor', 'pago.opa.prestador'])
            ->where('id_forma_pago', TesInstrumentoPagoRepository::FORMA_PAGO_ECHEQ)
            ->where('id_estado_instrumento', TesInstrumentoPagoRepository::EMITIDO)
            ->where('id_cuenta_bancaria', $extracto->id_cuenta_bancaria)
            ->whereNotNull('fecha_ejecucion')
            ->where(fn($q) => $q->whereNull('numero_provisorio')->orWhere('numero_provisorio', 0))
            ->whereRaw('ABS(monto_pago - ?) < 0.01', [abs((float) $extracto->importe)])
            ->whereNotIn('id_pago_parcial', $tomados)
            ->whereExists(fn($q) => $q->select(DB::raw(1))->from('tb_cont_asientos_pago_historial as h')
                ->whereColumn('h.id_pago_parcial', 'tb_tes_pago_parcial.id_pago_parcial')
                ->where('h.tipo_evento', 'EMISION'))
            ->get()
            ->filter(fn($a) => $this->numeroEnExtracto($extracto, [$a->numero_echeq]))
            ->values();
    }

    /** @return array{0: int, 1: array<string>} */
    protected function calcularScoreEcheq(TesExtractosBancariosEntity $extracto, TesPagosParciales $abono): array
    {
        $score = 80;
        $reglas = ['Importe exacto', 'Nº cheque/eCheq coincide'];
        $contraparte = $abono->pago?->opa?->proveedor ?? $abono->pago?->opa?->prestador;
        if (!empty($extracto->detalle_cuit) && $contraparte && $contraparte->cuit === $extracto->detalle_cuit) {
            $score += 20;
            $reglas[] = 'CUIT coincide';
        } elseif (!empty($extracto->detalle_nombre) && $contraparte && $this->textoSimilar($extracto->detalle_nombre, $contraparte->razon_social, 70)) {
            $score += 15;
            $reglas[] = 'Proveedor coincide';
        }
        return [min(100, $score), $reglas];
    }

    /**
     * Confirma la conciliación de un débito contra un eCheq emitido: lo ACREDITA con la fecha del
     * extracto (asiento Debe diferido / Haber banco + movimiento, en `marcarAcreditado`) y devuelve
     * el movimiento que quedó, contra el que se concilia. Siempre a partir del clic de Tesorería.
     */
    public function acreditarDesdeExtracto(TesExtractosBancariosEntity $extracto, $idAbono): int
    {
        $abono = TesPagosParciales::find($idAbono);
        if (!$abono) {
            throw new \Exception("No existe el eCheq {$idAbono}.");
        }
        if (empty($extracto->id_cuenta_bancaria)) {
            throw new \Exception('La línea del extracto no tiene cuenta bancaria propia asignada: no se puede acreditar un eCheq desde ella.');
        }
        if ((int) $abono->id_cuenta_bancaria !== (int) $extracto->id_cuenta_bancaria) {
            throw new \Exception('El eCheq se emitió desde otra cuenta bancaria que la de la línea del extracto.');
        }
        if (self::tipoMovimientoDeImporte($extracto->importe) !== 'EGRESO') {
            throw new \Exception('Sólo un débito del extracto puede acreditar un eCheq.');
        }
        if (!$this->montosIguales($extracto->importe, $abono->monto_pago)) {
            throw new \Exception('El importe de la línea no coincide exacto con el del eCheq.');
        }
        if (!$this->numeroEnExtracto($extracto, [$abono->numero_echeq])) {
            throw new \Exception("El número del eCheq ({$abono->numero_echeq}) no figura en la línea del extracto.");
        }
        if (TesConciliacionMatcheoEntity::where('estado', 1)->where('id_pago_parcial', $idAbono)->exists()) {
            throw new \Exception('Ese eCheq ya está conciliado contra otra línea del extracto.');
        }
        if (empty($extracto->fecha)) {
            throw new \Exception('La línea del extracto no tiene fecha: no se puede acreditar el eCheq.');
        }

        app(TesInstrumentoPagoRepository::class)->marcarAcreditado(
            $idAbono,
            \Carbon\Carbon::parse($extracto->fecha)->toDateString(),
            app(TestOrdenPagoRepository::class)
        );

        $mov = TesMovientosCuentaBancariaEntity::where('id_pago', $abono->id_pago)
            ->where('id_cuenta_bancaria', $abono->id_cuenta_bancaria)
            ->where('tipo_movimiento', 'EGRESO')
            ->whereRaw('ABS(monto - ?) < 0.01', [(float) $abono->monto_pago])
            ->whereDoesntHave('matcheos', fn($q) => $q->where('estado', 1))
            ->orderByDesc('id_movimiento')
            ->first();
        if (!$mov) {
            throw new \Exception('El eCheq no dejó su débito en la cuenta (es del circuito anterior): acreditalo a mano y conciliá la línea contra el movimiento del pago.');
        }

        return $mov->id_movimiento;
    }

    /**
     * Números de 4+ dígitos (sin ceros a la izquierda) de la referencia y el concepto. 4 porque los
     * eCheq de OSV (Nación) tienen 4 dígitos: 110 de 111 en `osv_1029`.
     */
    protected function numerosDelExtracto(TesExtractosBancariosEntity $extracto): array
    {
        $nums = [];
        foreach ([$extracto->referencia, $extracto->concepto] as $texto) {
            preg_match_all('/\d{4,}/', (string) $texto, $m);
            foreach ($m[0] as $n) {
                $nums[] = ltrim($n, '0');
            }
        }
        return array_values(array_filter(array_unique($nums), fn($n) => strlen($n) >= 4));
    }

    /** ¿Alguno de los números (de cheque/eCheq) figura en la línea? Ver numeroInstrumentoCoincide. */
    protected function numeroEnExtracto(TesExtractosBancariosEntity $extracto, array $numeros): bool
    {
        $delExtracto = $this->numerosDelExtracto($extracto);
        foreach ($numeros as $num) {
            $num = ltrim(preg_replace('/\D/', '', (string) $num), '0');
            if (strlen($num) < 4) {
                continue; // números muy cortos dan falsos positivos
            }
            foreach ($delExtracto as $n) {
                // Igual (sin ceros a la izquierda), o uno termina en el otro si el corto tiene 6+ dígitos.
                $corto = min(strlen($n), strlen($num));
                if ($n === $num || ($corto >= 6 && (str_ends_with($n, $num) || str_ends_with($num, $n)))) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Débito (importe < 0) → EGRESO; crédito (> 0) → INGRESO; 0 → sin sentido. */
    public static function tipoMovimientoDeImporte($importe): ?string
    {
        $importe = (float) $importe;
        if (abs($importe) < 0.01) {
            return null;
        }
        return $importe < 0 ? 'EGRESO' : 'INGRESO';
    }

    /**
     * Valida que una línea del extracto pueda conciliarse contra un movimiento. Se usa al guardar
     * la carga y al confirmar desde el visor: el movimiento puede venir elegido a mano.
     *
     * Corta (no deja pasar) si falta el dato con que validar — ver CLAUDE.md, "una guarda que
     * protege plata nunca se apaga sola".
     */
    public function validarParaConfirmar(TesExtractosBancariosEntity $extracto, $idMovimiento, $idExtractoActual = null): void
    {
        $mov = TesMovientosCuentaBancariaEntity::find($idMovimiento);
        if (!$mov) {
            throw new \Exception("No existe el movimiento {$idMovimiento}.");
        }
        if (empty($extracto->id_cuenta_bancaria)) {
            throw new \Exception('La línea del extracto no tiene cuenta bancaria propia asignada: no se puede conciliar contra un movimiento. Revisá el banco de la línea en el catálogo de cuentas.');
        }
        // Misma cuenta ⇒ misma razón social (una cuenta pertenece a una sola razón).
        if ((int) $mov->id_cuenta_bancaria !== (int) $extracto->id_cuenta_bancaria) {
            throw new \Exception('El movimiento elegido es de otra cuenta bancaria que la línea del extracto.');
        }
        $tipo = self::tipoMovimientoDeImporte($extracto->importe);
        if ($tipo && $mov->tipo_movimiento !== $tipo) {
            throw new \Exception($tipo === 'EGRESO'
                ? 'La línea es un débito y el movimiento elegido es un ingreso (p. ej. una devolución).'
                : 'La línea es un crédito y el movimiento elegido es un egreso.');
        }
        $yaUsado = TesConciliacionMatcheoEntity::where('estado', 1)
            ->where('id_movimiento_interno', $idMovimiento)
            ->when($idExtractoActual, fn($q) => $q->where('id_extracto_bancario', '!=', $idExtractoActual))
            ->exists();
        if ($yaUsado) {
            throw new \Exception("El movimiento {$idMovimiento} ya está conciliado contra otra línea del extracto.");
        }
    }

    /**
     * ¿El pago tiene algún egreso conciliado (en esa cuenta, si se indica)? Lo usa la anulación:
     * un pago conciliado no se anula sin desconciliarlo antes (R-00000689 P7).
     */
    public static function medioTieneIngresoConciliado(array $idsMedio): bool
    {
        if (empty($idsMedio)) {
            return false;
        }
        return TesMovientosCuentaBancariaEntity::whereIn('id_orden_medio', $idsMedio)
            ->where('tipo_movimiento', 'INGRESO')
            ->whereHas('matcheos', fn($q) => $q->where('estado', 1))
            ->exists();
    }

    public static function pagoTieneEgresoConciliado($idPago, $idCuenta = null): bool
    {
        return TesMovientosCuentaBancariaEntity::where('id_pago', $idPago)
            ->where('tipo_movimiento', 'EGRESO')
            ->when($idCuenta, fn($q) => $q->where('id_cuenta_bancaria', $idCuenta))
            ->whereHas('matcheos', fn($q) => $q->where('estado', 1))
            ->exists();
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

        // La OP puede ser de un proveedor o de un prestador; un cobro, de un cliente. Todos tienen cuit/razon_social.
        $contraparte = $movimiento->pago?->opa?->proveedor ?? $movimiento->pago?->opa?->prestador
            ?? $movimiento->medio?->orden?->cliente;

        // Nº de cheque / eCheq: es lo que el banco informa en el débito (R-00000689 E1).
        if ($this->numeroInstrumentoCoincide($extracto, $movimiento)) {
            $score += 30;
            $reglas[] = 'Nº cheque/eCheq coincide';
        } elseif (!empty($extracto->referencia) && $this->referenciaCoincide($extracto, $movimiento)) {
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
            // Cobro: referencia de la transferencia o nº del cheque/eCheq recibido.
            preg_replace('/\D/', '', (string) $movimiento->medio?->referencia),
        ];

        foreach ($candidatosTexto as $texto) {
            if ($texto && str_contains((string) $texto, $referencia)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compara los números de cheque/eCheq de los abonos del pago (de esa cuenta, sin los
     * provisorios `PROV-`) contra la referencia y los números largos del concepto del extracto.
     */
    protected function numeroInstrumentoCoincide(TesExtractosBancariosEntity $extracto, TesMovientosCuentaBancariaEntity $movimiento): bool
    {
        $numeros = [];
        foreach ($movimiento->pago?->pagosParciales ?? [] as $p) {
            if (!empty($p->id_cuenta_bancaria) && (int) $p->id_cuenta_bancaria !== (int) $movimiento->id_cuenta_bancaria) {
                continue;
            }
            $numeros[] = $p->num_cheque;
            if (!(int) $p->numero_provisorio) {
                $numeros[] = $p->numero_echeq;
            }
        }
        return $numeros && $this->numeroEnExtracto($extracto, $numeros);
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
