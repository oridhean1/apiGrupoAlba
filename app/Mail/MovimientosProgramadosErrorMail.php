<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MovimientosProgramadosErrorMail extends Mailable
{
    use Queueable, SerializesModels;

    public $errores;

    public function __construct($errores)
    {
        $this->errores = $errores;
    }

    public function build()
    {
        return $this->subject('Traspasos de Origen programados con error')
            ->view('movimientos_programados_error');
    }
}
