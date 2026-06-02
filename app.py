import os
import sys
import psutil
import schedule
import requests
import time
import subprocess
from threading import Thread
from flask import Flask, render_template_string, request, redirect, url_for, session, flash, jsonify
from flask_socketio import SocketIO, emit
from werkzeug.utils import secure_filename

app = Flask(__name__)
app.secret_key = 'TITAN_PREMIUM_HOSTING_2026'

# إعداد SocketIO مع دعم eventlet/gevent للبث الفوري
socketio = SocketIO(app, cors_allowed_origins="*", async_mode='eventlet')

# مجلد وهمي داخل السيرفر لتخزين ملفات المستخدمين بشكل معزول نسبياً
BASE_USER_DIR = os.path.join(os.getcwd(), 'users_storage')
if not os.path.exists(BASE_USER_DIR):
    os.makedirs(BASE_USER_DIR)

# قاعدة بيانات مؤقتة في الذاكرة (Memory DB) لتسهيل الرفع الفوري على Railway دون تعقيد إعدادات SQL
USERS = {
    'admin': {'password': 'adminpassword', 'is_admin': True, 'main_file': 'main.py', 'pid': None}
}

# ----------------- وظائف المراقبة والجدولة (psutil & schedule) -----------------
def monitor_system():
    """مراقبة استهلاك السيرفر وبثها فورياً عبر الـ WebSockets"""
    cpu = psutil.cpu_percentage(interval=1)
    ram = psutil.virtual_memory().percent
    # بث البيانات لجميع المتصلين باللوحة
    socketio.emit('sys_stats', {'cpu': cpu, 'ram': ram})

def run_schedule():
    """تشغيل الجدولة في الخلفية للتحقق من استهلاك النظام كل 5 ثوانٍ"""
    schedule.every(5).seconds.do(monitor_system)
    while True:
        schedule.run_pending()
        time.sleep(1)

# تشغيل خيط (Thread) الجدولة والمراقبة فور تشغيل السيرفر
Thread(target=run_schedule, daemon=True).start()

# ----------------- واجهات الـ HTML الاحترافية المتجاوبة -----------------
LAYOUT = """
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>منصة استضافة التيرمنال المطور</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/socketio/4.0.1/socketio.js"></script>
    <style>
        body { font-family: Arial, sans-serif; background: #0f111a; color: #a6accd; margin: 0; padding: 0; }
        .navbar { background: #1a1c29; padding: 15px 30px; display: flex; justify-content: space-between; border-bottom: 2px solid #232635; }
        .navbar a { color: #ff5370; text-decoration: none; margin-right: 15px; font-weight: bold; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
        .card { background: #1a1c29; padding: 25px; border-radius: 8px; border: 1px solid #232635; }
        .card h3 { margin-top: 0; color: #fff; border-bottom: 1px solid #232635; padding-bottom: 10px; }
        input[type="text"], input[type="password"], select { width: 100%; padding: 10px; margin: 10px 0; background: #232635; border: 1px solid #3b3f5c; color: #fff; border-radius: 4px; box-sizing: border-box; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; color: #fff; text-decoration: none; display: inline-block; }
        .btn-blue { background: #007bff; } .btn-green { background: #2cb57e; } .btn-red { background: #ff5370; }
        .terminal { background: #000; color: #69f0ae; padding: 15px; font-family: monospace; border-radius: 4px; height: 200px; overflow-y: auto; white-space: pre-wrap; margin-top: 15px; }
        .stat-box { display: flex; gap: 15px; margin-bottom: 20px; }
        .stat-item { background: #232635; padding: 10px 20px; border-radius: 4px; flex: 1; text-align: center; font-weight: bold; color: #fff; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: right; border-bottom: 1px solid #232635; }
        .flash { background: #ff5370; color: white; padding: 10px; border-radius: 4px; margin-bottom: 15px; text-align: center; }
    </style>
</head>
<body>
    <div class="navbar">
        <h2 style="margin:0; color:#80cbc4;">🚀 VibeHost Engine v2</h2>
        <div>
            {% if session.get('username') %}
                <span style="color:#fff">المستضيف: <b>{{ session['username'] }}</b></span> | 
                {% if session.get('is_admin') %}<a href="/admin" style="color:#80cbc4;">لوحة الأدمن</a> |{% endif %}
                <a href="/logout">تسجيل الخروج</a>
            {% endif %}
        </div>
    </div>
    <div class="container">
        {% with messages = get_flashed_messages() %}
          {% if messages %}<div class="flash">{{ messages[0] }}</div>{% endif %}
        {% endwith %}
        {% block content %}{% endblock %}
    </div>

    <script>
        // الاتصال بالبث الفوري الفعلي للمنصة
        var socket = io();
        socket.on('sys_stats', function(data) {
            if(document.getElementById('cpu_val')) {
                document.getElementById('cpu_val').innerText = data.cpu + '%';
                document.getElementById('ram_val').innerText = data.ram + '%';
            }
        });
        
        // استقبال مخرجات التيرمنال لايف من السيرفر الخلفي
        socket.on('terminal_stream', function(data) {
            var term = document.getElementById('live_terminal');
            if(term) {
                term.innerText += data.text;
                term.scrollTop = term.scrollHeight;
            }
        });
    </script>
</body>
</html>
"""

