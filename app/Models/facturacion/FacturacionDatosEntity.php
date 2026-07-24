<?php

namespace App\Models\facturacion;

use App\Models\configuracion\RazonSocialModelo;
use App\Models\Contabilidad\AsientosFacturacionHistorialEntity;
use App\Models\LocatorioModelos;
use App\Models\prestadores\PrestadorEntity;
use App\Models\proveedor\MatrizProveedoresEntity;
use App\Models\SindicatosModelo;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacturacionDatosEntity extends Model
{
    use HasFactory;
    protected $table = 'tb_facturacion_datos';
    protected $primaryKey = 'id_factura';
    public $timestamps = false;

    protected $fillable = [
        'id_tipo_factura',
        'cod_sindicato',
        'id_tipo_comprobante',
        'fecha_comprobante',
        'id_proveedor',
        'periodo',
        'tipo_letra',
        'fecha_vencimiento',
        'sucursal',
        'numero',
        'fecha_registra',
        'fecha_actualiza',
        'cod_usuario',
        'estado',
        'cae_cai',
        'id_tipo_imputacion_sintetizada',
        'archivo',
        'total_debitado_liquidacion',
        'id_prestador',
        'subtotal',
        'total_iva',
        'total_neto',
        'num_liquidacion',
        'refacturacion',
        'num_lote',
        'total_facturado',
        'total_aprobado',
        'total_debitado',
        'id_locatorio',
        'tipo_carga_detalle',
        'total_facturado_liquidacion',
        'total_aprobado_liquidacion',
        'estado_pago',
        'observaciones_resumen',
        'comprobante_relacionado',
        'tipo_hospital',
        'cod_hospital',
    ];

    public function detalle()
    {
        return $this->hasMany(FacturacionDetalleEntity::class, 'id_factura', 'id_factura');
    }

    public function impuesto()
    {
        return $this->hasMany(FacturacionDetalleImpuestoEntity::class, 'id_factura', 'id_factura');
    }

    public function descuentos()
    {
        return $this->hasMany(FacturacionDetalleDescuentoEntity::class, 'id_factura', 'id_factura');
    }

    public function tipoFactura()
    {
        return $this->hasOne(TipoFacturacionEntity::class, 'id_tipo_factura', 'id_tipo_factura');
    }

    public function filial()
    {
        return $this->hasOne(LocatorioModelos::class, 'id_locatorio', 'cod_sindicato');
    }

    public function tipoComprobante()
    {
        return $this->hasOne(TipoComprobanteFacturacionEntity::class, 'id_tipo_comprobante', 'id_tipo_comprobante');
    }
    public function tipoImputacion()
    {
        return $this->hasOne(FacturacionTipoImputacionSintetizadaEntity::class, 'id_tipo_imputacion_sintetizada', 'id_tipo_imputacion_sintetizada');
    }

    public function proveedor()
    {
        return $this->hasOne(MatrizProveedoresEntity::class, 'cod_proveedor', 'id_proveedor');
    }

    public function prestador()
    {
        return $this->hasOne(PrestadorEntity::class, 'cod_prestador', 'id_prestador');
    }
    public function comprobantes()
    {
        return $this->hasMany(FacturacionDetalleComprobantesEntity::class, 'id_factura', 'id_factura');
    }

    public function razonSocial()
    {
        return $this->hasOne(RazonSocialModelo::class, 'id_razon', 'id_locatorio');
    }

    public function opa()
    {
        return $this->hasOne(TesOrdenPagoEntity::class, 'id_factura');
    }

    public function historialAsientos()
    {
        return $this->hasMany(AsientosFacturacionHistorialEntity::class, 'id_factura', 'id_factura');
    }
}
