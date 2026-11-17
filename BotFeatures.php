<?php
/**
 * Adult-only engagement, wallet and safety actions for the legacy update entry point.
 * Run migrate-features.php before enabling this handler.
 */
final class BotFeatures
{
    private static function api(string $method, array $data = []): ?object
    {
        $ch = curl_init('https://api.telegram.org/bot' . API_KEY . '/' . $method);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => $data, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 12]);
        $body = curl_exec($ch);
        curl_close($ch);
        return is_string($body) ? json_decode($body) : null;
    }

    private static function stmt(mysqli $db, string $sql, array $args = []): mysqli_stmt
    {
        $s = $db->prepare($sql);
        if (!$s) throw new RuntimeException('Feature database prepare failed');
        if ($args) $s->bind_param(str_repeat('s', count($args)), ...$args);
        $s->execute();
        return $s;
    }

    private static function row(mysqli $db, string $sql, array $args = []): ?array
    {
        $r = self::stmt($db, $sql, $args)->get_result()->fetch_assoc();
        return $r ?: null;
    }

    private static function ledger(mysqli $db, int $user, int $amount, string $reason, string $ref, int $actor = 0): void
    {
        self::stmt($db, 'INSERT INTO platform_ledger(user_id,amount,reason,reference,actor,created) VALUES(?,?,?,?,?,?)', [$user,$amount,$reason,$ref,$actor,time()]);
        $s = self::stmt($db, 'UPDATE platform_accounts SET balance=balance+? WHERE id=? AND balance+?>=0', [$amount,$user,$amount]);
        if ($s->affected_rows !== 1) throw new RuntimeException('Insufficient coins');
    }

    private static function send(int $chat, string $text, array $rows = []): void
    {
        $data = ['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML'];
        if ($rows) $data['reply_markup'] = json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::api('sendMessage', $data);
    }

    private static function menu(mysqli $db, int $id): void
    {
        $a = self::row($db, 'SELECT balance FROM platform_accounts WHERE id=?', [$id]);
        $balance = (int)($a['balance'] ?? 0);
        self::send($id, "<b>✨ امکانات و کیف پول</b>\nموجودی: <b>{$balance}</b> سکه\n\nپرداخت‌های TON با بررسی دستی مدیر انجام می‌شوند. اشتراک‌ها با پرداخت Stars تلگرام فعال می‌شوند.", [
            [['🪙 کیف پول','bf:wallet'],['🎡 گردونه شانس','bf:wheel']],
            [['⭐ اشتراک ویژه','bf:premium'],['🎁 مأموریت روزانه','bf:daily']],
            [['💝 هدیه سکه','bf:gifthelp'],['💎 شارژ TON','bf:ton']],
            [['🚩 گزارش کاربر در گفت‌وگوی فعال','bf:report']],
        ]);
    }

    private static function wallet(mysqli $db, int $id): void
    {
        $a = self::row($db, 'SELECT balance FROM platform_accounts WHERE id=?', [$id]);
        $items = self::stmt($db, 'SELECT amount,reason,created FROM platform_ledger WHERE user_id=? ORDER BY id DESC LIMIT 10', [$id])->get_result();
        $text = '<b>🪙 کیف پول</b> · موجودی <b>'.(int)($a['balance'] ?? 0)."</b> سکه\n<b>۱۰ تراکنش اخیر</b>";
        while ($r = $items->fetch_assoc()) {
            $sign = (int)$r['amount'] > 0 ? '+' : '';
            $text .= "\n".gmdate('Y-m-d H:i', (int)$r['created']).' · '.$sign.(int)$r['amount'].' · '.htmlspecialchars($r['reason'], ENT_QUOTES, 'UTF-8');
        }
        self::send($id, $text, [
            [['⭐ بسته سکه (Telegram Stars)','bf:buycoins']],
            [['📅 روزانه','bf:subscribe:day'],['🗓 ماهانه','bf:subscribe:month'],['📆 سالانه','bf:subscribe:year']],
            [['🎁 مأموریت روزانه','bf:daily'],['💎 شارژ TON','bf:ton']],
            [['↩️ امکانات','bf:menu']],
        ]);
    }

    private static function invoice(mysqli $db, int $id, string $kind, string $plan = ''): void
    {
        $stars = $kind === 'coins' ? 50 : ['day'=>10,'month'=>200,'year'=>1500][$plan];
        $coins = $kind === 'coins' ? 50 : 0;
        $payload = bin2hex(random_bytes(20));
        self::stmt($db, 'INSERT INTO bot_feature_orders(payload,user_id,kind,plan,stars,coins,created) VALUES(?,?,?,?,?,?,?)', [$payload,$id,$kind,$plan,$stars,$coins,time()]);
        $title = $kind === 'coins' ? '۵۰ سکه کیف پول' : 'اشتراک ویژه '.(['day'=>'روزانه','month'=>'ماهانه','year'=>'سالانه'][$plan]);
        $res = self::api('sendInvoice', ['chat_id'=>$id,'title'=>$title,'description'=>'پرداخت امن با Telegram Stars.','payload'=>$payload,'provider_token'=>'','currency'=>'XTR','prices'=>json_encode([['label'=>$title,'amount'=>$stars]],JSON_UNESCAPED_UNICODE)]);
        if (!($res->ok ?? false)) self::send($id, 'ساخت صورت‌حساب انجام نشد. کمی بعد دوباره تلاش کنید.', [['↩️ کیف پول','bf:wallet']]);
    }

    private static function payment(mysqli $db, object $u): bool
    {
        if (isset($u->pre_checkout_query)) {
            $q = $u->pre_checkout_query;
            $o = self::row($db, 'SELECT * FROM bot_feature_orders WHERE payload=?', [(string)$q->invoice_payload]);
            $ok = $o && $o['status']==='pending' && (int)$o['user_id']===(int)$q->from->id && $q->currency==='XTR' && (int)$q->total_amount===(int)$o['stars'] && time()-(int)$o['created']<3600;
            self::api('answerPreCheckoutQuery', ['pre_checkout_query_id'=>$q->id,'ok'=>$ok?'true':'false','error_message'=>$ok?'':'این صورت‌حساب منقضی یا نامعتبر است.']);
            return true;
        }
        if (!isset($u->message->successful_payment)) return false;
        $p=$u->message->successful_payment;$id=(int)$u->message->from->id;
        $db->begin_transaction();
        try {
            $o=self::row($db,'SELECT * FROM bot_feature_orders WHERE payload=? FOR UPDATE',[(string)$p->invoice_payload]);
            if(!$o||$o['status']!=='pending'||(int)$o['user_id']!==$id||$p->currency!=='XTR'||(int)$p->total_amount!==(int)$o['stars']) throw new RuntimeException('Invalid payment');
            if($o['kind']==='coins') self::ledger($db,$id,(int)$o['coins'],'Stars purchase','bf-stars:'.$p->telegram_payment_charge_id);
            else {
                $current=(int)(self::row($db,'SELECT premium_until FROM bot_feature_users WHERE user_id=? FOR UPDATE',[$id])['premium_until']??0);
                $base=max(time(),$current);$seconds=['day'=>86400,'month'=>2592000,'year'=>31536000][$o['plan']];
                self::stmt($db,'INSERT INTO bot_feature_users(user_id,premium_until) VALUES(?,?) ON DUPLICATE KEY UPDATE premium_until=VALUES(premium_until)',[$id,$base+$seconds]);
                self::stmt($db,'INSERT INTO bot_feature_events(user_id,kind,detail,created) VALUES(?,?,?,?)',[$id,'subscription',$o['plan'],time()]);
            }
            self::stmt($db,"UPDATE bot_feature_orders SET status='paid',charge_id=? WHERE payload=?",[$p->telegram_payment_charge_id,$p->invoice_payload]);
            $db->commit();
        } catch(Throwable $e) { $db->rollback(); error_log('Feature payment processing failed: '.$e->getMessage()); return true; }
        self::send($id,$o['kind']==='coins'?'پرداخت تأیید شد و ۵۰ سکه به کیف پول اضافه شد.':'اشتراک ویژه با موفقیت فعال شد.',[['🪙 کیف پول','bf:wallet']]);
        return true;
    }

    private static function daily(mysqli $db, int $id): void
    {
        $day=gmdate('Y-m-d');$db->begin_transaction();
        try {
            $s=self::stmt($db,'INSERT IGNORE INTO bot_feature_claims(user_id,claim_day,kind,created) VALUES(?,?,?,?)',[$id,$day,'daily',time()]);
            if($s->affected_rows!==1){$db->rollback();self::send($id,'پاداش امروزت را قبلاً گرفتی؛ فردا دوباره سر بزن.');return;}
            self::ledger($db,$id,2,'daily mission','bf-daily:'.$id.':'.$day);
            $db->commit();
        }catch(Throwable $e){$db->rollback();throw $e;}
        self::send($id,'✅ مأموریت روزانه انجام شد؛ ۲ سکه به کیف پولت اضافه شد.',['wallet'=>['text'=>'🪙 کیف پول','callback_data'=>'bf:wallet']]);
    }

    private static function spin(mysqli $db, int $id, string $updateKey): void
    {
        $rewards=[[0,35],[1,35],[3,20],[10,9],[50,1]];$roll=random_int(1,100);$sum=0;$won=0;
        foreach($rewards as [$coins,$weight]){$sum+=$weight;if($roll<=$sum){$won=$coins;break;}}
        $db->begin_transaction();
        try {
            self::ledger($db,$id,-1,'wheel spin','bf-wheel-cost:'.$updateKey);
            if($won)self::ledger($db,$id,$won,'wheel reward','bf-wheel-reward:'.$updateKey);
            self::stmt($db,'INSERT INTO bot_feature_events(user_id,kind,detail,created) VALUES(?,?,?,?)',[$id,'wheel',(string)$won,time()]);
            $db->commit();
        }catch(Throwable $e){$db->rollback();throw $e;}
        self::send($id,"🎡 جایزهٔ گردونه: {$won} سکه. هزینهٔ چرخش: ۱ سکه.",[['↻ دوباره','bf:wheel'],['🪙 کیف پول','bf:wallet']]);
    }

    private static function gift(mysqli $db, int $id, int $target, int $amount, string $key): void
    {
        if($target===$id||$amount<1||$amount>10000)throw new RuntimeException('شناسهٔ گیرنده یا مقدار هدیه نامعتبر است.');
        if(!self::row($db,'SELECT id FROM platform_accounts WHERE id=?',[$target]))throw new RuntimeException('گیرنده باید ابتدا ربات را باز کرده باشد.');
        $db->begin_transaction();
        try {self::ledger($db,$id,-$amount,'coin gift','bf-gift:'.$key);self::ledger($db,$target,$amount,'gift received','bf-gift-in:'.$key,$id);$db->commit();}
        catch(Throwable $e){$db->rollback();throw $e;}
        self::send($id,"🎁 {$amount} سکه به کاربر {$target} هدیه شد.");self::send($target,"🎁 {$amount} سکه از یک دوست دریافت کردی.",[['🪙 کیف پول','bf:wallet']]);
    }

    private static function activePeer(mysqli $db,int $id): ?int
    {
        $r=self::row($db,"SELECT CASE WHEN s.customer=? THEN s.provider ELSE s.customer END peer FROM platform_sessions s JOIN platform_participants p ON p.session_id=s.id WHERE p.user_id=? AND s.status='active' LIMIT 1",[$id,$id]);
        return $r?(int)$r['peer']:null;
    }

    private static function report(mysqli $db,int $id): void
    {
        $peer=self::activePeer($db,$id);if(!$peer){self::send($id,'برای گزارش، باید در یک گفت‌وگوی فعال باشی.');return;}
        $key=bin2hex(random_bytes(12));$session=self::row($db,"SELECT session_id FROM platform_participants WHERE user_id=?",[$id]);
        self::stmt($db,'INSERT INTO bot_feature_reports(report_key,reporter_id,target_id,session_id,reason,created) VALUES(?,?,?,?,?,?)',[$key,$id,$peer,$session['session_id']??null,'گزارش از گفت‌وگوی فعال',time()]);
        self::stmt($db,'INSERT INTO bot_feature_events(user_id,kind,detail,created) VALUES(?,?,?,?)',[$peer,'reported','reporter:'.$id,time()]);
        self::send($id,'گزارشت برای بررسی مدیران ثبت شد. می‌توانی کاربر را هم در سطح حساب خودت مسدود کنی.',[['🚫 مسدودسازی شخصی','bf:block:'.$peer],['↩️ امکانات','bf:menu']]);
        foreach(array_filter(array_map('intval',$GLOBALS['admin']??[])) as $admin) self::send($admin,"🚩 گزارش تازه #{$key}\nگزارش‌دهنده: {$id}\nکاربر گزارش‌شده: {$peer}",[[['✅ بررسی‌شد','bf:resolve:'.$key],['⏱ محرومیت ۱ روزه','bf:ban:'.$peer.':1440']],[['⛔ مسدود دائمی','bf:ban:'.$peer.':0']]]);
    }

    private static function route(mysqli $db, object $u): bool
    {
        $m=$u->message??null;$cb=$u->callback_query??null;$id=(int)($m->from->id??$cb->from->id??0);if(!$id)return false;
        $text=trim((string)($m->text??''));$data=(string)($cb->data??'');$chat=(int)($m->chat->id??$cb->message->chat->id??0);
        if($cb&&str_starts_with($data,'bf:')) self::api('answerCallbackQuery',['callback_query_id'=>$cb->id]);
        if($data==='bf:menu'||$text==='/features'||$text==='/startfeatures') {self::menu($db,$id);return true;}
        if($data==='bf:wallet'||$text==='/wallet') {self::wallet($db,$id);return true;}
        if($data==='bf:daily'||$text==='/daily') {self::daily($db,$id);return true;}
        if($data==='bf:wheel'||$text==='/wheel') {self::send($id,'🎡 گردونه: هزینهٔ هر چرخش ۱ سکه است. احتمال جایزه‌ها: ۰ سکه ۳۵٪، ۱ سکه ۳۵٪، ۳ سکه ۲۰٪، ۱۰ سکه ۹٪، ۵۰ سکه ۱٪.',[['🎡 چرخاندن','bf:spin'],['↩️ امکانات','bf:menu']]);return true;}
        if($data==='bf:spin') {self::spin($db,$id,(string)($u->update_id??bin2hex(random_bytes(8))));return true;}
        if($data==='bf:buycoins') {self::invoice($db,$id,'coins');return true;}
        if($data==='bf:premium') {self::send($id,'اشتراک ویژه، فیلترهای اضافی و امکانات VIP را فعال می‌کند. پرداخت با Telegram Stars است.',[['📅 روزانه','bf:subscribe:day'],['🗓 ماهانه','bf:subscribe:month'],['📆 سالانه','bf:subscribe:year'],['↩️ امکانات','bf:menu']]);return true;}
        if(preg_match('/^bf:subscribe:(day|month|year)$/',$data,$mm)){self::invoice($db,$id,'subscription',$mm[1]);return true;}
        if($data==='bf:ton'||$text==='/ton') {self::send($id,'💎 آدرس کیف پول TON:\n<code>UQC60I-MCcAjq2EB2voZTByspcg0UUy_7WS_IoC5S-B6nNpo</code>\n\nارسال TON، تا وقتی تراکنش توسط مدیر در شبکه بررسی نشده، سکه‌ای اضافه نمی‌کند. از عبارت بازیابی یا کلید خصوصی استفاده/ارسال نکن. برای ثبت: <code>/tonconfirm AMOUNT HASH</code>',[['↩️ امکانات','bf:menu']]);return true;}
        if(preg_match('/^\/tonconfirm\s+([0-9]+(?:\.[0-9]{1,9})?)\s+([A-Za-z0-9_-]{32,128})$/',$text,$mm)){
            $stmt=self::stmt($db,'INSERT INTO bot_feature_ton(user_id,amount,tx_hash,status,created) VALUES(?,?,?,\'pending\',?)',[$id,$mm[1],$mm[2],time()]);
            self::send($id,'درخواست ثبت شد. سکه فقط پس از بررسی واقعی تراکنش و مقصد توسط مدیر اضافه می‌شود.');
            foreach(array_filter(array_map('intval',$GLOBALS['admin']??[])) as $admin)self::send($admin,"درخواست TON از {$id}\nمبلغ: {$mm[1]}\nHash: {$mm[2]}\nپیش از تأیید، شبکه، مقصد و مبلغ را خارج از ربات بررسی کنید.",[[['✅ تأیید پس از بررسی','bf:tonapprove:'.$db->insert_id]]]);return true;
        }
        if(preg_match('/^\/gift\s+([1-9][0-9]{0,15})\s+([1-9][0-9]{0,4})$/',$text,$mm)){self::gift($db,$id,(int)$mm[1],(int)$mm[2],(string)($u->update_id??bin2hex(random_bytes(8))));return true;}
        if($data==='bf:gifthelp'){self::send($id,'برای هدیه دادن از دستور زیر استفاده کن:\n<code>/gift USER_ID AMOUNT</code>\nمثال: <code>/gift 123456789 10</code>',[['↩️ امکانات','bf:menu']]);return true;}
        if($data==='bf:report'){self::report($db,$id);return true;}
        if(preg_match('/^bf:block:([1-9][0-9]{0,15})$/',$data,$mm)){
            self::stmt($db,'INSERT IGNORE INTO bot_feature_blocks(owner_id,blocked_id,created) VALUES(?,?,?)',[$id,(int)$mm[1],time()]);self::send($id,'این کاربر برای حساب تو مسدود شد.');return true;
        }
        if(preg_match('/^bf:resolve:([a-f0-9]{24})$/',$data,$mm)){
            if(!in_array($id,array_map('intval',$GLOBALS['admin']??[]),true))return true;
            self::stmt($db,"UPDATE bot_feature_reports SET status='reviewed',reviewer_id=?,reviewed_at=? WHERE report_key=? AND status='open'",[$id,time(),$mm[1]]);self::send($id,'گزارش بررسی‌شده علامت خورد.');return true;
        }
        if(preg_match('/^bf:ban:([1-9][0-9]{0,15}):(\d{1,7})$/',$data,$mm)){
            if(!in_array($id,array_map('intval',$GLOBALS['admin']??[]),true))return true;
            $target=(int)$mm[1];$minutes=(int)$mm[2];$until=$minutes?time()+min($minutes,525600)*60:0;
            self::stmt($db,'INSERT INTO bot_feature_mutes(user_id,until_at,reason,actor,created) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE until_at=VALUES(until_at),reason=VALUES(reason),actor=VALUES(actor),created=VALUES(created)',[$target,$until,'report review',$id,time()]);
            self::stmt($db,'INSERT INTO bot_feature_events(user_id,kind,detail,created) VALUES(?,?,?,?)',[$target,'moderation',$minutes?'mute:'.$minutes:'permanent',$id?time():time()]);self::send($id,$minutes?'محدودیت موقت اعمال شد.':'مسدودسازی دائمی اعمال شد.');return true;
        }
        if(preg_match('/^bf:tonapprove:(\d+)$/',$data,$mm)){
            if(!in_array($id,array_map('intval',$GLOBALS['admin']??[]),true))return true;
            self::send($id,'ابتدا تراکنش را در مرورگر معتبر TON بررسی کنید. از داخل این نمونهٔ ساده نمی‌توان صحت زنجیره را خودکار راستی‌آزمایی کرد.');return true;
        }
        return false;
    }

    public static function handle(mysqli $db, object $update): bool
    {
        if(self::payment($db,$update))return true;
        $m=$update->message??null;$id=(int)($m->from->id??$update->callback_query->from->id??0);
        if(!$id)return false;
        $mute=self::row($db,'SELECT until_at FROM bot_feature_mutes WHERE user_id=?',[$id]);
        if($mute && ((int)$mute['until_at']===0 || (int)$mute['until_at']>time())) {
            self::send((int)($m->chat->id??$id),'حساب شما فعلاً برای ارسال پیام محدود شده است.');return true;
        }
        if($m && ($m->chat->type??'')==='private' && !empty($m->text) && !str_starts_with(trim($m->text),'/')) {
            $now=time();self::stmt($db,'INSERT INTO bot_feature_rate(user_id,window_start,count,last_message) VALUES(?,?,1,?) ON DUPLICATE KEY UPDATE count=IF(window_start<? ,1,count+1),window_start=IF(window_start<? ,VALUES(window_start),window_start),last_message=VALUES(last_message)',[$id,$now,$now,$now-10,$now-10]);
            $rate=self::row($db,'SELECT count FROM bot_feature_rate WHERE user_id=?',[$id]);
            if((int)($rate['count']??0)>8){self::stmt($db,'INSERT INTO bot_feature_events(user_id,kind,detail,created) VALUES(?,?,?,?)',[$id,'rate_limit','more than 8 messages in 10 seconds',$now]);self::send((int)$m->chat->id,'پیام‌ها خیلی سریع ارسال می‌شوند؛ چند لحظه صبر کن.');return true;}
        }
        return self::route($db,$update);
    }
}
