<?php

namespace App\Imports;

use Carbon\Carbon;
use Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Lee el export de ARCA "Mis Comprobantes Emitidos" (xlsx / xls / csv) y devuelve filas normalizadas.
 *
 * Sólo lee: no valida reglas de negocio ni escribe nada (eso lo hace TesIngComprobanteRepository).
 *
 * Las columnas se buscan por ENCABEZADO, no por posición: ARCA cambió el layout más de una vez
 * (el de "recibidos" que lee ComprobantesAfipCompraImport ya trae neto e IVA por alícuota) y todavía
 * no tenemos un export real de emitidos (pregunta 2 de la segunda ronda, README 13.2).
 */
class ComprobantesArcaEmitidosLector
{
    private const OBLIGATORIAS = ['fecha', 'tipo', 'punto_venta', 'numero_desde', 'nro_doc_receptor', 'importe_total'];

    public function leer(string $ruta): array
    {
        $hoja = IOFactory::load($ruta)->getActiveSheet()->toArray(null, true, false, false);

        [$filaEncabezado, $columnas] = $this->ubicarEncabezado($hoja);

        $faltan = array_diff(self::OBLIGATORIAS, array_keys($columnas));
        if (!empty($faltan)) {
            throw new Exception('El archivo no parece un export de "Mis Comprobantes Emitidos" de ARCA: faltan las columnas ' . implode(', ', $faltan) . '.');
        }

        $filas = [];
        foreach (array_slice($hoja, $filaEncabezado + 1, null, true) as $i => $celdas) {
            if ($this->filaVacia($celdas)) {
                continue;
            }

            $valor = fn($campo) => isset($columnas[$campo]) ? ($celdas[$columnas[$campo]] ?? null) : null;

            $filas[] = [
                // número de fila como lo ve el usuario en Excel
                'fila'                   => $i + 1,
                'fecha_emision'          => $this->fecha($valor('fecha')),
                'id_tipo_comprobante'    => $this->entero($valor('tipo')),
                'tipo_texto'             => trim((string) $valor('tipo')),
                'punto_venta'            => $this->entero($valor('punto_venta')),
                'numero'                 => $this->entero($valor('numero_desde')),
                'numero_hasta'           => $this->entero($valor('numero_hasta')),
                'cae'                    => $this->texto($valor('cae')),
                'tipo_doc_receptor'      => $this->texto($valor('tipo_doc_receptor')),
                'cuit_receptor'          => preg_replace('/\D/', '', (string) $valor('nro_doc_receptor')),
                'denominacion_receptor'  => $this->texto($valor('denominacion_receptor')),
                'moneda'                 => $this->texto($valor('moneda')),
                'importe_neto_gravado'   => $this->importe($valor('neto_gravado')),
                'importe_no_gravado'     => $this->importe($valor('no_gravado')),
                'importe_exento'         => $this->importe($valor('exento')),
                'importe_otros_tributos' => $this->importe($valor('otros_tributos')),
                'importe_iva'            => $this->importe($valor('iva')),
                'importe_total'          => $this->importe($valor('importe_total')),
            ];
        }

        return [
            'cuit_emisor' => $this->cuitDelTitulo(array_slice($hoja, 0, $filaEncabezado)),
            'filas'       => $filas,
        ];
    }

    /** La primera fila con "punto de venta" es el encabezado; arriba suele venir el título. */
    private function ubicarEncabezado(array $hoja): array
    {
        foreach (array_slice($hoja, 0, 15, true) as $i => $celdas) {
            $columnas = [];
            foreach ($celdas as $c => $texto) {
                $campo = $this->campoDeEncabezado($this->normalizar((string) $texto));
                if ($campo && !isset($columnas[$campo])) {
                    $columnas[$campo] = $c;
                }
            }
            if (isset($columnas['punto_venta'])) {
                return [$i, $columnas];
            }
        }

        throw new Exception('No se encontró la fila de encabezados (con la columna "Punto de Venta") en las primeras 15 filas del archivo.');
    }

