<?php

namespace App\Http\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 支付单
 * Class Payment
 *
 * @property Order $order
 * @property User $user
 *
 * @package App\Http\Models
 * @property mixed $amount
 * @property-read mixed $pay_way_label
 * @property-read mixed $status_label
 * @property-read \App\Http\Models\Order $order
 * @property-read \App\Http\Models\User $user
 * @mixin \Eloquent
 */
class Payment extends Model
{
    const PAY_WAY_BALANCE = 1;
    const PAY_WAY_YOUZAN = 2;
    const PAY_WAY_EGHL = 3;

    const STATUS_PROCESSING = 0;
    const STATUS_SUCCESS = 1;
    const STATUS_NEW = 2;
    const STATUS_REFUNDED = 3;
    const STATUS_FAILED = -1;

    protected $table = 'payment';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'oid', 'oid');
    }

    public function getAmountAttribute($value)
    {
        return $value / 100;
    }

    public function setAmountAttribute($value)
    {
        return $this->attributes['amount'] = $value * 100;
    }

    // 订单状态
    public function getStatusLabelAttribute()
    {
        switch ($this->attributes['status']) {
            case self::STATUS_FAILED:
                $status_label = '支付失败';
                break;
            case self::STATUS_SUCCESS:
                $status_label = '支付成功';
                break;
            case self::STATUS_REFUNDED:
                $status_label = '已取消';
                break;
            case self::STATUS_NEW:
            case self::STATUS_PROCESSING:
            default:
                $status_label = '等待支付';
                break;
        }

        return $status_label;
    }

    // 支付方式
    public function getPayWayLabelAttribute()
    {
        switch ($this->attributes['pay_way']) {
            case 1:
                $pay_way_label = '微信';
                break;
            case 2:
            default:
                $pay_way_label = '支付宝';
                break;
        }

        return $pay_way_label;
    }
}
