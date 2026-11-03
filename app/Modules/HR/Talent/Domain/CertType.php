<?php

namespace App\Modules\HR\Talent\Domain;

class CertType extends GroupModel
{
    protected $table = 'tal_cert_types';

    protected function casts(): array
    {
        return ['mandatory' => 'boolean'];
    }
}
