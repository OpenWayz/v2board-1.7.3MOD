<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\TicketSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Utils\Dict;
use Illuminate\Http\Request;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $ticket = Ticket::where('id', $request->input('id'))
                ->where('user_id', $request->user['id'])
                ->first();
            if (!$ticket) {
                abort(500, __('Ticket does not exist'));
            }
            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->get();
            for ($i = 0; $i < count($ticket['message']); $i++) {
                if ($ticket['message'][$i]['user_id'] == $ticket->user_id) {
                    $ticket['message'][$i]['is_me'] = true;
                } else {
                    $ticket['message'][$i]['is_me'] = false;
                }
            }
            return response([
                'data' => $ticket
            ]);
        }
        $ticket = Ticket::where('user_id', $request->user['id'])
            ->orderBy('created_at', 'DESC')
            ->get();
        return response([
            'data' => $ticket
        ]);
    }

    public function save(TicketSave $request)
    {
        DB::beginTransaction();
        if ((int)Ticket::where('status', 0)->where('user_id', $request->user['id'])->lockForUpdate()->count()) {
            abort(500, __('There are other unresolved tickets'));
        }
        $ticket = Ticket::create(array_merge($request->only([
            'subject',
            'level'
        ]), [
            'user_id' => $request->user['id']
        ]));
        if (!$ticket) {
            DB::rollback();
            abort(500, __('Failed to open ticket'));
        }
        $ticketMessage = TicketMessage::create([
            'user_id' => $request->user['id'],
            'ticket_id' => $ticket->id,
            'message' => $request->input('message')
        ]);
        if (!$ticketMessage) {
            DB::rollback();
            abort(500, __('Failed to open ticket'));
        }
        DB::commit();
        $this->sendNotify($ticket, $request->input('message'), $request->user['id']);
        return response([
            'data' => true
        ]);
    }

    public function reply(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, __('Invalid parameter'));
        }
        if (empty($request->input('message'))) {
            abort(500, __('Message cannot be empty'));
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$ticket) {
            abort(500, __('Ticket does not exist'));
        }
        if ($ticket->status) {
            abort(500, __('The ticket is closed and cannot be replied'));
        }
        if ($request->user['id'] == $this->getLastMessage($ticket->id)->user_id) {
            abort(500, __('Please wait for the technical enginneer to reply'));
        }
        $ticketService = new TicketService();
        if (!$ticketService->reply(
            $ticket,
            $request->input('message'),
            $request->user['id']
        )) {
            abort(500, __('Ticket reply failed'));
        }
        $this->sendNotify($ticket, $request->input('message'), $request->user['id']);
        return response([
            'data' => true
        ]);
    }


    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, __('Invalid parameter'));
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$ticket) {
            abort(500, __('Ticket does not exist'));
        }
        $ticket->status = 1;
        if (!$ticket->save()) {
            abort(500, __('Close failed'));
        }
        return response([
            'data' => true
        ]);
    }

    private function getLastMessage($ticketId)
    {
        return TicketMessage::where('ticket_id', $ticketId)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function withdraw(TicketWithdraw $request)
    {
        if ((int)config('v2board.withdraw_close_enable', 0)) {
            abort(500, 'user.ticket.withdraw.not_support_withdraw');
        }
        if (!in_array(
            $request->input('withdraw_method'),
            config(
                'v2board.commission_withdraw_method',
                Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT
            )
        )) {
            abort(500, __('Unsupported withdrawal method'));
        }
        $user = User::find($request->user['id']);
        $limit = config('v2board.commission_withdraw_limit', 100);
        if ($limit > ($user->commission_balance / 100)) {
            abort(500, __('The current required minimum withdrawal commission is :limit', ['limit' => $limit]));
        }
        DB::beginTransaction();
        $subject = __('[Commission Withdrawal Request] This ticket is opened by the system');
        $ticket = Ticket::create([
            'subject' => $subject,
            'level' => 2,
            'user_id' => $request->user['id']
        ]);
        if (!$ticket) {
            DB::rollback();
            abort(500, __('Failed to open ticket'));
        }
        $message = sprintf("%s\r\n%s",
            __('Withdrawal method') . "：" . $request->input('withdraw_method'),
            __('Withdrawal account') . "：" . $request->input('withdraw_account')
        );
        $ticketMessage = TicketMessage::create([
            'user_id' => $request->user['id'],
            'ticket_id' => $ticket->id,
            'message' => $message
        ]);
        if (!$ticketMessage) {
            DB::rollback();
            abort(500, __('Failed to open ticket'));
        }
        DB::commit();
        $this->sendNotify($ticket, $message, $request->user['id']);
        return response([
            'data' => true
        ]);
    }

//    private function sendNotify(Ticket $ticket, string $message)
//    {
//        $telegramService = new TelegramService();
//        $telegramService->sendMessageWithAdmin("📮工单提醒 #{$ticket->id}\n———————————————\n主题：\n`{$ticket->subject}`\n内容：\n`{$message}`", true);
//    }
    private function sendNotify(Ticket $ticket, string $message, $user_id)
    {
        $user = User::find($user_id)->load('plan');
        $transfer_enable = $this->getFlowData($user->transfer_enable); // 总流量
        $remaining_traffic = $this->getFlowData($user->transfer_enable - $user->u - $user->d); // 剩余流量
        $expired_at = date("Y-m-d H:i:s", $user->expired_at); // 到期时间
        $plan = $user->plan;

        $ip_address = '';// IP地址

        // 检查 Cloudflare 提供的特定头
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip_address = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip_address = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // 如果特定头不存在，尝试解析 X-Forwarded-For 头
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            // 遍历数组，寻找第一个不是内部地址的 IP 地址
            foreach ($ips as $candidate) {
                $candidate = trim($candidate);
                // 检查 IP 地址是否是内部地址或保留地址
                if (!filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $ip_address = $candidate;
                    break;
                }
            }
        }

        // 如果未找到合适的 IP 地址，返回 REMOTE_ADDR
        if (empty($ip_address)) {
            $ip_address = $_SERVER['REMOTE_ADDR'];
        }

        $api_url = "http://ip-api.com/json/{$ip_address}?fields=520191&lang=zh-CN";
        $response = file_get_contents($api_url);
        $user_location = json_decode($response, true);
        if ($user_location && $user_location['status'] === 'success') {
            $location =  $user_location['city'] . ", " . $user_location['country'];
        } else {
            $location =  "无法确定用户地址";
        }

        $TGmessage = "📮工单 #{$ticket->id}\n———————————————\n";
        $TGmessage .= "用户ID: `{$user_id}`\n";
        $TGmessage .= "位置IP: `{$location} {$ip_address}`\n";
        if($user->plan){
            $TGmessage .= "套餐: `{$plan->name} {$remaining_traffic}/{$transfer_enable}`\n";
            $TGmessage .= "到期日: `{$expired_at}`\n";
        }else{
            $TGmessage .= "套餐与流量: \n`未订购任何套餐`\n";
        }
        $TGmessage .= "主题:`{$ticket->subject}`\n内容：\n`{$message}`\n";
        $telegramService = new TelegramService();
        $telegramService->sendMessageWithAdmin($TGmessage, true);
    }
    private function getFlowData($b)
    {
        $m = $b / (1024 * 1024);
        if ($m >= 1024) {
            $g = $m / 1024;
            $text = round($g, 2) . "GB";
        } else {
            $text = round($m, 2) . "MB";
        }
        return $text;
    }
}
