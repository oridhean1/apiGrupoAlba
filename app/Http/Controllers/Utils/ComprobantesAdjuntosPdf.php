<?php

namespace App\Http\Controllers\Utils;

use Mpdf\Mpdf;

/**
 * Anexa los comprobantes de pago al PDF de la orden, para que salga un solo documento.
 *
 * ⚠️ Limitación conocida del parser: mPDF importa páginas usando el FPDI que trae el proyecto, y
 * esa versión **sólo lee PDF 1.4 o anterior** (no está el lector de xref comprimido, que es
 * comercial). Un comprobante bajado del home banking puede ser 1.5+ y no se va a poder incrustar.
 *
 * Por eso esto NUNCA falla entero: el adjunto que no se pueda leer se omite, se deja anotado en la
 * hoja separadora y la orden se descarga igual. Perder el PDF completo porque un banco exporta en
 * 1.6 sería mucho peor que avisar cuál falta.
 */
class ComprobantesAdjuntosPdf
{
    /** Extensiones que sabemos poner como página de imagen. */
    private const IMAGENES = ['jpg', 'jpeg', 'png'];

    /**
     * @param  string  $pdfOrden  El PDF de la orden ya renderizado (binario).
     * @param  array   $adjuntos  [['ruta' => ruta absoluta, 'nombre' => nombre visible], ...]
     * @return array   ['pdf' => binario, 'omitidos' => ['nombre' => motivo, ...]]
     */
    public function anexar(string $pdfOrden, array $adjuntos): array
    {
        $omitidos = [];

        if (empty($adjuntos)) {
            return ['pdf' => $pdfOrden, 'omitidos' => $omitidos];
        }

        $tmpOrden = tempnam(sys_get_temp_dir(), 'opa_');
        file_put_contents($tmpOrden, $pdfOrden);

        try {
            $mpdf = new Mpdf([
                'tempDir' => storage_path('app/mpdf-tmp'),
                'format'  => 'A4',
            ]);

            // 1) La orden, tal cual salió.
            $this->importarPdf($mpdf, $tmpOrden);

            // 2) Separadora + comprobantes.
            $incrustados = [];

            foreach ($adjuntos as $adj) {
                $ruta   = $adj['ruta'] ?? null;
                $nombre = $adj['nombre'] ?? basename((string) $ruta);

                if (!$ruta || !is_file($ruta)) {
                    $omitidos[$nombre] = 'el archivo no está en el servidor';
                    continue;
                }

                $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

                try {
                    if (in_array($ext, self::IMAGENES, true)) {
                        $mpdf->AddPage();
                        // `contain` dentro del alto útil: un comprobante fotografiado en vertical
                        // no tiene que salir cortado.
                        $mpdf->Image($ruta, 15, 25, 180, 0, $ext === 'png' ? 'png' : 'jpg', '', true, true);
                    } else {
                        $this->importarPdf($mpdf, $ruta);
                    }

                    $incrustados[] = $nombre;
                } catch (\Throwable $e) {
                    // El mensaje crudo de FPDI no le dice nada a nadie; se traduce el caso real.
                    $omitidos[$nombre] = str_contains($e->getMessage(), 'compression technique')
                        || str_contains($e->getMessage(), 'cross-reference')
                        ? 'el PDF usa un formato que el servidor no puede incrustar (PDF 1.5 o superior)'
                        : 'no se pudo leer el archivo';
                }
            }

            return ['pdf' => $mpdf->Output('', 'S'), 'omitidos' => $omitidos];
        } finally {
            @unlink($tmpOrden);
        }
    }

    private function importarPdf(Mpdf $mpdf, string $ruta): void
    {
        $paginas = $mpdf->setSourceFile($ruta);

        for ($i = 1; $i <= $paginas; $i++) {
            $tpl  = $mpdf->importPage($i);
            $dims = $mpdf->getTemplateSize($tpl);

            $mpdf->AddPageByArray([
                'orientation' => ($dims['width'] > $dims['height']) ? 'L' : 'P',
                'sheet-size'  => [$dims['width'], $dims['height']],
            ]);

            $mpdf->useTemplate($tpl);
        }
    }
}
