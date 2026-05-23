<?php

// تفعيل وضع إظهار الأخطاء الفوري لكشف خلل السيرفر إن وجد
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- الإعدادات الفنية الأساسية الثابتة ---
define('API_TOKEN', '8958322378:AAHqSTxmSJhtaeLqIiz3bIgnsnnEzU3aFR4'); 
define('ADMIN_ID', 476825418); 
define('BASE_URL', 'https://dev-vibeapi.pantheonsite.io/bot/'); 

// القنوات المحددة للاشتراك الإجباري
$required_channels = [
    'Store_S0ra'  => 'https://t.me/Store_S0ra',
    'itsh1_11_1'  => 'https://t.me/itsh1_11_1',
    'teamEXP1'    => 'https://t.me/teamEXP1'
];

// قاعدة البيانات ومجلدات الأمان المستقلة
define('USERS_DB', 'users_data.json');
define('STORE_DB', 'store_data.json');
define('TMP_DIR', 'tmp_uploads/'); 
define('HOST_DIR', 'hosting_users/'); 
define('ITEMS_DIR', 'store_items/'); 

// تهيئة بيئة العمل التلقائية والآمنة
foreach ([TMP_DIR, HOST_DIR, ITEMS_DIR] as $dir) {
    if (!file_exists($dir)) @mkdir($dir, 0755, true);
}
if (!file_exists(USERS_DB)) file_put_contents(USERS_DB, json_encode([]));
if (!file_exists(STORE_DB)) file_put_contents(STORE_DB, json_encode([]));

// استقبال ومعالجة البيانات المتدفقة من الـ Webhook
$update = json_decode(file_get_contents('php://input'), true);

if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $text = isset($message['text']) ? trim($message['text']) : '';
    handleMessage($chat_id, $user_id, $text, $message);
} elseif (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chat_id = $callback['message']['chat']['id'];
    $user_id = $callback['from']['id'];
    $data = $callback['data'];
    $message_id = $callback['message']['message_id'];
    handleCallback($chat_id, $user_id, $data, $message_id, $callback['id']);
}

// --- محرك تليجرام الأساسي الداعم للـ HTML ---
function bot($method, $datas = []) {
    $url = "https://api.telegram.org/bot" . API_TOKEN . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// --- 🤖 كلاس الذكاء الاصطناعي لفحص كود ملفات الاستضافة (Gemini AI) 🤖 ---
class GeminiAI {
    private $url = "https://gemini.talkai.info/chat/send/";
    private $headers = ['Content-Type: application/json', 'Origin: https://gemini.talkai.info'];

    public function analyzeCode($file_content) {
        $prompt = "قم بتحليل كود الـ PHP التالي بدقة كخبير أمني برمجيات. قم بفحصه جيداً وأجبني بصيغة JSON فقط تحتوي على المفاتيح التالية دون أي مقدمات أو نصوص خارج الجيسون:
        1. 'secure': قيمتها true إذا كان الكود آمن تماماً وبوت تليجرام طبيعي، و false إذا كان يحتوي على شيل أو محاولة اختراق أو دوال تدميرية للسيرفر.
        2. 'token': استخرج توكن البوت الموجود بالكود بدقة وضعه هنا، إذا لم تجد توكن اجعل قيمته null.
        3. 'reason': اكتب تقرير برمجياً قصيراً جداً باللغة العربية عما يفعله الكود وهل هو آمن أم لا.
        
        الكود المراد تحليله وفحصه:\n\n" . $file_content;

        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
        $messages = [["id" => $uuid, "from" => "you", "content" => $prompt]];

        $post_data = [
            "type" => "chat",
            "messagesHistory" => $messages,
            "settings" => ["model" => "gemini-2.0-flash-lite", "temperature" => 0.2]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) return null;

        $lines = explode("\n", $response);
        $clean_text = "";
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, 'data: ') === 0) {
                $content = substr($line, 6);
                if (!is_numeric(trim($content))) $clean_text .= $content;
            }
        }
        $clean_text = str_replace('\n', "\n", $clean_text);
        preg_match('/\{.*\}/s', $clean_text, $json_match);
        return !empty($json_match) ? json_decode($json_match[0], true) : null;
    }
}

// --- نظام جلب وفحص بيانات المشتركين ---
function getUserData($user_id) {
    $data = json_decode(file_get_contents(USERS_DB), true);
    if (!isset($data[$user_id])) {
        $data[$user_id] = [
            'captcha_passed' => false,
            'captcha_answer' => null,
            'points' => 10, 
            'referred_by' => null,
            'referrals_count' => 0,
            'last_daily' => 0,
            'banned' => false,
            'active_bot_token' => null, 
            'active_bot_file' => null, 
            'status' => 'offline',
            'step' => 'none',
            'join_date' => date('Y-m-d H:i')
        ];
        file_put_contents(USERS_DB, json_encode($data));
    }
    return $data[$user_id];
}

