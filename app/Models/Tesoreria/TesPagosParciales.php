<?php

namespace App\Models\Tesoreria;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TesPagosParciales extends Model
{
    use HasFactory;

    protected $table = 'tb_tes_pago_parcial';
    protected $primaryKey = 'id_pago_parcial';
    public $timestamps = false;

    protected $fillable = [
        'fecha_registra',
        'fecha_confirma_pago',
        // Cuando este abono entro en un pago CONFIRMADO. Sin esto, un eCheq emitido
        // despues de que la boleta ya estaba confirmada heredaba el permiso de la boleta
        // y se podia acreditar sin pasar nunca por Confirmar Pago. Ver 2026_09_10_100000.
        'fecha_confirmado_en_pago',
        'id_forma_pago',
        'monto_pago',
        'id_usuario',
        'monto_opa',
        'num_cheque',
        'id_pago',
        // Vinculo con la fecha del cronograma que planifico este pago. Ver 2026_09_04_101000.
        'id_fecha_probable',
        'monto_restante',
        // Ciclo de vida del instrumento (eCheq). Cada abono ES un cheque: por eso estas
        // columnas viven aca y no en la boleta. Ver 2026_09_04_100900. Sin el fillable,
        // create() las descarta en silencio -- ya paso dos veces en esta fase.
        'id_estado_instrumento',
        'numero_echeq',
        // El numero lo puso el sistema para no frenar la carga del pago, y hay que reemplazarlo
        // por el que asigne el banco. Ver 2026_09_15_100000.
        'numero_provisorio',
        'fecha_emision_echeq',
        'id_banco_emisor',
        // Cuenta de origen del pago. Vivia en la boleta (una sola para toda la orden), lo que
        // impedia pagar una misma orden desde dos bancos distintos. Ver 2026_09_06_100000.
        'id_cuenta_bancaria',
        'motivo_rechazo',
        'fecha_rechazo',
    ];

    protected $casts = [
        // Sin el cast, MySQL devuelve '0'/'1' como string y '0' es truthy en una comparacion
        // suelta del front. Un provisorio tomado por real termina impreso en un comprobante.
        'numero_provisorio' => 'boolean',
    ];

    /**
     * Abonos VIVOS: los que efectivamente representan plata.
     *
     * Un abono RECHAZADO (5) o ANULADO (6) no cuenta para nada — esa plata no salió, o volvió.
     * El resto del sistema ya aplicaba esta exclusión a mano en cada consulta (el tope de
     * emisión, lo pagado de la orden, los listados de eCheq); acá queda en un solo lugar para
     * que las relaciones también la respeten.
     *
     * Los pagos del circuito VIEJO tienen `id_estado_instrumento` en NULL y siguen contando: son
     * anteriores al ciclo de vida del instrumento, no son abonos muertos.
     *
     * Se agregó el 2026-09-07: después de anular dos eCheq de la OPA-4284, el modal de Confirmar
     * Pago los seguía listando como abonos, y el comprobante PDF también los imprimía.
     */
    public function scopeVivos($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('id_estado_instrumento')
                ->orWhereNotIn('id_estado_instrumento', [
                    \App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository::RECHAZADO,
                    \App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository::ANULADO,
                ]);
        });
    }

    public function formaPago()
    {
        return $this->hasOne(TesTipoFormasPagoEntity::class, 'id_forma_pago', 'id_forma_pago');
    }

    /** La boleta de pago a la que pertenece este abono. */
    public function pago()
    {
        return $this->hasOne(TesPagoEntity::class, 'id_pago', 'id_pago');
    }

    public function estadoInstrumento()
    {
        return $this->hasOne(TesEstadoInstrumentoEntity::class, 'id_estado_instrumento', 'id_estado_instrumento');
    }

    public function bancoEmisor()
    {
        return $this->hasOne(TesEntidadesBancariasEntity::class, 'id_entidad_bancaria', 'id_banco_emisor');
    }

    /** Cuenta de origen de ESTE pago — no la de la boleta. Ver 2026_09_06_100000. */
    public function cuentaBancaria()
    {
        return $this->hasOne(TesCuentasBancariasEntity::class, 'id_cuenta_bancaria', 'id_cuenta_bancaria');
    }
}
