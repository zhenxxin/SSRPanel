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
        // TODO 查询 payment 表中 当天开始 到 15 分钟前，状态为挂起的记录，并执行查询
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

        $query = Payment::query()
            ->where(['status' => Payment::STATUS_PROCESSING])
            ->whereBetween('created_at', [$timeBegin, $timeEnd])
            ->each([$this, 'query']);
    }

    /**
     * @param Payment $payment
     * @param $index
     */
    public function query($payment, $index)
    {
        $params = [
            'TransactionType' => 'QUERY',
            'PymtMethod' => 'ANY', // 与创建请求时一致
            'PaymentID' => $payment->sn,
            'Amount' => number_format($payment->getAmountAttribute($payment->amount), 2, '.', ''),
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
            return; // 如果返回 false，表示中断后续处理
        }
        $params['ServiceID'] = EGHL::get('ServiceID');
        $params['HashValue'] = $hash;
        $uniqueId = $payment->sn;

        $client = new Client();
        try {
            $resp = $client->get(EGHL::get('URL'), ['query' => $params, 'timeout' => 20]);
            $statusCode = $resp->getStatusCode();
            if (200 != $statusCode) {
                throw new Exception("请求失败，返回状态码为：${statusCode}");
            }

            $body = $resp->getBody()->getContents();
            $result = parse_query(trim($body));
            if (!is_array($result) || !isset($result['TxnStatus'])) {
                throw new Exception("请求失败，返回结果为：{$body}");
            }
        } catch (Exception $exception) {
            $message = $exception->getMessage();
            $this->warn("查证失败：${uniqueId}，原因：${message}");
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
            $user = $payment->user;

            $payment->status = $paymentStatus;
            $payment->save();
            $order->status = $orderStatus;
            $order->save();

            if (Order::STATUS_SUCCESS == $orderStatus) {
                $this->info("支付订单(${uniqueId})查证成功，即将更新账户余额");
                $updated = User::query()
                    ->where(['id' => $payment->user_id, 'updated_at' => $user->updated_at])
                    ->increment('balance', $order->amount);
                if (1 != $updated) {
                    throw new Exception("更新账户余额失败，记录数不是预期值");
                }
            }
            DB::commit();
        } catch (Exception $exception) {
            DB::rollBack();
            $message = $exception->getMessage();
            $this->warn("更新数据库失败：${uniqueId}, 原因：${message}");
        }
    }
}