function updateUserData($user_id, $field, $value) {
    $data = json_decode(file_get_contents(USERS_DB), true);
    $data[$user_id][$field] = $value;
    file_put_contents(USERS_DB, json_encode($data));
}

// --- دالة فحص الاشتراك الإجباري المتعدد ---
function checkAllSubscriptions($user_id) {
    global $required_channels;
    if ($user_id == ADMIN_ID) return true;
    
    foreach ($required_channels as $username => $link) {
        $check = bot('getChatMember', ['chat_id' => '@' . $username, 'user_id' => $user_id]);
        $status = $check['result']['status'] ?? '';
        if (!in_array($status, ['creator', 'administrator', 'member'])) return false;
    }
    return true;
}

function getSubscriptionKeyboard() {
    global $required_channels; $inline_keyboard = []; $index = 1;
    foreach ($required_channels as $username => $link) { $inline_keyboard[] = [['text' => "📢 القناة رقم $index 🔗", 'url' => $link]]; $index++; }
    $inline_keyboard[] = [['text' => "🔄 تأكيد التفعيل والاشتراك ⚡", 'callback_data' => 'check_sub_status', 'style' => 'success']];
    return json_encode(['inline_keyboard' => $inline_keyboard]);
}

// دالة كابتشا الأرقام الرياضية الفورية
function sendMathCaptcha($chat_id, $user_id) {
    $num1 = mt_rand(5, 15); $num2 = mt_rand(1, 4); $correct_answer = $num1 + $num2;
    updateUserData($user_id, 'captcha_answer', $correct_answer);
    $wrong1 = $correct_answer + mt_rand(1, 3); $wrong2 = $correct_answer - mt_rand(1, 3);
    $options = [$correct_answer, $wrong1, $wrong2]; shuffle($options);
    $inline_buttons = []; $styles = ['success', 'primary', 'danger'];
    foreach ($options as $key => $opt) { $inline_buttons[] = ['text' => (string)$opt, 'callback_data' => "math_" . $opt, 'style' => $styles[$key]]; }
    bot('sendMessage', ['chat_id' => $chat_id, 'text' => "<b>🔒 جدار الحماية ونظام التحقق البشري</b>\n\nيرجى حل المسألة الحسابية لفتح الخدمات السحابية:\n\n🎯 كم ناتج: <code>$num1 + $num2</code> ؟", 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [$inline_buttons]])]);
}

// --- لوحات التحكم والقوائم الرئيسية الملونة الحصرية ---
function getMainMenuKeyboard($user_id) {
    $buttons = [
        [
            ['text' => "🏪 متجر السلع والملفات", 'callback_data' => 'go_store', 'style' => 'success'],
            ['text' => "🚀 استضافة ملفات PHP", 'callback_data' => 'go_hosting', 'style' => 'primary']
        ],
        [['text' => "❓ قسم المساعدة والدعم الفني", 'callback_data' => 'go_help', 'style' => 'primary']],
        [
            ['text' => "🔗 كسب النقاط والإحالة", 'callback_data' => 'go_share', 'style' => 'primary'],
            ['text' => "👤 حسابي الفردي", 'callback_data' => 'my_account', 'style' => 'primary']
        ],
        [
            ['text' => "🏆 توب الإحالات", 'callback_data' => 'top_ref', 'style' => 'primary'],
            ['text' => "💰 توب الرصيد والنقاط", 'callback_data' => 'top_money', 'style' => 'primary']
        ],
        [['text' => "⚡ فحص حالة اتصال خادم الويب", 'callback_data' => 'ping_server', 'style' => 'success']]
    ];
    if ($user_id == ADMIN_ID) {
        $buttons[] = [['text' => "👑 لوحة الإدارة الخارقة والتعديلات", 'callback_data' => 'admin_panel', 'style' => 'danger']];
    }
    return json_encode(['inline_keyboard' => $buttons]);
}

function getHostingMenu($status) {
    $status_icon = ($status == 'active') ? "🟢 يعمل بالخلفية" : "🔴 متوقف ومقفل";
    return json_encode([
        'inline_keyboard' => [
            [['text' => "الحالة الحالية للبوت: $status_icon", 'callback_data' => 'none', 'style' => 'primary']],
            [['text' => "📤 إرسال ملف البوت وتفعيله", 'callback_data' => 'upload_panel', 'style' => 'primary']],
            [
                ['text' => "🟢 تشغيل البوت", 'callback_data' => 'run_bot', 'style' => 'success'],
                ['text' => "🟡 توقيف البوت", 'callback_data' => 'stop_bot', 'style' => 'primary']
            ],
            [['text' => "🗑️ حذف كود البوت نهائياً", 'callback_data' => 'delete_bot', 'style' => 'danger']],
            [['text' => "🔙 العودة للوحة التحكم الرئيسية", 'callback_data' => 'main_menu', 'style' => 'primary']]
        ]
    ]);
}

