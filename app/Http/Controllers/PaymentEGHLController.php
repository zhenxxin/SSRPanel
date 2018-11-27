<?php

namespace App\Http\Controllers;

use App\Components\GHLPaymentGateway;
use App\Console\Commands\PaymentQueryEGHL;
use App\Facades\EGHL;
use App\Http\Models\Order;
use App\Http\Models\Payment;
use DB;
use Illuminate\Http\Request;
use Session;
use URL;

class PaymentEGHLController extends Controller
{
    const DEFAULT_TIMEOUT = 600; // seconds

    private $password;

    public function __construct()
    {
        $this->password = config('services.payment.eghl.merchant_password');
    }

    public function create(Request $request, $sn)
    {
        $returnUrl = URL::previous();
        $custName = $request->get('cust_name');
        $custEmail = $request->get('cust_email');
        $custPhone = $request->get('cust_phone');
        if (is_null($custName)) {
            Session::flash('errorMsg', '用户名不能为空');
            return redirect($returnUrl);
        }
        if (is_null($custEmail)) {
            Session::flash('errorMsg', '邮箱不能为空');
            return redirect($returnUrl);
        }
        if (is_null($custPhone)) {
            Session::flash('errorMsg', '手机号码不能为空');
            return redirect($returnUrl);
        }

        /** @var Payment $payment */
        $payment = Payment::query()->where(['sn' => $sn])->firstOrFail();
        if (Payment::STATUS_NEW != $payment->status) {
            Session::flash('errorMsg', '支付订单状态不正确');
            return redirect($returnUrl);
        }

        $params = [
            'TransactionType' => 'SALE',
            'PymtMethod' => 'ANY',
            'ServiceID' => EGHL::get('ServiceID'),
            'PaymentID' => $payment->sn,
            'OrderNumber' => $payment->order_sn,
            'PaymentDesc' => $payment->order_sn,
            'MerchantReturnURL' => $this->buildReturnUrl($payment->sn),
            'MerchantCallBackURL' => $this->buildReturnUrl($payment->sn),
            //'MerchantTermsURL' => '', // 展示条款信息
            'Amount' => number_format($payment->getAmountAttribute($payment->amount), 2, '.', ''),
            'CurrencyCode' => 'CNY',
            'CustIP' => getClientIP(),
            'CustName' => $custName,
            'CustEmail' => $custEmail,
            'CustPhone' => $custPhone,
            'PageTimeout' => self::DEFAULT_TIMEOUT,
        ];

        foreach ($params as $name => $value) {
            EGHL::set($name, $value);
        }
        $params['HashValue'] = EGHL::calcHash($this->password);
        $url = EGHL::get('URL') . '?' . http_build_query($params);

        return redirect($url);
    }

    public function callback(Request $request, $sn)
    {
        $url = URL::action('PaymentController@detail', ['sn' => $sn]);
        $this->receiveResponse($sn);
        return redirect($url);
    }

    private function receiveResponse($sn)
    {
        if (!EGHL::verifyRequest($this->password)) {
            Session::flash('errorMsg', '请求非法');
            return false;
        }

        // 验证通过的，如果支付结果确定，则进行余额操作，否则标记失败
        $payment = Payment::query()->where(['sn' => $sn])->firstOrFail();
        if (Payment::STATUS_NEW != $payment->status) {
            Session::flash('errorMsg', '交易状态不正确');
            return false;
        }

        $txnStatus = EGHL::get('TxnStatus');
        switch ($txnStatus) {
            case 0:
                $paymentStatus = Payment::STATUS_SUCCESS;
                $orderStatus = Order::STATUS_SUCCESS;
                break;
            case 1:
                $paymentStatus = Payment::STATUS_FAILED;
                $orderStatus = Order::STATUS_FAILED;
                break;
            case 2:
            default:
                $paymentStatus = Payment::STATUS_PROCESSING;
                $orderStatus = Order::STATUS_PENDING;
                break;
        }

        DB::beginTransaction();
        try {
            // TODO 记录 TxnID 用于对账
            $payment->status = $paymentStatus;
            $payment->save();
            $payment->order->status = $orderStatus;
            $payment->order->save();
            DB::commit();
        } catch (\Exception $exception) {
            DB::rollBack();
            Session::flash('errorMsg', '系统异常，请稍后确认支付状态');
            return false;
        }

        return true;
    }

    private function buildReturnUrl($sn)
    {
        return URL::action('PaymentEGHLController@callback', ['sn' => $sn]);
    }
}
