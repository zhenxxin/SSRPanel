<?php

namespace App\Http\Controllers;

use App\Facades\EGHL;
use App\Http\Models\Payment;
use Illuminate\Http\Request;
use URL;

class PaymentEGHLController extends Controller
{
    private $merchantPassword;

    public function __construct()
    {
        $this->merchantPassword = config('services.payment.eghl.merchant_password');
    }

    public function create(Request $request, $sn)
    {
        $custName = $request->get('cust_name');
        $custEmail = $request->get('cust_email');
        $custPhone = $request->get('cust_phone');

        /** @var Payment $payment */
        $payment = Payment::query()->where(['sn' => $sn, 'status' => 0])->firstOrFail();

        $params = [
            'TransactionType' => 'SALE',
            'PymtMethod' => 'ANY',
            'ServiceID' => EGHL::get('ServiceID'),
            'PaymentID' => $payment->sn,
            'OrderNumber' => $payment->order_sn,
            'PaymentDesc' => $payment->order_sn,
            'MerchantReturnURL' => URL::action('PaymentEGHLController@redirect', ['sn' => $payment->sn]),
            'MerchantCallBackURL' => URL::action('PaymentEGHLController@callback', ['sn' => $payment->sn]),
            //'MerchantTermsURL' => '', // 展示条款信息
            'Amount' => number_format($payment->getAmountAttribute($payment->amount), 2, '.', ''),
            'CurrencyCode' => 'CNY',
            'CustIP' => getClientIP(),
            'CustName' => $custName,
            'CustEmail' => $custEmail,
            'CustPhone' => $custPhone,
        ];

        foreach ($params as $name => $value) {
            EGHL::set($name, $value);
        }
        $params['HashValue'] = EGHL::calcHash($this->merchantPassword);
        $url = EGHL::get('URL') . '?' . http_build_query($params);

        return redirect($url);
    }

    public function redirect(Request $request, $sn)
    {
        EGHL::getValuesFromRequest();
    }

    public function callback(Request $request, $sn)
    {
        EGHL::getValuesFromRequest();
    }
}
