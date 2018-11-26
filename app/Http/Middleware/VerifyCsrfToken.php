<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as BaseVerifier;

class VerifyCsrfToken extends BaseVerifier
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        "payment/*",
        'payment-eghl/redirect/*', 'payment-eghl/callback/*', // 回调和跳转，不需要检查
    ];
}