LOGIN_HTML = """
{% extends "layout" %}
{% block content %}
<div style="max-width: 400px; margin: 80px auto;" class="card">
    <h3>🔐 تسجيل الدخول إلى السيرفر</h3>
    <form method="POST">
        <input type="text" name="username" placeholder="اسم المستخدم" required>
        <input type="password" name="password" placeholder="كلمة المرور" required>
        <button type="submit" class="btn btn-blue" style="width: 100%;">دخول الآمن</button>
    </form>
    <p style="text-align: center; margin-top:15px;">ليس لديك مساحة؟ <a href="/register" style="color:#80cbc4;">أنشئ حسابك الآن</a></p>
</div>
{% endblock %}
"""

REGISTER_HTML = """
{% extends "layout" %}
{% block content %}
<div style="max-width: 400px; margin: 80px auto;" class="card">
    <h3>🚀 حجز استضافة وبيئة بايثون جديدة</h3>
    <form method="POST">
        <input type="text" name="username" placeholder="اختر اسم مستخدم" required>
        <input type="password" name="password" placeholder="اختر كلمة مرور قوية" required>
        <button type="submit" class="btn btn-green" style="width: 100%;">تفعيل الاستضافة الفورية</button>
    </form>
    <p style="text-align: center; margin-top:15px;"><a href="/login" style="color:#80cbc4;">تسجيل الدخول للمشتركين</a></p>
</div>
{% endblock %}
"""

DASHBOARD_HTML = """
{% extends "layout" %}
{% block content %}
<div class="stat-box">
    <div class="stat-item">المعالج الذكي (CPU): <span id="cpu_val" style="color:#69f0ae;">--</span></div>
    <div class="stat-item">الذاكرة العشوائية (RAM): <span id="ram_val" style="color:#69f0ae;">--</span></div>
    <div class="stat-item">حالة العملية الخلفية: 
        <span style="color: {% if user_data.pid %} #69f0ae {% else %} #ff5370 {% endif %};">
            {% if user_data.pid %} تعمل (PID: {{ user_data.pid }}) {% else %} متوقفة ⏹️ {% endif %}
        </span>
    </div>
</div>

<div class="grid">
    <div>
        <div class="card" style="margin-bottom: 25px;">
            <h3>⚙️ لوحة إدارة الملف والتنفيذ</h3>
            <p>ملف التشغيل الحالي الافتراضي للـ Script: <b style="color:#ffb62c;">{{ user_data.main_file }}</b></p>
            <form method="POST" action="/change_main" style="display:flex; gap:10px;">
                <input type="text" name="main_file" value="{{ user_data.main_file }}" style="margin:0;" required>
                <button type="submit" class="btn btn-blue">تعديل ملف التشغيل</button>
            </form>
            <div style="margin-top: 20px; display: flex; gap: 10px;">
                <a href="/start_script" class="btn btn-green">▶️ تشغيل السكريبت لايف</a>
                <a href="/stop_script" class="btn btn-red">⏹️ إجبار الإيقاف الحالي</a>
            </div>
        </div>

        <div class="card">
            <h3>💻 التيرمنال البث الحي (Live Stream Box)</h3>
            <div class="terminal" id="live_terminal">$ نظام البث الفوري جاهز ومستعد لتلقي البيانات المتدفقة...&#10;</div>
            <form method="POST" action="/run_custom_cmd" style="display:flex; margin-top: 15px; gap: 10px;">
                <input type="text" name="cmd" placeholder="اكتب أمر سريع (مثال: pip install requests أو python -V)" style="margin:0;" required>
                <button type="submit" class="btn btn-blue">تنفيذ الأوامر</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h3>📁 مدير ملفات العميل المتقدم (File Manager)</h3>
        <div style="background:#232635; padding:15px; border-radius:6px; margin-bottom:15px;">
            <form method="POST" action="/make_item" style="display:flex; gap:10px; margin-bottom:10px;">
                <input type="text" name="item_name" placeholder="اسم الملف أو المجلد الجديد" style="margin:0;" required>
                <select name="item_type" style="margin:0; width:100px;">
                    <option value="file">📄 ملف</option>
                    <option value="folder">📁 مجلد</option>
                </select>
                <button type="submit" class="btn btn-green">إضافة</button>
            </form>
            <form method="POST" action="/upload_to_storage" enctype="multipart/form-data" style="display:flex; justify-content:space-between; align-items:center; margin-top:15px;">
                <input type="file" name="file_upload" required>
                <button type="submit" class="btn btn-blue">⬆️ رفع للمساحة</button>
            </form>
        </div>

        <h4>الملفات البرمجية المستضافة:</h4>
        <table>
            <thead>
                <tr>
                    <th>الاسم</th>
                    <th>النوع</th>
                    <th>الإجراء</th>
                </tr>
            </thead>
            <tbody>
                {% for file in file_list %}
                <tr>
                    <td>{{ file.name }}</td>
                    <td>{{ '📁 مجلد' if file.is_dir else '📄 ملف نصي' }}</td>
                    <td><a href="/remove_item?name={{ file.name }}" style="color:#ff5370; font-weight:bold; text-decoration:none;">حذف 🗑️</a></td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
</div>
{% endblock %}
"""

