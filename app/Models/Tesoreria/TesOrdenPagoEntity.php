<?php

namespace App\Models\Tesoreria;

use App\Models\facturacion\FacturacionDatosEntity;
use App\Models\prestadores\PrestadorEntity;
use App\Models\proveedor\MatrizProveedoresEntity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TesOrdenPagoEntity extends Model
{
    use HasFactory;

    protected $table = 'tb_tes_orden_pago';
    protected $primaryKey = 'id_orden_pago';
    public $timestamps = false;

    protected $fillable = [
        'id_proveedor',
        'id_prestador',
        'monto_orden_pago',
        'id_moneda',
        'fecha_emision',
        'fecha_vencimiento',
        'fecha_confirma_pago',
        'id_estado_orden_pago',
        'monto_anticipado',
        'observaciones',
        'cod_usuario',
        'fecha_genera',
        'id_factura',
        'num_orden_pago',
        'tipo_factura',
        'motivo_rechazo',
        'fecha_rechazo',
        'fecha_probable_pago',
        'pago_emergencia',
        'cuotas',
        'cod_usuario_rechaza',
        // Trazabilidad anulacion -> reemision. Sin esto, create() las descartaba en silencio
        // y la orden nueva nacia sin vinculo con la que reemplaza. (2026-09-03)
        'tipo_opa',
        // La razón social propia de la orden. Solo se usa cuando NO se puede derivar de las
        // facturas: hoy, el ANTICIPO. Ver 2026_09_25_100000.
        'id_razon',
        'id_opa_reemplazada',
        'id_opa_anticipo'
    ];

    /** El ANTICIPO del que esta APLICACION consume saldo. */
    public function opaAnticipo()
    {
        return $this->hasOne(TesOrdenPagoEntity::class, 'id_orden_pago', 'id_opa_anticipo');
    }

    /** Las APLICACIONes que consumieron saldo de este ANTICIPO. */
    public function aplicaciones()
    {
        return $this->hasMany(TesOrdenPagoEntity::class, 'id_opa_anticipo', 'id_orden_pago');
    }

    /** La OP que esta reemplaza, cuando nacio de una anulacion. */
    public function opaReemplazada()
    {
        return $this->hasOne(TesOrdenPagoEntity::class, 'id_orden_pago', 'id_opa_reemplazada');
    }

    /** La OP que reemplazo a esta, si fue anulada y reemitida. */
    public function opaReemplazante()
    {
        return $this->hasOne(TesOrdenPagoEntity::class, 'id_opa_reemplazada', 'id_orden_pago');
    }

    public function estado()
    {
        return $this->hasOne(TesEstadoOrdenPagoEntity::class, 'id_estado_orden_pago', 'id_estado_orden_pago');
    }

    public function proveedor()
    {
        return $this->hasOne(MatrizProveedoresEntity::class, 'cod_proveedor', 'id_proveedor');
    }

    public function prestador()
    {
        return $this->hasOne(PrestadorEntity::class, 'cod_prestador', 'id_prestador');
    }
    
    public function pagos()
    {
        return $this->hasMany(TesPagoEntity::class, 'id_orden_pago');
    }

    public function pagoFecha()
    {
        return $this->hasOne(TesPagoEntity::class, 'id_orden_pago');
    }

    public function fechapagos()
    {
        return $this->hasOne(TesPagoEntity::class, 'id_orden_pago','id_orden_pago');
    }
    public function opadetalle()
    {
        return $this->hasMany(TesOrdenPagoDetalleEntity::class, 'id_orden_pago','id_orden_pago');
    }
}