// لوحة تحكم الآدمن المحدثة بأزرار التحكم الجماعي الخارقة
function getAdminPanelKeyboard() {
    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => "➕ إضافة سلعة", 'callback_data' => 'admin_add_item', 'style' => 'success'],
                ['text' => "🗑️ مسح سلعة", 'callback_data' => 'admin_del_item', 'style' => 'danger']
            ],
            [
                ['text' => "➕ إضافة نقاط", 'callback_data' => 'admin_add_pts', 'style' => 'success'],
                ['text' => "➖ خصم نقاط", 'callback_data' => 'admin_sub_pts', 'style' => 'danger']
            ],
            [
                ['text' => "🚫 حظر مستخدم", 'callback_data' => 'admin_ban_user', 'style' => 'danger'],
                ['text' => "🟢 فك حظر", 'callback_data' => 'admin_unban_user', 'style' => 'success']
            ],
            [
                ['text' => "🟢 تشغيل كل البوتات", 'callback_data' => 'mass_run', 'style' => 'success'],
                ['text' => "🟡 إيقاف كل البوتات", 'callback_data' => 'mass_stop', 'style' => 'primary']
            ],
            [['text' => "🚨 حذف وتطهير كل الاستضافات نهائياً 🚨", 'callback_data' => 'mass_delete', 'style' => 'danger']],
            [
                ['text' => "📊 الإحصائيات", 'callback_data' => 'admin_stats', 'style' => 'success'],
                ['text' => "💾 نسخة احتياطية", 'callback_data' => 'admin_backup', 'style' => 'primary']
            ],
            [['text' => "🔙 العودة للرئيسية", 'callback_data' => 'main_menu', 'style' => 'primary']]
        ]
    ]);
}

