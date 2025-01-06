<?php

namespace RachidLaasri\LaravelInstaller\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InstallationCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct()
    {
        // Se pueden pasar datos aquí si es necesario.
    }
}
