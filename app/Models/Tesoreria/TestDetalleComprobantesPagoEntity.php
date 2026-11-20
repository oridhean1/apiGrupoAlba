<?php

namespace App\Models\Tesoreria;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TestDetalleComprobantesPagoEntity extends Model
{
    use HasFactory;

    protected $table = 'tb_test_pago_detalle_comprobantes';
    protected $primaryKey = 'id_comprobante';
    public $timestamps = false;
    protected $fillable = [
        'id_pago',
        // Fecha del cronograma que cubre. NULL = comprobante viejo, cubre toda la boleta. (2026-10-23)
        'id_fecha_probable',
        'nombre_archivo',
        'fecha_registra',
        'cod_usuario_registra',
        'cod_usuario_elimina',
        'fecha_elimina',
        'estado'
    ];

    /**
     * Fechas del cronograma que respalda: `int[]`, o `null` = toda la boleta. Sale del puente
     * `tb_tes_comprobante_pago_fecha` (puede ser más de una, R-00000661); un comprobante sin filas
     * ahí usa la columna `id_fecha_probable`. Mismo criterio que
     * `TesPagosRepository::coberturaDeComprobantes()`.
     */
    protected $appends = ['fechas_cubiertas'];

    public function getFechasCubiertasAttribute(): ?array
    {
        $filas = \Illuminate\Support\Facades\DB::table('tb_tes_comprobante_pago_fecha')
            ->where('id_comprobante', $this->id_comprobante)
            ->get(['id_fecha_probable', 'estado']);

        if ($filas->isNotEmpty()) {
            return $filas->where('estado', 1)->pluck('id_fecha_probable')->map(fn($f) => (int) $f)->values()->all();
        }

        return is_null($this->id_fecha_probable) ? null : [(int) $this->id_fecha_probable];
    }

    protected $hidden = [
        'id_pago',
        'cod_usuario_registra',
        'cod_usuario_elimina',
        'fecha_elimina',
        'estado'
    ];
}
