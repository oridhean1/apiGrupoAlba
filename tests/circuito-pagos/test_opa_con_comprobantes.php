<?php
// La orden se descarga con los comprobantes de pago anexados, en un solo PDF.
// Y si un adjunto no se puede incrustar (PDF 1.5+, archivo faltante), la descarga NO se cae.
use App\Http\Controllers\Utils\ComprobantesAdjuntosPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";
$r = [];
$tmp = sys_get_temp_dir() . '/test_comp_' . getmypid();
@mkdir($tmp, 0777, true);

$paginas = function (string $pdf): int {
    // Contar objetos /Type /Page (no /Pages) alcanza para este chequeo.
    return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
};

try {
    $anexador = new ComprobantesAdjuntosPdf();

    // Orden: 1 pagina. Adjunto PDF legible: 2 paginas.
    $orden   = Pdf::loadHTML('<h1>ORDEN DE PAGO</h1><p>cuerpo</p>')->setPaper('A4')->output();
    $adj2pag = Pdf::loadHTML('<h1>COMPROBANTE 1</h1><div style="page-break-after:always"></div><h1>pag 2</h1>')->output();
    file_put_contents("$tmp/comp1.pdf", $adj2pag);

    // Imagen: un JPEG minimo real (1x1).
    file_put_contents("$tmp/comp2.jpg", base64_decode(
        '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
        . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
        . 'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='
    ));

    echo "--- 1: la orden sale con el PDF y la imagen anexados ---\n";
    $res = $anexador->anexar($orden, [
        ['ruta' => "$tmp/comp1.pdf", 'nombre' => 'comp1.pdf'],
        ['ruta' => "$tmp/comp2.jpg", 'nombre' => 'comp2.jpg'],
    ]);
    $n = $paginas($res['pdf']);
    echo "  paginas del PDF final: {$n} (esperado 4: 1 orden + 2 del pdf + 1 imagen)\n";
    echo "  omitidos: " . (count($res['omitidos']) ?: 'ninguno') . "\n";
    $r[] = ($n === 4 && empty($res['omitidos']));
    echo $ok(end($r));

    echo "--- 2: un adjunto que no existe se omite, la orden sale igual ---\n";
    $res = $anexador->anexar($orden, [['ruta' => "$tmp/no-existe.pdf", 'nombre' => 'no-existe.pdf']]);
    echo "  motivo: " . ($res['omitidos']['no-existe.pdf'] ?? '(no reporto)') . "\n";
    echo "  paginas: " . $paginas($res['pdf']) . " (la orden sigue estando)\n";
    $r[] = (str_contains($res['omitidos']['no-existe.pdf'] ?? '', 'no está en el servidor')
        && $paginas($res['pdf']) >= 1);
    echo $ok(end($r));

    echo "--- 3: un PDF ilegible NO tira abajo la descarga ---\n";
    // Un archivo que dice ser PDF pero no lo es: el mismo camino que un 1.5+ no soportado.
    file_put_contents("$tmp/roto.pdf", "%PDF-1.7\nbasura que no es un pdf\n");
    $res = $anexador->anexar($orden, [
        ['ruta' => "$tmp/roto.pdf",  'nombre' => 'roto.pdf'],
        ['ruta' => "$tmp/comp1.pdf", 'nombre' => 'comp1.pdf'],
    ]);
    echo "  omitido: roto.pdf -> " . ($res['omitidos']['roto.pdf'] ?? '(no reporto)') . "\n";
    echo "  paginas: " . $paginas($res['pdf']) . " (esperado 3: la orden + el adjunto bueno)\n";
    $r[] = (isset($res['omitidos']['roto.pdf']) && $paginas($res['pdf']) === 3);
    echo $ok(end($r));

    echo "--- 4: sin adjuntos devuelve el PDF original intacto ---\n";
    $res = $anexador->anexar($orden, []);
    $r[] = ($res['pdf'] === $orden && empty($res['omitidos']));
    echo "  identico al original: " . var_export($res['pdf'] === $orden, true) . "\n";
    echo $ok(end($r));

    echo "--- 5: el comprobante ya no lleva el sello de uso interno ---\n";
    // Sin los comentarios de Blade: uno explica que "acá iba el sello", y no se imprime.
    $blade = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents(resource_path('views/orden_pago.blade.php')));
    $sello = str_contains($blade, 'PENDIENTE DE EMISION') || str_contains($blade, 'version_comprobante');
    echo "  quedan rastros del sello en la plantilla: " . var_export($sello, true) . " (esperado false)\n";
    $r[] = !$sello;
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION: {$e->getMessage()}\n  {$e->getFile()}:{$e->getLine()}\n"; $r[] = false;
} finally {
    array_map('unlink', glob("$tmp/*") ?: []);
    @rmdir($tmp);
    $c = count(array_filter($r));
    echo "\n=== {$c}/" . count($r) . " OK " . ($c === count($r) ? '' : '<<< HAY FALLAS') . "\n";
}
