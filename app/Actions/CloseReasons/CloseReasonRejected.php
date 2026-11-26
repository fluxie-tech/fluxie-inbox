<?php

namespace App\Actions\CloseReasons;

use RuntimeException;

/** O pedido sobre o motivo de encerramento foi recusado. A mensagem já serve para mostrar na tela. */
class CloseReasonRejected extends RuntimeException {}
