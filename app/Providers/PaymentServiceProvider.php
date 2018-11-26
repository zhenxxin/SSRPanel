<?php

namespace App\Providers;

use App\Components\GHLPaymentGateway;
use Illuminate\Support\ServiceProvider;
use Paymentwall_Config;

class PaymentServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        // 注册其他的支付工具
        $this->app->singleton('payment.eghl', function () {
            $instance = new GHLPaymentGateway(config('services.payment.eghl.host'));
            $instance->set('ServiceID', config('services.payment.eghl.merchant_id'));
            return $instance;
        });
    }

    public function provides()
    {
        return ['payment.eghl'];
    }
}