ADMIN_HTML = """
{% extends "layout" %}
{% block content %}
<div class="card">
    <h3>👑 إدارة النظام العام (الأدمن)</h3>
    <a href="/dashboard" class="btn btn-blue">العودة للوحة التحكم</a>
    <br><br>
    <table border="1" style="width:100%; border-color:#232635;">
        <tr>
            <th>المستخدم</th>
            <th>ملف التشغيل الرئيسي</th>
            <th>رقم العملية النشطة في الخلفية (PID)</th>
        </tr>
        {% for name, data in users.items() %}
        <tr>
            <td>{{ name }}</td>
            <td><code>{{ data.main_file }}</code></td>
            <td><span style="color:#69f0ae;">{{ data.pid or 'لا توجد عملية جارية' }}</span></td>
        </tr>
        {% endfor %}
    </table>
</div>
{% endblock %}
"""

# ----------------- مسارات ومنطق استضافة الويب (Routes) -----------------

@app.route('/layout')
def render_layout(): return LAYOUT

@app.route('/')
def index(): return redirect(url_for('login'))

@app.route('/register', methods=['GET', 'POST'])
def register():
    if request.method == 'POST':
        user = request.form['username'].strip()
        pwd = request.form['password']
        if user in USERS:
            flash('المستخدم محجوز بالسيرفر مسبقاً!')
            return redirect(url_for('register'))
        USERS[user] = {'password': pwd, 'is_admin': False, 'main_file': 'main.py', 'pid': None}
        os.makedirs(os.path.join(BASE_USER_DIR, user), exist_ok=True)
        with open(os.path.join(BASE_USER_DIR, user, 'main.py'), 'w') as f:
            f.write("print('Welcome to VibeHost! Script is running completely live.')")
        flash('تم تخصيص السيرفر والمساحة بنجاح! سجل دخولك الآن.')
        return redirect(url_for('login'))
    return render_template_string(REGISTER_HTML)

@app.route('/login', methods=['GET', 'POST'])
def login():
    if request.method == 'POST':
        user = request.form['username'].strip()
        pwd = request.form['password']
        if user in USERS and USERS[user]['password'] == pwd:
            session['username'] = user
            session['is_admin'] = USERS[user]['is_admin']
            return redirect(url_for('dashboard'))
        flash('خطأ في اسم المستخدم أو الباسورد!')
    return render_template_string(LOGIN_HTML)

@app.route('/dashboard')
def dashboard():
    if 'username' not in session: return redirect(url_for('login'))
    user = session['username']
    user_path = os.path.join(BASE_USER_DIR, user)
    os.makedirs(user_path, exist_ok=True)
    
    # جلب قائمة الملفات من المجلد المعزول للعميل الحالي
    raw_files = os.listdir(user_path)
    file_list = []
    for f in raw_files:
        file_list.append({'name': f, 'is_dir': os.path.isdir(os.path.join(user_path, f))})
        
    return render_template_string(DASHBOARD_HTML, user_data=USERS[user], file_list=file_list)

@app.route('/change_main', methods=['POST'])
def change_main():
    if 'username' not in session: return redirect(url_for('login'))
    user = session['username']
    USERS[user]['main_file'] = request.form['main_file'].strip()
    flash('تم تبديل ملف تشغيل البوت/السكريبت الرئيسي.')
    return redirect(url_for('dashboard'))

