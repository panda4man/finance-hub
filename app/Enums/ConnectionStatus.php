<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case Active = 'active';
    case LoginRequired = 'login_required';
    case PendingExpiration = 'pending_expiration';
    case Revoked = 'revoked';
    case Error = 'error';
}
