<?php

namespace App\Http\Controllers\Tesoreria\Repository;

/**
 * Conceptos que el banco debita/acredita y que no nacen en Tesorería (R-00000689 E5, P2 y P5):
 * gastos e impuestos bancarios, sueldos, FCI, seguros y traspasos entre cuentas propias. Se
 * registran desde el Visor de Conciliación con su asiento contable.
 *
 * La cuenta contable de cada concepto es POR RAZÓN SOCIAL y vive en
 * `tb_cont_razon_cuenta_contable` (`tipo_contraparte` = `tipo`, varchar 20). La define Contabilidad:
 * sin esa fila, registrar el concepto corta. El traspaso no lleva cuenta propia: es banco contra banco.
 *
 * `patron`: regex sobre el concepto del extracto, para sugerir el concepto. El ORDEN importa (gana
 * el primero): "Percep. Iva" tiene que caer en percepción antes que en IVA.
 * `sentido`: EGRESO (débito), INGRESO (crédito) o AMBOS.
 */
final class ConceptosBancarios
{
    public const TRASPASO = 'TRASPASO';

    public const CATALOGO = [
        'PERCEP_IVA'   => ['descripcion' => 'Percepción de IVA',                    'tipo' => 'BCO_PERCEP_IVA',   'sentido' => 'EGRESO', 'patron' => '/PERCEP/i'],
        'IMP_DEB_CRED' => ['descripcion' => 'Impuesto a los débitos y créditos',    'tipo' => 'BCO_IMP_DEB_CRED', 'sentido' => 'AMBOS',  'patron' => '/25413|IMP\.?\s*S\/?\s*(DEB|CRED)|IMP\.?\s*DEB|GRAVAMEN/i'],
        'SIRCREB'      => ['descripcion' => 'SIRCREB',                              'tipo' => 'BCO_SIRCREB',      'sentido' => 'AMBOS',  'patron' => '/SIRCREB/i'],
        'RET_IIBB'     => ['descripcion' => 'Retención de Ingresos Brutos',         'tipo' => 'BCO_RET_IIBB',     'sentido' => 'AMBOS',  'patron' => '/(RET|RETENCION).*(IIBB|ING\.?\s*BRUTOS)/i'],
        'SELLOS'       => ['descripcion' => 'Impuesto de sellos',                   'tipo' => 'BCO_SELLOS',       'sentido' => 'EGRESO', 'patron' => '/SELLOS/i'],
        'IVA'          => ['descripcion' => 'IVA de comisiones bancarias',          'tipo' => 'BCO_IVA',          'sentido' => 'EGRESO', 'patron' => '/^\s*I\s*\.?\s*V\s*\.?\s*A\b/i'],
        'INTERESES'    => ['descripcion' => 'Intereses',                            'tipo' => 'BCO_INTERESES',    'sentido' => 'AMBOS',  'patron' => '/INTERES/i'],
        'COMISION'     => ['descripcion' => 'Comisiones y gastos bancarios',        'tipo' => 'BCO_COMISION',     'sentido' => 'AMBOS',  'patron' => '/COMISION|CARGO\s+POR|^\s*COM\b|MANTENIMIENTO/i'],
        'SUELDOS'      => ['descripcion' => 'Sueldos (haberes)',                    'tipo' => 'BCO_SUELDOS',      'sentido' => 'EGRESO', 'patron' => '/HABERES|SUELDO/i'],
        'FCI'          => ['descripcion' => 'Fondo común de inversión (suscripción / rescate)', 'tipo' => 'BCO_FCI', 'sentido' => 'AMBOS', 'patron' => '/\bFCI\b|SUSC|RESC/i'],
        'SEGUROS'      => ['descripcion' => 'Seguros',                              'tipo' => 'BCO_SEGUROS',      'sentido' => 'EGRESO', 'patron' => '/SEGURO/i'],
        self::TRASPASO => ['descripcion' => 'Transferencia entre cuentas propias',  'tipo' => null,               'sentido' => 'AMBOS',  'patron' => '/MISMA\s+TITULARIDAD|CUENTAS?\s+PROPIAS?/i'],
    ];

    public static function existe(string $codigo): bool
    {
        return isset(self::CATALOGO[$codigo]);
    }

    public static function get(string $codigo): array
    {
        if (!self::existe($codigo)) {
            throw new \Exception("Concepto bancario desconocido: {$codigo}.");
        }
        return self::CATALOGO[$codigo] + ['codigo' => $codigo];
    }

    /** Concepto sugerido para el texto del extracto y el sentido (EGRESO/INGRESO), o null. */
    public static function sugerir(?string $concepto, ?string $sentido): ?string
    {
        $texto = (string) $concepto;
        if (trim($texto) === '') {
            return null;
        }
        foreach (self::CATALOGO as $codigo => $c) {
            if (($c['sentido'] === 'AMBOS' || $c['sentido'] === $sentido) && preg_match($c['patron'], $texto)) {
                return $codigo;
            }
        }
        return null;
    }

    public static function admiteSentido(string $codigo, string $sentido): bool
    {
        $s = self::get($codigo)['sentido'];
        return $s === 'AMBOS' || $s === $sentido;
    }
}
