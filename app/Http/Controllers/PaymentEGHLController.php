<?php

namespace App\Http\Controllers;

use App\Facades\EGHL;
use App\Http\Models\Order;
use App\Http\Models\Payment;
use App\Http\Models\User;
use DB;
use Exception;
use Illuminate\Http\Request;
use Log;
use Redirect;
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
        $custName = $request->get('cust_name');
        $custEmail = $request->get('cust_email');
        $custPhone = $request->get('cust_phone');
        if (is_null($custName)) {
            Session::flash('errorMsg', '用户名不能为空');
            return Redirect::back()->withInput();
        }
        if (is_null($custEmail)) {
            Session::flash('errorMsg', '邮箱不能为空');
            return Redirect::back()->withInput();
        }
        if (is_null($custPhone)) {
            Session::flash('errorMsg', '手机号码不能为空');
            return Redirect::back()->withInput();
        }

        /** @var Payment $payment */
        $payment = Payment::query()->where(['sn' => $sn])->firstOrFail();
        if (Payment::STATUS_NEW != $payment->status) {
            Session::flash('errorMsg', '支付订单状态不正确');
            return Redirect::back()->withInput();
        }

        if (Payment::PAY_WAY_EGHL != $payment->pay_way) {
            Session::flash('errorMsg', '支付通道不被支持');
            return Redirect::back()->withInput();
        }

        $params = [
            'TransactionType' => 'SALE',
            'PymtMethod' => 'ANY',
            'ServiceID' => EGHL::get('ServiceID'),
            'PaymentID' => $payment->sn,
            'OrderNumber' => $payment->order_sn,
            'PaymentDesc' => $payment->order_sn,
            'MerchantReturnURL' => $this->buildRedirectUrl($payment->sn),
            'MerchantCallBackURL' => $this->buildCallbackUrl($payment->sn),
            //'MerchantTermsURL' => '', // 展示条款信息
            'Amount' => $payment->amount, // 这里对于人民币，最小单位是 元（Model 层处理），请留意 EGHL 内部完成了格式化
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

    public function redirect(Request $request, $sn)
    {
        $url = URL::action('PaymentController@detail', ['sn' => $sn]);
        try {
            $this->receiveResponse($sn);
        } catch (Exception $exception) {
            Log::warning("处理${sn}失败，原因：" . $exception->getMessage());
        }
        return redirect($url);
    }

    public function callback(Request $request, $sn)
    {
        $url = URL::action('PaymentController@detail', ['sn' => $sn]);
        try {
            if ($this->receiveResponse($sn)) {
                return 'OK';
            }
        } catch (Exception $exception) {
            Log::warning("处理${sn}失败，原因：" . $exception->getMessage());
        }

        return redirect($url);
    }

    /**
     * @param $sn
     * @return bool
     * @throws Exception
     */
    private function receiveResponse($sn)
    {
        $head = "接受交易(${sn})的返回结果，";
        Log::debug($head . '开始，原始参数为：' . json_encode($_REQUEST));
        if (!EGHL::verifyRequest($this->password)) {
            Log::info($head . "处理失败，校验失败");
            Session::flash('errorMsg', '请求非法');
            return false;
        }

        // 验证通过的，如果支付结果确定，则进行余额操作，否则标记失败
        $payment = Payment::query()->where(['sn' => $sn])->firstOrFail();
        if (Payment::STATUS_NEW != $payment->status) {
            Log::info($head . "处理失败，状态不正常或已处理");
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
            $order = $payment->order;
            $user = $order->user;

            // TODO 记录 TxnID 用于对账
            $payment->status = $paymentStatus;
            $payment->save();
            $order->status = $orderStatus;
            $order->save();

            Log::debug($head . "处理成功，payment:${paymentStatus}, order: ${orderStatus}");
            if (Order::STATUS_SUCCESS == $orderStatus) {
                $updated = User::query()
                    ->where(['id' => $payment->user_id, 'updated_at' => $user->updated_at])
                    ->increment('balance', $order->amount * 100); // 这里要将单位换算一下
                if (1 != $updated) {
                    throw new Exception("更新账户余额失败，记录数不是预期值");
                }
            }
            DB::commit();
        } catch (Exception $exception) {
            DB::rollBack();
            $message = $exception->getMessage();
            Log::warning($head ."处理失败，原因：${message}");
            Session::flash('errorMsg', '系统异常，请稍后确认支付状态');
            return false;
        }

        return true;
    }

    private function buildRedirectUrl($sn)
    {
        return URL::action('PaymentEGHLController@redirect', ['sn' => $sn]);
    }

    private function buildCallbackUrl($sn)
    {
        return URL::action('PaymentEGHLController@callback', ['sn' => $sn]);
    }
}
