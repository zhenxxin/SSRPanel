<?php

namespace App\Http\Controllers;

use App\Components\Helpers;
use App\Components\Yzy;
use App\Http\Models\Coupon;
use App\Http\Models\Goods;
use App\Http\Models\Order;
use App\Http\Models\Payment;
use App\Http\Models\PaymentCallback;
use Exception;
use UnexpectedValueException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Response;
use Redirect;
use Session;
use Log;
use DB;
use Auth;

/**
 * 支付控制器
 *
 * Class PaymentController
 *
 * @package App\Http\Controllers
 */
class PaymentController extends Controller
{
    protected static $systemConfig;

    function __construct()
    {
        self::$systemConfig = Helpers::systemConfig();
    }

    // 创建支付单
    public function create(Request $request)
    {
        $goods_id = intval($request->get('goods_id'));
        $coupon_sn = $request->get('coupon_sn');
        $pay_way = $request->get('pay_way');

        $goods = Goods::query()->where('is_del', 0)->where('status', 1)->where('id', $goods_id)->first();
        if (!$goods) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：商品或服务已下架']);
        }

        // 判断是否存在同个商品的未支付订单
        $existsOrder = Order::query()->where('status', 0)->where('user_id', Auth::user()->id)->where('goods_id', $goods_id)->exists();
        if ($existsOrder) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：尚有未支付的订单，请先去支付']);
        }

        // 限购控制
        $strategy = self::$systemConfig['goods_purchase_limit_strategy'];
        if ($strategy == 'all' || ($strategy == 'package' && $goods->type == 2) || ($strategy == 'free' && $goods->price == 0) || ($strategy == 'package&free' && ($goods->type == 2 || $goods->price == 0))) {
            $noneExpireOrderExist = Order::query()->where('status', '>=', 0)->where('is_expire', 0)->where('user_id', Auth::user()->id)->where('goods_id', $goods_id)->exists();
            if ($noneExpireOrderExist) {
                return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：商品不可重复购买']);
            }
        }

        // 单个商品限购
        if ($goods->is_limit == 1) {
            $noneExpireOrderExist = Order::query()->where('status', '>=', 0)->where('user_id', Auth::user()->id)->where('goods_id', $goods_id)->exists();
            if ($noneExpireOrderExist) {
                return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：此商品每人限购1次']);
            }
        }

        // 使用优惠券
        if ($coupon_sn) {
            $coupon = Coupon::query()->where('status', 0)->where('is_del', 0)->whereIn('type', [1, 2])->where('sn', $coupon_sn)->first();
            if (!$coupon) {
                return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：优惠券不存在']);
            }

            // 计算实际应支付总价
            $amount = $coupon->type == 2 ? $goods->price * $coupon->discount / 10 : $goods->price - $coupon->amount;
            $amount = $amount > 0 ? $amount : 0;
        } else {
            $amount = $goods->price;
        }

        // 价格异常判断
        if ($amount < 0) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：订单总价异常']);
        } elseif ($amount == 0) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：订单总价为0，无需使用在线支付']);
        }

        // 验证账号是否存在有效期更长的套餐
        if ($goods->type == 2) {
            $existOrderList = Order::query()
                ->with(['goods'])
                ->whereHas('goods', function ($q) {
                    $q->where('type', 2);
                })
                ->where('user_id', Auth::user()->id)
                ->where('is_expire', 0)
                ->where('status', 2)
                ->get();

            foreach ($existOrderList as $vo) {
                if ($vo->goods->days > $goods->days) {
                    return Response::json(['status' => 'fail', 'data' => '', 'message' => '支付失败：您已存在有效期更长的套餐，只能购买流量包']);
                }
            }
        }

        DB::beginTransaction();
        try {
            $orderSn = date('ymdHis') . mt_rand(100000, 999999);
            $sn = makeRandStr(12);

            // 生成订单
            $order = new Order();
            $order->order_sn = $orderSn;
            $order->user_id = Auth::user()->id;
            $order->goods_id = $goods->id;
            $order->coupon_id = !empty($coupon) ? $coupon->id : 0;
            $order->origin_amount = $goods->price;
            $order->amount = $amount;
            $order->expire_at = date("Y-m-d H:i:s", strtotime("+" . $goods->days . " days"));
            $order->is_expire = 0;
            $order->pay_way = $pay_way != 1 ? 2 : 1; // 1 - 余额；2 - 在线支付
            $order->status = 0;
            $order->save();

            // 同时生成支付订单
            $payment = new Payment();
            $payment->sn = $sn;
            $payment->user_id = Auth::user()->id;
            $payment->oid = $order->oid;
            $payment->order_sn = $orderSn;
            $payment->pay_way = $pay_way;
            $payment->amount = $amount;
            $payment->status = 0;
            $payment->save();

            // 优惠券置为已使用
            if (!empty($coupon)) {
                if ($coupon->usage == 1) {
                    $coupon->status = 1;
                    $coupon->save();
                }

                Helpers::addCouponLog($coupon->id, $goods->id, $order->oid, '在线支付使用');
            }

            switch ($pay_way) {
                case Payment::PAY_WAY_BALANCE:
                    $this->prePayThoughBalance();
                    break;
                case Payment::PAY_WAY_YOUZAN:
                    // 判断是否开启有赞云支付
                    $this->prePayThoughYouZan($payment);
                    break;
                case Payment::PAY_WAY_EGHL:
                    $this->prePayThoughEGHL($payment);
                    break;
                default:
                    throw new InvalidArgumentException('不支持的支付通道，请检查选项');
            }

            DB::commit();

            return Response::json(['status' => 'success', 'data' => $sn, 'message' => '创建订单成功，正在转到付款页面，请稍后']);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('创建支付订单失败：' . $e->getMessage());

            return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建订单失败：' . $e->getMessage()]);
        }
    }

    // 支付单详情
    public function detail(Request $request, $sn)
    {
        if (empty($sn)) {
            return Redirect::to('services');
        }

        $payment = Payment::query()->with(['order', 'order.goods'])->where('sn', $sn)->where('user_id', Auth::user()->id)->first();
        if (!$payment) {
            return Redirect::to('services');
        }

        $order = Order::query()->where('oid', $payment->oid)->first();
        if (!$order) {
            Session::flash('errorMsg', '订单不存在');

            return Response::view('payment/' . $sn);
        }

        $view['payment'] = $payment;
        $view['website_logo'] = self::$systemConfig['website_logo'];
        $view['website_analytics'] = self::$systemConfig['website_analytics'];
        $view['website_customer_service'] = self::$systemConfig['website_customer_service'];

        return Response::view('payment.detail', $view);
    }

    // 获取订单支付状态
    public function getStatus(Request $request)
    {
        $sn = $request->get('sn');

        if (empty($sn)) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '请求失败']);
        }

        $payment = Payment::query()->where('sn', $sn)->where('user_id', Auth::user()->id)->first();
        if (!$payment) {
            return Response::json(['status' => 'error', 'data' => '', 'message' => '支付失败']);
        } elseif ($payment->status > 0) {
            return Response::json(['status' => 'success', 'data' => '', 'message' => '支付成功']);
        } elseif ($payment->status < 0) {
            return Response::json(['status' => 'error', 'data' => '', 'message' => '订单超时未支付，已自动关闭']);
        } else {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '等待支付']);
        }
    }

    // 有赞云回调日志
    public function callbackList(Request $request)
    {
        $status = $request->get('status', 0);

        $query = PaymentCallback::query();

        if ($status) {
            $query->where('status', $status);
        }

        $view['list'] = $query->orderBy('id', 'desc')->paginate(10);

        return Response::view('payment.callbackList', $view);
    }

    private function prePayThoughBalance()
    {
        // TODO consume account balance
    }

    /**
     * @param Order $payment
     * @throws Exception
     */
    private function prePayThoughYouZan(Payment $payment)
    {
        if (!self::$systemConfig['is_youzan']) {
            throw new UnexpectedValueException('创建支付单失败：系统并未开启在线支付功能');
        }

        $yzy = new Yzy();
        $result = $yzy->createQrCode($payment->order->goods->name, $payment->amount * 100, $payment->order_sn);
        if (isset($result['error_response'])) {
            Log::error('【有赞云】创建二维码失败：' . $result['error_response']['msg']);

            throw new Exception($result['error_response']['msg']);
        }

        $payment->qr_id = $result['response']['qr_id'];
        $payment->qr_url = $result['response']['qr_url'];
        $payment->qr_code = $result['response']['qr_code'];
        $payment->qr_local_url = $this->base64ImageSaver($result['response']['qr_code']);
        $payment->save();
    }

    /*
     * 后续步骤中请求 eGHL 以显示支付页面
     */
    private function prePayThoughEGHL(Payment $payment)
    {
    }
}
