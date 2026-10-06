# Tests obsoletos (no los corre la suite)

Se reconstruyeron desde el historial el 2026-10-06 y quedaron en una versión vieja. No se borran
porque documentan casos que se probaron; si hace falta esa cobertura, se reescriben contra el
código actual.

| Test | Por qué quedó afuera |
|---|---|
| circuito_completo, formas_mezcladas, transferencias | Usan `marcarPendienteEmision()`, que ya no existe: el eCheq se emite desde el modal de pago (2026-09-12). Cubiertos hoy por `test_emitir_desde_el_modal`. |
| comprobante_op, echeq, echeq_export, fifo, reemplazo | Usan `crearInstrumentos()`, que ya no existe (mismo cambio). FIFO lo cubren `test_cc_dos_saldos` y `test_cuenta_corriente`. |
| estado_opa_y_totales_comprobante | Acredita un eCheq que no entró en un pago confirmado: desde el 2026-09-10 eso está prohibido a propósito. |
| print_pendiente | Espera el sello "PENDIENTE DE EMISIÓN", que se sacó a pedido (lo verifica `test_opa_con_comprobantes`). |
| abonos_anulados_ocultos | La reconstrucción quedó incompleta (variable sin definir). |
| eliminar_factura, fix_asientos, opa_4417_caso_real | Pruebas de una sola vez sobre un caso o arreglo puntual. |