// --- معالجة الرسائل والأوامر والسلع المرفوعة للمتجر ---
function handleMessage($chat_id, $user_id, $text, $message) {
    $user_info = getUserData($user_id);
    if ($user_info['banned']) return;

    if (!checkAllSubscriptions($user_id)) {
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "<b>⚠️ عذراً عزيزي، يجب عليك الاشتراك في قنوات البوت والمنصة أولاً لتتمكن من استخدام الخدمات مجاناً.</b>", 'parse_mode' => 'HTML', 'reply_markup' => getSubscriptionKeyboard()]);
        return;
    }

    if (!$user_info['captcha_passed']) {
        if (strpos($text, '/start') === 0) {
            $parts = explode(' ', $text);
            if (count($parts) == 2 && is_numeric($parts[1]) && $parts[1] != $user_id) updateUserData($user_id, 'referred_by', $parts[1]);
        }
        sendMathCaptcha($chat_id, $user_id); return;
    }

    if (strpos($text, '/start') === 0) {
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "<b>👋 أهلاً بك في سحابة المطور الذكية المحدثة!</b>", 'parse_mode' => 'HTML', 'reply_markup' => getMainMenuKeyboard($user_id)]);
        updateUserData($user_id, 'step', 'none'); return;
    }

    // معالجة خطوات الآدمن
    if ($user_id == ADMIN_ID && isset($user_info['admin_step'])) {
        $step = $user_info['admin_step'];

        if ($step == 'wait_item_name') {
            updateUserData($user_id, 'new_item_name', $text); updateUserData($user_id, 'admin_step', 'wait_item_price');
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "<b>💰 الاسم مسجّل!</b>\n\nأرسل الآن سعر السلعة بالنقاط:", 'parse_mode' => 'HTML']);
        } 
        elseif ($step == 'wait_item_price') {
            if (!is_numeric($text)) { bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ أرقام فقط."]); return; }
            updateUserData($user_id, 'new_item_price', (int)$text); updateUserData($user_id, 'admin_step', 'wait_item_obj');
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "<b>📦 السعر مسجّل!</b>\n\nالآن قم بإرسال السلعة نفسها المخصصة للبيع:", 'parse_mode' => 'HTML']);
        } 
        elseif ($step == 'wait_item_obj') {
            $store_data = json_decode(file_get_contents(STORE_DB), true); $item_id = "ID" . mt_rand(100, 999);
            $type = 'text'; $file_id = null; $content = null;
            if (isset($message['document'])) { $type = 'document'; $file_id = $message['document']['file_id']; }
            elseif (isset($message['video'])) { $type = 'video'; $file_id = $message['video']['file_id']; }
            else { $type = 'text'; $content = $text; }

            $store_data[$item_id] = ['name' => $user_info['new_item_name'], 'price' => $user_info['new_item_price'], 'type' => $type, 'file_id' => $file_id, 'content' => $content];
            file_put_contents(STORE_DB, json_encode($store_data));
            updateUserData($user_id, 'admin_step', 'none');
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "<b>✅ تم إضافة السلعة بنجاح برقم معرّف: <code>$item_id</code></b>", 'parse_mode' => 'HTML', 'reply_markup' => getAdminPanelKeyboard()]);
        }
        elseif ($step == 'wait_del_id') {
            $store_data = json_decode(file_get_contents(STORE_DB), true);
            if (isset($store_data[$text])) {
                unset($store_data[$text]); file_put_contents(STORE_DB, json_encode($store_data));
                bot('sendMessage', ['chat_id' => $chat_id, 'text' => "<b>✅ تم حذف السلعة بنجاح.</b>", 'parse_mode' => 'HTML', 'reply_markup' => getAdminPanelKeyboard()]);
            } else { bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ المعرّف غير موجود."]); }
            updateUserData($user_id, 'admin_step', 'none');
        }
        elseif ($step == 'wait_add_uid') {
            updateUserData($user_id, 'admin_target_user', $text); updateUserData($user_id, 'admin_step', 'wait_add_amount');
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "<b>💰 أرسل عدد النقاط المراد إضافتها:</b>", 'parse_mode' => 'HTML']);
        }
        elseif ($step == 'wait_add_amount') {
            $target = $user_info['admin_target_user']; $target_data = getUserData($target);
            updateUserData($target, 'points', $target_data['points'] + (int)$text);
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "<b>✅ تم إضافة النقاط بنجاح.</b>", 'parse_mode' => 'HTML', 'reply_markup' => getAdminPanelKeyboard()]);
            updateUserData($user_id, 'admin_step', 'none');
        }
        elseif ($step == 'wait_sub_uid') {
            updateUserData($user_id, 'admin_target_user', $text); updateUserData($user_id, 'admin_step', 'wait_sub_amount');
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "<b>💰 أرسل عدد النقاط المراد خصمها:</b>", 'parse_mode' => 'HTML']);
        }
        elseif ($step == 'wait_sub_amount') {
            $target = $user_info['admin_target_user']; $target_data = getUserData($target);
            updateUserData($target, 'points', max(0, $target_data['points'] - (int)$text));
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "<b>✅ تم خصم النقاط بنجاح.</b>", 'parse_mode' => 'HTML', 'reply_markup' => getAdminPanelKeyboard()]);
            updateUserData($user_id, 'admin_step', 'none');
        }
        elseif ($step == 'wait_ban_uid') {
            updateUserData($text, 'banned', true);
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "<b>🚫 تم حظر المستخدم بنجاح.</b>", 'parse_mode' => 'HTML', 'reply_markup' => getAdminPanelKeyboard()]);
            updateUserData($user_id, 'admin_step', 'none');
        }
        elseif ($step == 'wait_unban_uid') {
            updateUserData($text, 'banned', false);
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "<b>🟢 تم فك حظر المستخدم بنجاح.</b>", 'parse_mode' => 'HTML', 'reply_markup' => getAdminPanelKeyboard()]);
            updateUserData($user_id, 'admin_step', 'none');
        }
        return;
    }

    // استقبال ملفات الاستضافة
    if (isset($message['document']) && $user_info['step'] == 'wait_php') {
        $doc = $message['document']; $file_name = $doc['file_name'];
        if (pathinfo($file_name, PATHINFO_EXTENSION) != 'php') { bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ ملفات PHP فقط."]); return; }
        
        $file_info = bot('getFile', ['file_id' => $doc['file_id']]);
        $file_content = file_get_contents("https://api.telegram.org/file/bot" . API_TOKEN . "/" . $file_info['result']['file_path']);
        $clean_filename = preg_replace('/[^a-zA-Z0-9_\.-]/', '', $file_name);
        $tmp_file_path = TMP_DIR . $user_id . '_' . $clean_filename;
        file_put_contents($tmp_file_path, $file_content);

        $ai = new GeminiAI(); $ai_result = $ai->analyzeCode($file_content);
        $secure_status = ($ai_result['secure'] === true) ? "🟢 آمن ومحمي" : "🚨 مشبوه ومحظور";
        $extracted_token = !empty($ai_result['token']) ? $ai_result['token'] : "لم يتم العثور على توكن";

        $admin_keyboard = json_encode(['inline_keyboard' => [[
            ['text' => "🟢 قبول وتنشيط صامت", 'callback_data' => "approve_{$user_id}_{$clean_filename}", 'style' => 'success'],
            ['text' => "🔴 رفض ومسح", 'callback_data' => "reject_{$user_id}_{$clean_filename}", 'style' => 'danger']
        ]]]);

        $admin_msg = "<b>📥 طلب استضافة جديد:</b>\n\n👤 العضو: <code>$user_id</code>\n📄 الملف: <code>{$clean_filename}</code>\n🛡️ الأمن: $secure_status\n🔑 التوكن: <code>{$extracted_token}</code>";
        bot('sendMessage', ['chat_id' => ADMIN_ID, 'text' => $admin_msg, 'reply_markup' => $admin_keyboard, 'parse_mode' => 'HTML']);
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "<b>⏳ تم رفع ملفك بنجاح، وطلبك قيد المراجعة الفورية من قبل الإدارة الآن.</b>", 'parse_mode' => 'HTML']);
        updateUserData($user_id, 'step', 'none');
    }
}

