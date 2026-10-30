<?php

namespace App\Models\Contabilidad;

use App\Models\configuracion\RazonSocialModelo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuenta contable de contraparte (prestadores / proveedores) por razón social.
 * Reemplaza el hardcodeo 35/36 del asiento de factura (HABER) y de pago (DEBE).
 */
class RazonCuentaContableEntity extends Model
{
    use HasFactory;

    protected $table = 'tb_cont_razon_cuenta_contable';
    protected $primaryKey = 'id_razon_cuenta_contable';
    public $timestamps = false;

    const TIPO_PRESTADOR = 'PRESTADOR';
    const TIPO_PROVEEDOR = 'PROVEEDOR';

    // Circuito de ingresos (R-00000630): cuentas de venta por razón social.
    const TIPO_VENTA_DEUDOR = 'VENTA_DEUDOR';
    const TIPO_VENTA_INGRESO = 'VENTA_INGRESO';
    const TIPO_VENTA_IVA = 'VENTA_IVA';
    const TIPO_VENTA_RET_GANANCIAS = 'VENTA_RET_GANANCIAS';
    const TIPO_VENTA_ANTICIPO = 'VENTA_ANTICIPO';

    protected $fillable = [
        'id_razon',
        'tipo_contraparte',
        'id_detalle_plan',
        'vigente',
        'cod_usuario',
        'fecha_registra',
        'cod_usuario_modifica',
        'fecha_modifica'
    ];

    public function razonSocial()
    {
        return $this->hasOne(RazonSocialModelo::class, 'id_razon', 'id_razon');
    }

    public function detallePlan()
    {
        return $this->hasOne(DetallePlanCuentasEntity::class, 'id_detalle_plan', 'id_detalle_plan');
    }
}