@app.route('/make_item', methods=['POST'])
def make_item():
    if 'username' not in session: return redirect(url_for('login'))
    user = session['username']
    name = secure_filename(request.form['item_name'])
    itype = request.form['item_type']
    target = os.path.join(BASE_USER_DIR, user, name)
    if itype == 'file':
        with open(target, 'w') as f: f.write("")
    else:
        os.makedirs(target, exist_ok=True)
    return redirect(url_for('dashboard'))

@app.route('/upload_to_storage', methods=['POST'])
def upload_to_storage():
    if 'username' not in session: return redirect(url_for('login'))
    user = session['username']
    f = request.files['file_upload']
    if f:
        filename = secure_filename(f.filename)
        f.save(os.path.join(BASE_USER_DIR, user, filename))
        flash('تم رفع الملف بنجاح لمساحتك الاستضافية!')
    return redirect(url_for('dashboard'))

@app.route('/remove_item')
def remove_item():
    if 'username' not in session: return redirect(url_for('login'))
    user = session['username']
    name = secure_filename(request.args.get('name'))
    target = os.path.join(BASE_USER_DIR, user, name)
    if os.path.exists(target):
        if os.path.isdir(target): os.rmdir(target)
        else: os.remove(target)
    return redirect(url_for('dashboard'))

# ----------------- تشغيل و بث التيرمنال لايف (SocketIO Background Tasks) -----------------

def stream_process_output(user, process):
    """قراءة مخرجات السكريبت وبثها فورياً للمتصفح عبر الـ WebSockets بدون تأخير"""
    while True:
        output = process.stdout.readline()
        if output == '' and process.poll() is not None:
            break
        if output:
            socketio.emit('terminal_stream', {'text': output})
    process.wait()
    USERS[user]['pid'] = None
    socketio.emit('terminal_stream', {'text': '\n[🔴 تمت العملية أو تم إيقاف السكريبت بنجاح]\n'})

@app.route('/start_script')
def start_script():
    if 'username' not in session: return redirect(url_for('login'))
    user = session['username']
    if USERS[user]['pid'] is not None:
        flash('السكريبت يعمل بالفعل بالخلفية حالياً!')
        return redirect(url_for('dashboard'))
        
    user_path = os.path.join(BASE_USER_DIR, user)
    main_f = USERS[user]['main_file']
    
    # تشغيل سكريبت بايثون كعملية فرعية مستقلة (Subprocess) في السيرفر وتوجيه المخرجات للـ WebSockets
    proc = subprocess.Popen(
        [sys.executable, main_f],
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        cwd=user_path,
        bufsize=1
    )
    USERS[user]['pid'] = proc.pid
    
    # تشغيل خيط منفصل لتمرير المخرجات للمتصفح لايف لاين-باي-لاين
    Thread(target=stream_process_output, args=(user, proc), daemon=True).start()
    return redirect(url_for('dashboard'))

@app.route('/stop_script')
def stop_script():
    if 'username' not in session: return redirect(url_for('login'))
    user = session['username']
    pid = USERS[user]['pid']
    if pid:
        try:
            p = psutil.Process(pid)
            p.terminate()  # إغلاق العملية وقتلها فورياً عن طريق psutil
            flash('تم إنهاء السكريبت بنجاح!')
        except Exception:
            flash('العملية منتهية بالفعل.')
        USERS[user]['pid'] = None
    return redirect(url_for('dashboard'))

@app.route('/run_custom_cmd', methods=['POST'])
def run_custom_cmd():
    if 'username' not in session: return redirect(url_for('login'))
    user = session['username']
    cmd = request.form['cmd']
    user_path = os.path.join(BASE_USER_DIR, user)
    
    # تنفيذ أمر تيرمنال سريع وبث النتيجة فورا
    res = subprocess.run(cmd, shell=True, capture_output=True, text=True, cwd=user_path)
    socketio.emit('terminal_stream', {'text': f"\n$ {cmd}\n{res.stdout}{res.stderr}\n"})
    return redirect(url_for('dashboard'))

@app.route('/admin')
def admin():
    if 'username' not in session or not session.get('is_admin'): return "ممنوع", 403
    return render_template_string(ADMIN_HTML, users=USERS)

@app.route('/logout')
def logout():
    session.clear()
    return redirect(url_for('login'))

if __name__ == '__main__':
    # لتشغيل التطبيق عبر محرك الـ SocketIO المطور والمتوافق تماماً مع جيفينت وإيفينتليت وسيرفر gunicorn على ريلاي وايه
    socketio.run(app, host='0.0.0.0', port=int(os.environ.get('PORT', 5000)), debug=True)