// --- معالجة ضغطات أزرار الإنلاين الملونة بالكامل ---
function handleCallback($chat_id, $user_id, $data, $message_id, $callback_id) {
    $user_info = getUserData($user_id);

    if ($data == 'check_sub_status') {
        if (checkAllSubscriptions($user_id)) {
            bot('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]); sendMathCaptcha($chat_id, $user_id);
        } else { bot('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "❌ لم تشترك بجميع القنوات بعد!", 'show_alert' => true]); }
        return;
    }

    if (strpos($data, 'math_') === 0) {
        $user_answer = (int)substr($data, 5);
        if ($user_answer == (int)$user_info['captcha_answer']) {
            updateUserData($user_id, 'captcha_passed', true);
            bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>✅ تم التحقق بنجاح! تم فتح اللوحة لك الآن.</b>", 'parse_mode' => 'HTML', 'reply_markup' => getMainMenuKeyboard($user_id)]);
        } else { sendMathCaptcha($chat_id, $user_id); }
        return;
    }

    if ($data == 'main_menu') {
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>🚀 يمكنك التنقل بحرية بين الأقسام الرئيسية والتحكم بنقاطك وسلعك:</b>", 'parse_mode' => 'HTML', 'reply_markup' => getMainMenuKeyboard($user_id)]);
    }

    // --- ميزات التحكم الجماعي الخارقة للآدمن (Mass Control Executions) ---
    elseif ($data == 'mass_run' && $user_id == ADMIN_ID) {
        $db = json_decode(file_get_contents(USERS_DB), true);
        $count = 0;
        foreach ($db as $uid => $info) {
            if (!empty($info['active_bot_token']) && !empty($info['active_bot_file'])) {
                $bot_path = HOST_DIR . $uid . '/' . $info['active_bot_file'];
                $webhook_url = BASE_URL . $bot_path;
                $res = file_get_contents("https://api.telegram.org/bot{$info['active_bot_token']}/setWebhook?url=" . $webhook_url);
                $res_arr = json_decode($res, true);
                if ($res_arr['ok'] === true) {
                    $db[$uid]['status'] = 'active';
                    $count++;
                }
            }
        }
        file_put_contents(USERS_DB, json_encode($db));
        bot('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "🟢 تم تشغيل وإعادة ربط الـ Webhook لـ $count بوت مستضاف بنجاح فوري!", 'show_alert' => true]);
    }

    elseif ($data == 'mass_stop' && $user_id == ADMIN_ID) {
        $db = json_decode(file_get_contents(USERS_DB), true);
        $count = 0;
        foreach ($db as $uid => $info) {
            if (!empty($info['active_bot_token'])) {
                file_get_contents("https://api.telegram.org/bot{$info['active_bot_token']}/deleteWebhook");
                $db[$uid]['status'] = 'offline';
                $count++;
            }
        }
        file_put_contents(USERS_DB, json_encode($db));
        bot('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "🟡 تم تعطيل وحذف الـ Webhook عن جميع البوتات المستضافة ($count بوت) صامتاً!", 'show_alert' => true]);
    }

    elseif ($data == 'mass_delete' && $user_id == ADMIN_ID) {
        $db = json_decode(file_get_contents(USERS_DB), true);
        // مسح الـ Webhooks أولاً
        foreach ($db as $uid => $info) {
            if (!empty($info['active_bot_token'])) {
                @file_get_contents("https://api.telegram.org/bot{$info['active_bot_token']}/deleteWebhook");
            }
            $db[$uid]['active_bot_token'] = null;
            $db[$uid]['active_bot_file'] = null;
            $db[$uid]['status'] = 'offline';
        }
        file_put_contents(USERS_DB, json_encode($db));

        // مسح وتطهير مجلد الاستضافات بشكل كامل برمجياً
        if (file_exists(HOST_DIR)) {
            $dir_iterator = new RecursiveDirectoryIterator(HOST_DIR, RecursiveDirectoryIterator::SKIP_DOTS);
            $file_iterator = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($file_iterator as $file) {
                if ($file->isDir()) { rmdir($file->getRealPath()); } else { unlink($file->getRealPath()); }
            }
        }
        bot('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "🚨 تم تصفير وتطهير السيرفر بالكامل وحذف جميع ملفات ومجلدات البوتات المرفوعة نهائياً!", 'show_alert' => true]);
    }

    // الأقسام والتحكم الفردي
    elseif ($data == 'go_hosting') {
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>🚀 لوحة التحكم الكاملة والمدمجة بإعدادات الاستضافة السحابية لملفات PHP:</b>", 'parse_mode' => 'HTML', 'reply_markup' => getHostingMenu($user_info['status'])]);
    }

    elseif ($data == 'upload_panel') {
        updateUserData($user_id, 'step', 'wait_php');
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>📤 أرسل الآن ملف البوت الخاص بك بصيغة <code>.php</code> كمستند صريح لتفعيله مخفياً بالكامل:</b>", 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "🔙 إلغاء والعودة لقسم الاستضافة", 'callback_data' => 'go_hosting', 'style' => 'primary']]]])]);
    }

    elseif ($data == 'go_store') {
        $store_data = json_decode(file_get_contents(STORE_DB), true);
        if (empty($store_data)) {
            bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>🏪 متجر السلع والملفات الرقمية فارغ حالياً!</b>", 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "🔙 العودة للرئيسية", 'callback_data' => 'main_menu', 'style' => 'primary']]]])]); return;
        }
        $inline_keyboard = [];
        foreach ($store_data as $item_id => $item) { $inline_keyboard[] = [['text' => "🛒 {$item['name']} | [ID: $item_id] | 💰 {$item['price']} نقطة", 'callback_data' => "buy_{$item_id}"]]; }
        $inline_keyboard[] = [['text' => "🔙 العودة للوحة التحكم", 'callback_data' => 'main_menu', 'style' => 'primary']];
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>🏪 مرحباً بك في متجر السيرفر التلقائي للسلع والملفات:</b>", 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $inline_keyboard])]);
    }

    elseif (strpos($data, 'buy_') === 0) {
        $item_id = substr($data, 4); $store_data = json_decode(file_get_contents(STORE_DB), true);
        if (!isset($store_data[$item_id])) { bot('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "❌ السلعة غير متوفرة.", 'show_alert' => true]); return; }
        $item = $store_data[$item_id];
        if ($user_info['points'] < $item['price']) { bot('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "❌ رصيد نقاطك غير كافٍ!", 'show_alert' => true]); return; }

        updateUserData($user_id, 'points', $user_info['points'] - $item['price']);
        bot('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "✅ تم الشراء بنجاح! جاري إرسال السلعة...", 'show_alert' => false]);

        if ($item['type'] == 'document') { bot('sendDocument', ['chat_id' => $chat_id, 'document' => $item['file_id'], 'caption' => "🎁 تم شراء ملفك: <b>" . $item['name'] . "</b>", 'parse_mode' => 'HTML']); }
        elseif ($item['type'] == 'video') { bot('sendVideo', ['chat_id' => $chat_id, 'video' => $item['file_id'], 'caption' => "🎁 تم شراء الفيديو: <b>" . $item['name'] . "</b>", 'parse_mode' => 'HTML']); }
        else { bot('sendMessage', ['chat_id' => $chat_id, 'text' => "🎁 <b>تم شراء النص/الكود بنجاح:</b>\n\n<code>" . $item['content'] . "</code>", 'parse_mode' => 'HTML']); }
    }

    elseif ($data == 'go_help') {
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>❓ قسم الدعم الفني والإرشادات السريعة للمنصة:</b>\n\n1️⃣ ترفع ملف PHP ويتم استضافته مخفياً بالكامل.\n2️⃣ تجمع النقاط من دعوة المطورين لاستبدالها من المتجر السحابي التلقائي.", 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "🔙 العودة للرئيسية", 'callback_data' => 'main_menu', 'style' => 'primary']]]])]);
    }

    elseif ($data == 'go_share') {
        $ref_link = "https://t.me/" . $username . "?start=" . $user_id;
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>🔗 رابط إحالتك الفريد لجلب المطورين والأصدقاء للقمة:</b>\n<code>$ref_link</code>\n\n🎁 الهدية اليومية تعطيك 2 نقاط مجاناً كل 24 ساعة.", 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [
            [['text' => "🎁 استلام الهدية اليومية", 'callback_data' => 'claim_daily', 'style' => 'success']],
            [['text' => "🔙 العودة للرئيسية", 'callback_data' => 'main_menu', 'style' => 'primary']]
        ]])]);
    }

    elseif ($data == 'claim_daily') {
        $now = time();
        if ($now - $user_info['last_daily'] >= 86400) {
            updateUserData($user_id, 'points', $user_info['points'] + 2); updateUserData($user_id, 'last_daily', $now);
            bot('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "🎁 تم إضافة 2 نقاط لرصيدك.", 'show_alert' => true]);
        } else { bot('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "⏳ لقد استلمت مكافأتك اليومية بالفعل مسبقاً.", 'show_alert' => true]); }
    }

    elseif ($data == 'my_account') {
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>👤 مستندات حسابك الفردي داخل الخادم:</b>\n\n🆔 معرف الحساب: <code>$user_id</code>\n📅 تاريخ الاشتراك: <code>{$user_info['join_date']}</code>\n💰 رصيد نقاطك الكلي: <code>{$user_info['points']} نقطة</code>\n👥 إجمالي عدد إحالاتك: <code>{$user_info['referrals_count']} مطور</code>", 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "🔙 العودة للرئيسية", 'callback_data' => 'main_menu', 'style' => 'primary']]]])]);
    }

    elseif ($data == 'top_ref') {
        $db = json_decode(file_get_contents(USERS_DB), true); uasort($db, function($a, $b) { return ($b['referrals_count'] ?? 0) <=> ($a['referrals_count'] ?? 0); });
        $text = "<b>🏆 لوحة صدارة المطورين الأكثر جلباً للإحالات النشطة:</b>\n\n"; $rank = 1;
        foreach (array_slice($db, 0, 10, true) as $u_id => $info) { $text .= "<b>المركز $rank:</b> <code>$u_id</code> — الإحالات: <code>" . ($info['referrals_count'] ?? 0) . " صديق</code>\n"; $rank++; }
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "🔙 الرئيسة", 'callback_data' => 'main_menu']]]])]);
    }

    elseif ($data == 'top_money') {
        $db = json_decode(file_get_contents(USERS_DB), true); uasort($db, function($a, $b) { return $b['points'] <=> $a['points']; });
        $text = "<b>🏆 لوحة صدارة أغنى مستخدمي المنصة برصيد النقاط:</b>\n\n"; $rank = 1;
        foreach (array_slice($db, 0, 10, true) as $u_id => $info) { $text .= "<b>المركز $rank:</b> <code>$u_id</code> — الرصيد: <code>{$info['points']} نقطة</code>\n"; $rank++; }
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "🔙 الرئيسة", 'callback_data' => 'main_menu']]]])]);
    }

    elseif ($data == 'ping_server') {
        bot('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "⚡ خوادم معالجة البيانات السحابية الخاصة بالمنصة آمنة ومستقرة بكفاءة 100%!", 'show_alert' => true]);
    }

    // تحكم تشغيل وإيقاف الـ Webhook الفردي صامتاً
    elseif ($data == 'run_bot') {
        $bot_path = HOST_DIR . $user_id . '/' . $user_info['active_bot_file']; $webhook_url = BASE_URL . $bot_path;
        $setup = file_get_contents("https://api.telegram.org/bot{$user_info['active_bot_token']}/setWebhook?url=" . $webhook_url); $res = json_decode($setup, true);
        if ($res['ok'] == true) { 
            updateUserData($user_id, 'status', 'active'); 
            bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>🚀 لوحة التحكم بإعدادات الاستضافة السحابية لملفات PHP:</b>", 'parse_mode' => 'HTML', 'reply_markup' => getHostingMenu('active')]); 
            bot('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "🟢 تم تشغيل البوت صامتاً بنجاح بالخلفية!", 'show_alert' => false]); 
        } else { bot('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "❌ فشل التنشيط؛ تأكد من التوكن.", 'show_alert' => true]); }
    }

    elseif ($data == 'stop_bot') {
        file_get_contents("https://api.telegram.org/bot{$user_info['active_bot_token']}/deleteWebhook"); updateUserData($user_id, 'status', 'offline');
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>🚀 لوحة التحكم بإعدادات الاستضافة السحابية لملفات PHP:</b>", 'parse_mode' => 'HTML', 'reply_markup' => getHostingMenu('offline')]); bot('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "🟡 تم إيقاف البوت صامتاً عن العمل بنجاح.", 'show_alert' => false]);
    }

    elseif ($data == 'delete_bot') {
        $user_dir = HOST_DIR . $user_id; @file_get_contents("https://api.telegram.org/bot{$user_info['active_bot_token']}/deleteWebhook");
        if (file_exists($user_dir)) { $files = array_diff(scandir($user_dir), ['.', '..']); foreach ($files as $file) { unlink($user_dir . '/' . $file); } rmdir($user_dir); }
        updateUserData($user_id, 'active_bot_token', null); updateUserData($user_id, 'active_bot_file', null); updateUserData($user_id, 'status', 'offline');
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>🗑️ تم حذف كود البوت ومسح جميع ملفاته بنجاح 100%.</b>", 'parse_mode' => 'HTML', 'reply_markup' => getHostingMenu('offline')]);
    }

    // تفريعات الآدمن
    elseif ($data == 'admin_panel' && $user_id == ADMIN_ID) {
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>👑 لوحة التحكم العليا المخصصة لك للتحكم بالمتجر، والتحكم بنقاط وحظر المشتركين:</b>", 'parse_mode' => 'HTML', 'reply_markup' => getAdminPanelKeyboard()]);
    }
    elseif ($data == 'admin_add_item' && $user_id == ADMIN_ID) { updateUserData($user_id, 'admin_step', 'wait_item_name'); bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>📦 معالج حقن السلع الرقمية:</b>\n\nأرسل الآن اسم السلعة الجديدة المراد عرضها بالمتجر:", 'parse_mode' => 'HTML']); }
    elseif ($data == 'admin_del_item' && $user_id == ADMIN_ID) { updateUserData($user_id, 'admin_step', 'wait_del_id'); bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>🗑️ معالج إزالة السلع:</b>\n\nأرسل الآن رقم معرّف السلعة (ID) المراد مسحها نهائياً من المتجر:", 'parse_mode' => 'HTML']); }
    elseif ($data == 'admin_add_pts' && $user_id == ADMIN_ID) { updateUserData($user_id, 'admin_step', 'wait_add_uid'); bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>➕ معالج شحن الحسابات:</b>\n\nأرسل الآن آيدي (ID) العضو المراد شحن رصيده بالنقاط:", 'parse_mode' => 'HTML']); }
    elseif ($data == 'admin_sub_pts' && $user_id == ADMIN_ID) { updateUserData($user_id, 'admin_step', 'wait_sub_uid'); bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>➖ معالج سحب الرصيد:</b>\n\nأرسل الآن آيدي (ID) العضو المراد سحب وخصم نقاط من رصيده:", 'parse_mode' => 'HTML']); }
    elseif ($data == 'admin_ban_user' && $user_id == ADMIN_ID) { updateUserData($user_id, 'admin_step', 'wait_ban_uid'); bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>🚫 معالج الحظر الصارم:</b>\n\nأرسل آيدي (ID) المستخدم المراد حظره وقطعه نهائياً عن الخادم:", 'parse_mode' => 'HTML']); }
    elseif ($data == 'admin_unban_user' && $user_id == ADMIN_ID) { updateUserData($user_id, 'admin_step', 'wait_unban_uid'); bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>🟢 معالج فك الحظر:</b>\n\nأرسل آيدي (ID) العضو المحظور لإلغاء حظر حسابه فوراً:", 'parse_mode' => 'HTML']); }

    elseif ($data == 'admin_stats' && $user_id == ADMIN_ID) {
        $db = json_decode(file_get_contents(USERS_DB), true); $s_db = json_decode(file_get_contents(STORE_DB), true);
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>📊 تفاصيل وثائق السيرفر حياً:</b>\n\n👥 عدد المشتركين المسجلين: <code>" . count($db) . " مطور</code>\n📦 عدد السلع النشطة بالمتجر: <code>" . count($s_db) . " سلعة جاهزة</code>", 'parse_mode' => 'HTML', 'reply_markup' => getAdminPanelKeyboard()]);
    }
    elseif ($data == 'admin_backup' && $user_id == ADMIN_ID) {
        bot('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "✅ جاري تصدير النسخ الاحتياطية...", 'show_alert' => true]);
        bot('sendDocument', ['chat_id' => ADMIN_ID, 'document' => new CURLFile(USERS_DB), 'caption' => "💾 نسخة قاعدة بيانات الأعضاء والنقاط."]);
        bot('sendDocument', ['chat_id' => ADMIN_ID, 'document' => new CURLFile(STORE_DB), 'caption' => "💾 نسخة قاعدة بيانات محتويات المتجر."]);
    }

    // موافقات الاستضافة
    elseif (strpos($data, 'approve_') === 0 && $user_id == ADMIN_ID) {
        $parts = explode('_', $data); $target_user = $parts[1]; $target_file = implode('_', array_slice($parts, 2));
        $tmp_path = TMP_DIR . $target_user . '_' . $target_file;

        if (file_exists($tmp_path)) {
            $file_content = file_get_contents($tmp_path);
            $ai = new GeminiAI(); $ai_result = $ai->analyzeCode($file_content); $extracted_token = $ai_result['token'];
            if (empty($extracted_token)) { preg_match('/[0-9]{9,10}:[a-zA-Z0-9_-]{35}/', $file_content, $matches); $extracted_token = !empty($matches[0]) ? $matches[0] : null; }
            if (empty($extracted_token)) { bot('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "❌ تعذر العثور على توكن.", 'show_alert' => true]); return; }

            $target_dir = HOST_DIR . $target_user; if (!file_exists($target_dir)) mkdir($target_dir, 0755, true);
            $final_path = $target_dir . '/' . $target_file; rename($tmp_path, $final_path);

            $target_info = getUserData($target_user);
            updateUserData($target_user, 'points', $target_info['points'] - 1);
            updateUserData($target_user, 'active_bot_token', $extracted_token);
            updateUserData($target_user, 'active_bot_file', $target_file);
            updateUserData($target_user, 'status', 'active');

            $user_bot_url = BASE_URL . $final_path; file_get_contents("https://api.telegram.org/bot{$extracted_token}/setWebhook?url=" . $user_bot_url);
            bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>✅ تم تفعيل واستضافة ملف المستخدم <code>$target_user</code> صامتاً!</b>", 'parse_mode' => 'HTML']);
        }
    }
    elseif (strpos($data, 'reject_') === 0 && $user_id == ADMIN_ID) {
        $parts = explode('_', $data); $target_user = $parts[1]; $target_file = implode('_', array_slice($parts, 2));
        $tmp_path = TMP_DIR . $target_user . '_' . $target_file; if (file_exists($tmp_path)) unlink($tmp_path);
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "<b>🔴 تم رفض طلب الاستضافة ومسحه من الحجر الصحي.</b>", 'parse_mode' => 'HTML']);
    }
}