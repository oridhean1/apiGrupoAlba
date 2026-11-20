<?php

namespace App\Models\Tesoreria;

use App\Models\configuracion\RazonSocialModelo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TesExtractosBancariosEntity extends Model
{
    use HasFactory;

    protected $table = 'tb_tes_extracto_bancarios';
    protected $primaryKey = 'id_extracto';
    public $timestamps = false;

    protected $fillable = [
        'id_cuenta_bancaria',
        'id_razon',
        'fecha',
        'banco',
        'concepto',
        'importe',
        'saldo',
        'referencia',
        'detalle',
        'detalle_nombre',
        'detalle_cuit',
        // Campos Cygnus Finance AI
        'estado_conciliacion',
        'score_matching',
        'id_movimiento_match',
        // Campos de auditoría
        'id_usuario',
        'fecha_registra',
        'id_usuario_confirma',
        'fecha_confirma',
        'observaciones'
    ];

    /** Concepto bancario sugerido para registrar la línea (E5). Ver ConceptosBancarios. */
    protected $appends = ['concepto_sugerido'];

    public function getConceptoSugeridoAttribute(): ?string
    {
        return \App\Http\Controllers\Tesoreria\Repository\ConceptosBancarios::sugerir(
            $this->concepto,
            \App\Http\Controllers\Tesoreria\Repository\TesConciliacionMatchingRepository::tipoMovimientoDeImporte($this->importe)
        );
    }

    public function cuentaBancaria()
    {
        return $this->hasOne(TesCuentasBancariasEntity::class, 'id_cuenta_bancaria', 'id_cuenta_bancaria');
    }

    public function razonSocial()
    {
        return $this->hasOne(RazonSocialModelo::class, 'id_razon', 'id_razon');
    }

    public function movimientoMatch()
    {
        return $this->hasOne(TesMovientosCuentaBancariaEntity::class, 'id_movimiento', 'id_movimiento_match');
    }

    public function matcheos()
    {
        return $this->hasMany(TesConciliacionMatcheoEntity::class, 'id_extracto_bancario', 'id_extracto');
    }

    /**
     * Cuántas líneas iguales (banco/fecha/concepto/importe) de la razón social ya están guardadas.
     * Se compara por CANTIDAD, no por existencia: el banco puede informar dos operaciones reales
     * idénticas el mismo día (R-00000689 P3) y la segunda no es un duplicado.
     */
    public static function contarIguales($idRazon, $banco, $fecha, $concepto, $importe): int
    {
        return static::where('id_razon', $idRazon)
            ->where('banco', $banco)
            ->where('fecha', $fecha)
            ->where('concepto', $concepto)
            ->whereRaw('ABS(importe - ?) < 0.01', [$importe ?? 0])
            ->count();
    }

    /** Clave para numerar las ocurrencias de una misma línea dentro de un archivo o lote. */
    public static function claveLinea($idRazon, $banco, $fecha, $concepto, $importe): string
    {
        return implode('|', [$idRazon, $banco, $fecha, $concepto, number_format((float) $importe, 2, '.', '')]);
    }
}