    private function campoDeEncabezado(string $h): ?string
    {
        if ($h === '') return null;
        if (str_starts_with($h, 'fecha')) return 'fecha';
        if (str_contains($h, 'punto de venta')) return 'punto_venta';
        if (str_contains($h, 'desde')) return 'numero_desde';
        if (str_contains($h, 'hasta')) return 'numero_hasta';
        if (str_contains($h, 'autorizacion')) return 'cae';
        if (str_contains($h, 'tipo doc')) return 'tipo_doc_receptor';
        if (str_contains($h, 'doc') && (str_contains($h, 'nro') || str_contains($h, 'numero'))) return 'nro_doc_receptor';
        if (str_starts_with($h, 'denominacion')) return 'denominacion_receptor';
        if (str_starts_with($h, 'tipo cambio')) return null;
        if (str_starts_with($h, 'tipo')) return 'tipo';
        if (str_starts_with($h, 'moneda')) return 'moneda';
        if (str_contains($h, 'no gravado')) return 'no_gravado';
        // "Neto Gravado IVA 21%" es el detalle por alícuota: se usa el total
        if (str_contains($h, 'neto gravado') && !str_contains($h, 'iva')) return 'neto_gravado';
        if (str_contains($h, 'exent')) return 'exento';
        if (str_contains($h, 'otros tributos')) return 'otros_tributos';
        if ($h === 'iva' || $h === 'total iva') return 'iva';
        if (str_contains($h, 'total') && !str_contains($h, 'iva')) return 'importe_total';
        return null;
    }

    /** "Mis Comprobantes Emitidos - CUIT 30718039734" */
    private function cuitDelTitulo(array $filasTitulo): ?string
    {
        foreach ($filasTitulo as $celdas) {
            foreach ($celdas as $texto) {
                if (preg_match('/\b(\d{2})-?(\d{8})-?(\d)\b/', (string) $texto, $m)) {
                    return $m[1] . $m[2] . $m[3];
                }
            }
        }
        return null;
    }

    private function normalizar(string $texto): string
    {
        $t = mb_strtolower(trim($texto));
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $t = preg_replace('/[^a-z0-9% ]+/', ' ', $t);
        return trim(preg_replace('/\s+/', ' ', $t));
    }

    private function filaVacia(array $celdas): bool
    {
        foreach ($celdas as $c) {
            if (trim((string) $c) !== '') return false;
        }
        return true;
    }

    private function texto($v): ?string
    {
        $t = trim((string) $v);
        return $t === '' ? null : $t;
    }

    /** "6 - Factura B" → 6 */
    private function entero($v): ?int
    {
        if (is_numeric($v)) return (int) $v;
        return preg_match('/^\s*(\d+)/', (string) $v, $m) ? (int) $m[1] : null;
    }

    /** Acepta 1234.56, "1234,56" y "1.234,56". null si no es un número. */
    private function importe($v): ?float
    {
        if ($v === null || trim((string) $v) === '') return 0.0;
        if (is_numeric($v)) return round((float) $v, 2);

        $t = str_replace([' ', '$'], '', (string) $v);
        if (str_contains($t, ',')) {
            $t = str_replace(['.', ','], ['', '.'], $t);
        }
        return is_numeric($t) ? round((float) $t, 2) : null;
    }

    private function fecha($v): ?string
    {
        if ($v === null || trim((string) $v) === '') return null;

        try {
            if (is_numeric($v)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject($v))->format('Y-m-d');
            }
            foreach (['d/m/Y', 'Y-m-d', 'd-m-Y'] as $formato) {
                $f = Carbon::createFromFormat('!' . $formato, trim($v));
                if ($f && $f->format($formato) === trim($v)) {
                    return $f->format('Y-m-d');
                }
            }
        } catch (\Throwable $e) {
        }
        return null;
    }
}
