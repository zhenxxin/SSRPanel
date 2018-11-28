<?php

namespace App\Console\Commands;

use App\Http\Models\Order;
use App\Http\Models\User;
use DB;
use EGHL;
use App\Http\Models\Payment;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use function GuzzleHttp\Psr7\parse_query;
use Illuminate\Console\Command;
use Log;

class PaymentQueryEGHL extends Command
{
    private $password;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment:query-eghl {--begin=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'eGHL payment query';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->password = config('services.payment.eghl.merchant_password');
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        // 查询 payment 表中 当天开始 到 15 分钟前，状态为挂起的记录，并执行查询
        // 如果查询到交易结果，成功了，就将余额加上，失败了就标记掉
        $timeBegin = $this->option('begin');
        if (is_null($timeBegin)) {
            $timeBegin = Carbon::now()->setTime(0, 0);
        }
        $timeEnd = $this->option('end');
        if (is_null($timeEnd)) {
            $timeEnd = Carbon::now()->subMinutes(15);
        }

        // 跨天的时候，调换一下时间
        if ($timeEnd < $timeBegin) {
            $fiftyMinutesAgo = $timeEnd;
            $timeEnd = $timeBegin;
            $timeBegin = $fiftyMinutesAgo;
        }

        Log::info("开始查证时间区间：${timeBegin} ~ ${timeEnd} 的支付订单");
        $result = Payment::query()->whereIn('status', [Payment::STATUS_NEW, Payment::STATUS_PROCESSING])
            ->where(['pay_way' => Payment::PAY_WAY_EGHL])
            ->whereBetween('created_at', [$timeBegin, $timeEnd])
            ->each([$this, 'query']);

        Log::info("完成查证时间区间：${timeBegin} ~ ${timeEnd} 的支付订单");
    }

    /**
     * @param Payment $payment
     * @param $index
     * @throws Exception
     */
    public function query($payment, $index)
    {
        $uniqueId = $payment->sn;
        $head = "查证交易(${uniqueId})的状态，";
        Log::debug($head . "位置在当前批次的 ${index} ");
        $params = $this->buildQueryParams($payment);
        if (false === $params) {
            Log::warning($head . "构建查证参数失败");
            return; // 继续下一个
        }

        $client = new Client();
        try {
            $resp = $client->get(EGHL::get('URL'), ['query' => $params, 'timeout' => 20]);
            $statusCode = $resp->getStatusCode();
            if (200 != $statusCode) {
                throw new Exception("请求失败，返回状态码为：${statusCode}");
            }

            $body = $resp->getBody()->getContents();
            Log::debug($head . "收到响应，status: ${statusCode}, body: ${body}");
            $result = parse_query(trim($body));
            if (!is_array($result)) {
                throw new Exception("返回结果为：{$body}");
            }

            if (isset($result['TxnExists']) && 0 != $result['TxnExists']) {
                // 2 表示内部异常，这个最好多次查证
                // 1 表示不存在，建议设置超时关闭
                Log::info($head . "交易不存在，稍后查证");
                return;
            }

            if (!isset($result) || !isset($result['TxnStatus'])) {
                throw new Exception("返回结果参数缺失");
            }

            if (!EGHL::verifyRequestFromParams($this->password, $result)) {
                throw new Exception("校验失败");
            }
        } catch (Exception $exception) {
            $message = $exception->getMessage();
            Log::warning($head . "请求失败，原因：${message}");
            return;
        }

        switch ($result['TxnStatus']) {
            case 0:
                $paymentStatus = Payment::STATUS_SUCCESS;
                $orderStatus = Order::STATUS_SUCCESS;
                break;
            case 1:
                $paymentStatus = Payment::STATUS_FAILED;
                $orderStatus = Order::STATUS_FAILED;
                break;
            case 2:
                $paymentStatus = Payment::STATUS_PROCESSING;
                $orderStatus = Order::STATUS_PROCESSING;
                break;
            case 10:
                $paymentStatus = Payment::STATUS_REFUNDED;
                // 已退款的，将订单置为失败
                $orderStatus = Order::STATUS_FAILED;
                break;
            case -1:
                // 状态不存在时，将状态设置为新建，可以重新发起支付
                $paymentStatus = Payment::STATUS_NEW;
                $orderStatus = Order::STATUS_PROCESSING;
                break;
            case -2: // 对方系统错误，暂不处理
            default:
                $paymentStatus = Payment::STATUS_PROCESSING;
                $orderStatus = Order::STATUS_PROCESSING;
                break;
        }

        DB::beginTransaction();
        try {
            $order = $payment->order;
            $user = $order->user;

            $payment->status = $paymentStatus;
            $payment->save();
            $order->status = $orderStatus;
            $order->save();

            Log::debug($head . "查证成功，payment: ${paymentStatus}, order: ${orderStatus}");
            if (Order::STATUS_SUCCESS == $orderStatus) {
                $this->info("支付订单(${uniqueId})查证成功，即将更新账户余额");
                $updated = User::query()
                    ->where(['id' => $order->user_id, 'updated_at' => $user->updated_at])
                    ->increment('balance', $order->amount * 100); // 这里要将单位换算一下
                if (1 != $updated) {
                    throw new Exception("更新账户余额失败，记录数不是预期值");
                }
            }
            DB::commit();
        } catch (Exception $exception) {
            DB::rollBack();
            $message = $exception->getMessage();
            Log::warning($head . "更新数据库失败, 原因：${message}");
        }
    }

    /**
     * @param Payment $payment
     * @return array|bool
     */
    private function buildQueryParams($payment)
    {
        $params = [
            'ServiceID' => EGHL::get('ServiceID'),
            'TransactionType' => 'QUERY',
            'PymtMethod' => 'ANY', // 与创建请求时一致
            'PaymentID' => $payment->sn,
            'Amount' => $payment->amount,
            'CurrencyCode' => 'CNY',
            'MerchantReturnURL' => '', // 为了 calcHash 正常调用，不要传值
            'CustIP' => '',
        ];

        foreach ($params as $key => $val) {
            EGHL::set($key, $val);
        }

        $hash = EGHL::calcHash($this->password);
        if (false == $hash) {
            $this->warn("计算 ${payment['PaymentID']} 的 hash 错误");
            return false; // 如果返回 false，表示中断后续处理
        }
        $params['HashValue'] = $hash;

        return $params;
    }
}
