<?php

namespace App\Http\Controllers\Contabilidad\Repository;

use App\Models\Contabilidad\AsientosContablesEntity;
use App\Models\Contabilidad\AsientosPagoHistorialEntity;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class AsientosPagoHistorialRepository
{
    private $user;
    private $fechaActual;
    private $asientoContableRepository;

    public function __construct(AsientoContableRepository $asientoContableRepository)
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
        $this->asientoContableRepository = $asientoContableRepository;
    }

    public function obtenerAsientoVigentePago($idPago)
    {
        return AsientosPagoHistorialEntity::with(['asientoContable', 'asientoContable.detalle'])
            ->where('id_pago', $idPago)
            ->where('tipo_evento', 'ALTA')
            ->where('es_contraasiento', false)
            ->whereHas('asientoContable', fn($q) => $q->where('vigente', 'ACTIVO'))
            ->first();
    }

    /**
     * @param int|null $idPagoParcial   Abono (instrumento) al que pertenece el evento, si aplica.
     * @param int|null $idAsientoDetalle Línea puntual del asiento, para poder revertir SOLO esa.
     *
     * Los dos últimos parámetros existen para el circuito de eCheq (2026-09-12). Un pago puede
     * tener varios instrumentos y cada uno su propia línea en el mismo asiento; sin saber cuál es
     * la línea, rechazar un eCheq obligaba a revertir el asiento completo y se llevaba puestos los
     * otros medios de pago. Quedan opcionales: los eventos a nivel boleta (ALTA, ANULACION) los
     * dejan en null, como siempre.
     */
    public function guardarHistorial($idPago, $idAsientoContable, $tipoEvento, $esContraasiento = false, $idAsientoOrigen = null, $observacion = null, $idPagoParcial = null, $idAsientoDetalle = null)
    {
        return AsientosPagoHistorialEntity::create([
            'id_pago'                     => $idPago,
            'id_pago_parcial'             => $idPagoParcial,
            'id_asiento_contable'         => $idAsientoContable,
            'id_asiento_contable_detalle' => $idAsientoDetalle,
            'tipo_evento'                 => $tipoEvento,
            'es_contraasiento'            => $esContraasiento,
            'id_asiento_origen'           => $idAsientoOrigen,
            'observacion'                 => $observacion,
            'cod_usuario'                 => $this->user->cod_usuario,
            'fecha_registra'              => $this->fechaActual
        ]);
    }

    public function pagoTieneAsientos($idPago)
    {
        return AsientosPagoHistorialEntity::where('id_pago', $idPago)->exists();
    }

    /**
     * Genera contraasiento invirtiendo DEBE ↔ HABER del asiento original.
     * No gestiona transacción propia — depende de la transacción del controlador.
     */
    public function generarContraasiento($idAsientoOriginal, $tipoEvento, $idPago, $observacion = null)
    {
        $asientoOriginal = AsientosContablesEntity::with('detalle')->find($idAsientoOriginal);

        if (!$asientoOriginal) {
            throw new Exception("No se encontró el asiento contable original (ID: {$idAsientoOriginal}).");
        }

        $numeroCorrelativo = $this->asientoContableRepository->obtenerSiguienteNumeroAsiento();

        $contraasiento = $this->asientoContableRepository->findByCrearAsiento(
            $asientoOriginal->id_tipo_asiento,
            'CONTRAASIENTO - ' . $asientoOriginal->asiento_modelo,
            'CONTRAASIENTO - ' . $asientoOriginal->asiento_leyenda . ' - ' . strtoupper($tipoEvento),
            $numeroCorrelativo,
            $asientoOriginal->id_periodo_contable,
            $asientoOriginal->numero,
            'ACTIVO',
            $asientoOriginal->id_razon
        );

        foreach ($asientoOriginal->detalle as $detalleOriginal) {
            $this->asientoContableRepository->findByCrearDetalleAsiento([
                'id_asiento_contable'                => $contraasiento->id_asiento_contable,
                'cod_proveedor'                      => $detalleOriginal->cod_proveedor,
                'cod_prestador'                      => $detalleOriginal->cod_prestador,
                'id_proveedor_cuenta_contable'       => $detalleOriginal->id_proveedor_cuenta_contable,
                'id_tipo_prestador_cuenta_contable'  => $detalleOriginal->id_tipo_prestador_cuenta_contable,
                'id_forma_pago_cuenta_contable'      => $detalleOriginal->id_forma_pago_cuenta_contable,
                'id_familia_cuenta_contable'         => $detalleOriginal->id_familia_cuenta_contable,
                'id_cuenta_bancaria_cuenta_contable' => $detalleOriginal->id_cuenta_bancaria_cuenta_contable,
                'id_retencion_cuenta_contable'       => $detalleOriginal->id_retencion_cuenta_contable,
                'monto_debe'                         => $detalleOriginal->monto_haber,
                'monto_haber'                        => $detalleOriginal->monto_debe,
                'observaciones'                      => 'CONTRAASIENTO - ' . $detalleOriginal->observaciones,
                'id_detalle_plan'                    => $detalleOriginal->id_detalle_plan
            ]);
        }

        $this->asientoContableRepository->findByAnularAsientoContableId($idAsientoOriginal, 'CONTRAASIENTO');

        $this->guardarHistorial(
            $idPago,
            $contraasiento->id_asiento_contable,
            $tipoEvento,
            true,
            $idAsientoOriginal,
            $observacion ?? "Contraasiento generado por {$tipoEvento} de pago"
        );

        return $contraasiento;
    }

    /**
     * Procesa la anulación contable de un pago.
     * No gestiona transacción propia — depende de la transacción del controlador.
     */
    public function procesarAnulacionPago($idPago, $observacion = null)
    {
        $historialVigente = $this->obtenerAsientoVigentePago($idPago);

        if (!$historialVigente) {
            return null; // Pago sin asiento — se permite anular igual
        }

        return $this->generarContraasiento(
            $historialVigente->id_asiento_contable,
            'ANULACION',
            $idPago,
            $observacion
        );
    }
}
