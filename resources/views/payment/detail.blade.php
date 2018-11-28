@extends('user.layouts')
@section('css')
@endsection
@section('content')
    <!-- BEGIN CONTENT BODY -->
    <div class="page-content" style="padding-top:0;">
        <!-- BEGIN PAGE BASE CONTENT -->
        <div class="portlet light bordered">
            <div class="portlet-body">
                @if (Session::get('errorMsg'))
                <div class="alert alert-danger">
                    <button class="close" data-close="alert"></button>
                    <span> {!! Session::get('errorMsg') !!} </span>
                </div>
                @endif
                <div class="alert alert-info" style="text-align: center;">
                    @if (1 == $payment->pay_way)
                    @elseif (2 == $payment->pay_way)
                    请使用<strong style="color:red;">支付宝、QQ、微信</strong>扫描如下二维码
                    @elseif (3 == $payment->pay_way)
                    请准备好您的信用卡信息，并输入<strong style="color: red;">姓名，手机号码，邮箱</strong>，然后点击支付按钮将跳转到 <strong>GHL Payment Gateway</strong> 进行支付
                    @endif
                </div>
                <div class="row" style="text-align: center; font-size: 1.05em;">
                    <div class="col-md-12">
                        <div class="table-scrollable">
                            <table class="table table-hover table-light">
                                <tr>
                                    <td align="right" width="50%">服务名称：</td>
                                    <td align="left" width="50%">{{$payment->order->goods->name}}</td>
                                </tr>
                                <tr>
                                    <td align="right">应付金额：</td>
                                    <td align="left">{{$payment->amount}} 元</td>
                                </tr>
                                <tr>
                                    <td align="right">有效期：</td>
                                    <td align="left">{{$payment->order->goods->days}} 天</td>
                                </tr>
                                @if (1 == $payment->pay_way)
                                @elseif (2 == $payment->pay_way)
                                <tr>
                                    <td colspan="2">
                                        扫描下方二维码进行付款（可截图再扫描）
                                        <br>
                                        请于15分钟内支付，到期未支付订单将自动关闭
                                        <br>
                                        支付后，请稍作等待，账号状态会自动更新
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" align="center">
                                        <img src="{{$payment->qr_local_url}}"/>
                                    </td>
                                </tr>
                                @elseif (3 == $payment->pay_way)
                                <tr>
                                    <td colspan="2">
                                        <form action="/payment-eghl/create/{{ $payment->sn }}" method="post">
                                            <label for="cust_name">姓名</label>
                                            <input type="text" name="cust_name" />
                                            <label for="cust_phone">手机号码</label>
                                            <input type="text" name="cust_phone" />
                                            <label for="cust_email">邮箱</label>
                                            <input type="email" name="cust_email" />
                                            <input type="hidden" name="_token" value="{{csrf_token()}}" />
                                            <button class="btn btn-large blue hidden-print uppercase" type="submit">立即支付</button>
                                        </form>
                                    </td>
                                </tr>
                                @endif
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- END PAGE BASE CONTENT -->
    </div>
    <!-- END CONTENT BODY -->
@endsection
@section('script')
    <script type="text/javascript">
        // 每800毫秒查询一次订单状态
        $(document).ready(function(){
            setInterval("getStatus()", 800);
        });

        // 检查支付单状态
        function getStatus () {
            var sn = '{{$payment->sn}}';

            $.get("{{url('payment/getStatus')}}", {sn:sn}, function (ret) {
                console.log(ret);
                if (ret.status == 'success') {
                    layer.msg(ret.message, {time:1500}, function() {
                        window.location.href = '{{url('invoices')}}';
                    });
                } else if(ret.status == 'error') {
                    layer.msg(ret.message, {time:1500}, function () {
                        window.location.href = '{{url('invoices')}}';
                    })
                }
            });
        }
    </script>
@endsection