<?php

namespace App\Services\AutoClose;

/** O que fazer com um chamado parado num instante: avisar o cliente, encerrar ou deixar como está. */
enum AutoCloseStep
{
    case WARN;
    case CLOSE;
    case NONE;
}
