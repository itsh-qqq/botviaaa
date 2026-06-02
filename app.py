import os
import tarfile
import io
from flask import Flask, render_template_string, request, redirect, url_for, session, flash
from flask_sqlalchemy import SQLAlchemy
import docker

app = Flask(__name__)
app.secret_key = 'PLATINUM_HOSTING_SUPER_SECRET_KEY_2026'

# إعداد قاعدة البيانات
app.config['SQLALCHEMY_DATABASE_URI'] = 'sqlite:///advanced_hosting.db'
app.config['SQLALCHEMY_TRACK_MODIFICATIONS'] = False
db = SQLAlchemy(app)

# الاتصال بـ Docker
try:
    client = docker.from_env()
except Exception as e:
    print("⚠️ تنبيه: تأكد من تشغيل Docker daemon على النظام!")

# ----------------- نموذج قاعدة البيانات -----------------
class User(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    username = db.Column(db.String(80), unique=True, nullable=False)
    password = db.Column(db.String(120), nullable=False)
    is_admin = db.Column(db.Boolean, default=False)
    container_id = db.Column(db.String(120), nullable=True)
    main_file = db.Column(db.String(80), default="main.py")  # اسم ملف التشغيل الافتراضي

with app.app_context():
    db.create_all()
    if not User.query.filter_by(username='admin').first():
        admin_user = User(username='admin', password='adminpassword', is_admin=True)
        db.add(admin_user)
        db.commit()

# ----------------- واجهات الـ HTML الاحترافية (CSS مدمج) -----------------

BASE_LAYOUT = """
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>منصة استضافة بايثون المتطورة</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #121420; color: #cfd8dc; margin: 0; padding: 0; }
        .navbar { background: #1b1e2e; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #25293c; }
        .navbar h2 { margin: 0; color: #00adb5; font-size: 20px; }
        .navbar a { color: #ff5252; text-decoration: none; margin-right: 15px; font-weight: bold; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
        .card { background: #1b1e2e; padding: 25px; border-radius: 10px; border: 1px solid #25293c; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
        .card h3 { margin-top: 0; color: #fff; border-bottom: 1px solid #25293c; padding-bottom: 10px; }
        input[type="text"], input[type="password"], textarea, select { width: 100%; padding: 10px; margin: 10px 0; background: #25293c; border: 1px solid #383f58; color: #fff; border-radius: 5px; box-sizing: border-box; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; color: #fff; text-decoration: none; display: inline-block; }
        .btn-blue { background: #007bff; } .btn-blue:hover { background: #0056b3; }
        .btn-green { background: #28a745; } .btn-green:hover { background: #218838; }
        .btn-red { background: #dc3545; } .btn-red:hover { background: #c82333; }
        .terminal { background: #000; color: #00ff00; padding: 15px; font-family: monospace; border-radius: 5px; height: 180px; overflow-y: auto; white-space: pre-wrap; box-shadow: inset 0 0 10px #000; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; text-align: right; border-bottom: 1px solid #25293c; }
        th { color: #00adb5; }
        .flash-msg { background: #ff5252; color: white; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>🚀 PlatinumPy PaaS v2026</h2>
        <div>
            {% if session.get('username') %}
                <span style="color: #fff">مرحباً، <b>{{ session['username'] }}</b></span> | 
                {% if session.get('is_admin') %}<a href="/admin" style="color: #00adb5;">لوحة الإدارة</a> |{% endif %}
                <a href="/logout">تسجيل الخروج</a>
            {% endif %}
        </div>
    </div>
    <div class="container">
        {% with messages = get_flashed_messages() %}
          {% if messages %}<div class="flash-msg">{{ messages[0] }}</div>{% endif %}
        {% endwith %}
        {% block content %}{% endblock %}
    </div>
</body>
</html>
"""

LOGIN_HTML = """
{% extends "base_layout" %}
{% block content %}
<div style="max-width: 400px; margin: 100px auto;" class="card">
    <h3>🔐 تسجيل الدخول للمنصة</h3>
    <form method="POST">
        <input type="text" name="username" placeholder="اسم المستخدم" required>
        <input type="password" name="password" placeholder="كلمة المرور" required>
        <button type="submit" class="btn btn-blue" style="width: 100%;">دخول</button>
    </form>
    <p style="text-align: center; margin-top: 15px; font-size: 14px;">ليس لديك حساب؟ <a href="/register" style="color: #00adb5;">سجل من هنا</a></p>
</div>
{% endblock %}
"""

REGISTER_HTML = """
{% extends "base_layout" %}
{% block content %}
<div style="max-width: 400px; margin: 100px auto;" class="card">
    <h3>🚀 إنشاء حساب مستخدم جديد</h3>
    <form method="POST">
        <input type="text" name="username" placeholder="اسم المستخدم الجديد" required>
        <input type="password" name="password" placeholder="كلمة المرور" required>
        <button type="submit" class="btn btn-green" style="width: 100%;">أنشئ الحساب وافتح استضافتك</button>
    </form>
    <p style="text-align: center; margin-top: 15px; font-size: 14px;">لديك حساب؟ <a href="/login" style="color: #00adb5;">سجل دخولك</a></p>
</div>
{% endblock %}
"""

DASHBOARD_HTML = """
{% extends "base_layout" %}
{% block content %}
<div class="grid">
    
    <!-- القسم الأول: التحكم في السيرفر وملف التشغيل والتيرمنال -->
    <div>
        <div class="card" style="margin-bottom: 25px;">
            <h3>⚙️ التحكم في الاستضافة (Python Application)</h3>
            <p>ملف التشغيل الرئيسي الحالي: <b style="color: #ff9f43;">{{ user.main_file }}</b></p>
            
            <form method="POST" action="/update_main_file" style="display: flex; gap: 10px;">
                <input type="text" name="main_file" value="{{ user.main_file }}" placeholder="مثال: app.py أو bot.py" style="margin: 0;" required>
                <button type="submit" class="btn btn-blue">تحديث ملف التشغيل</button>
            </form>
            
            <div style="margin-top: 20px; display: flex; gap: 15px;">
                <a href="/run_app" class="btn btn-green">▶️ تشغيل التطبيق في الخلفية</a>
                <a href="/stop_app" class="btn btn-red">⏹️ إيقاف التطبيق</a>
            </div>
        </div>

        <div class="card">
            <h3>💻 منفذ الأوامر التفاعلي (Terminal)</h3>
            <div class="terminal">{% if terminal_output %}{{ terminal_output }}{% else %}$ هنا تظهر مخرجات الأوامر وسجلات التشغيل (Logs)...{% endif %}</div>
            <form method="POST" action="/exec_command" style="display: flex; margin-top: 15px; gap: 10px;">
                <input type="text" name="command" placeholder="اكتب الأمر هنا (مثال: pip install requests أو ls)" style="margin: 0;" required>
                <button type="submit" class="btn btn-blue">تنفيذ</button>
            </form>
        </div>
    </div>

    <!-- القسم الثاني: مدير الملفات المتطور (File Manager) -->
    <div class="card">
        <h3>📁 مدير ملفات الاستضافة المعزول (File Manager)</h3>
        
        <!-- أدوات الإنشاء والرفع -->
        <div style="background: #25293c; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
            <form method="POST" action="/create_item" style="display: flex; gap: 10px; margin-bottom: 10px;">
                <input type="text" name="name" placeholder="اسم الملف أو المجلد الجديد (مثال: config.json أو src)" style="margin: 0;" required>
                <select name="type" style="margin:0; width: 120px;">
                    <option value="file">ملف</option>
                    <option value="folder">مجلد</option>
                </select>
                <button type="submit" class="btn btn-green">إنشاء</button>
            </form>
            
            <form method="POST" action="/upload_file" enctype="multipart/form-data" style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px;">
                <input type="file" name="file" required>
                <button type="submit" class="btn btn-blue">⬆️ رفع الملف</button>
            </form>
        </div>

        <!-- جدول استعراض الملفات -->
        <h4>الملفات البرمجية المتوفرة:</h4>
        <table>
            <thead>
                <tr>
                    <th>اسم الملف / المجلد</th>
                    <th>الحجم / النوع</th>
                    <th>العمليات</th>
                </tr>
            </thead>
            <tbody>
                {% for item in files_list %}
                <tr>
                    <td>{% if item.is_dir %}📁 {% else %}📄 {% endif %}{{ item.name }}</td>
                    <td>{{ item.size }}</td>
                    <td>
                        <a href="/delete_item?name={{ item.name }}" style="color: #ff5252; text-decoration: none; font-weight: bold;">حذف 🗑️</a>
                    </td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
</div>
{% endblock %}
"""

ADMIN_HTML = """
{% extends "base_layout" %}
{% block content %}
<div class="card">
    <h3>👑 لوحة تحكم الإدارة العليا (الأدمن)</h3>
    <a href="/dashboard" class="btn btn-blue">⬅️ العودة للوحة التحكم العادية</a>
    <br><br>
    <table>
        <thead>
            <tr>
                <th>رقم العميل</th>
                <th>اسم المستخدم</th>
                <th>ملف التشغيل</th>
                <th>معرف حاوية Docker للمستخدم</th>
            </tr>
        </thead>
        <tbody>
            {% for u in users %}
            <tr>
                <td>{{ u.id }}</td>
                <td>{{ u.username }} {% if u.is_admin %}<b style="color: #00adb5;">(أدمن)</b>{% endif %}</td>
                <td><code>{{ u.main_file }}</code></td>
                <td><code style="color: #ff9f43;">{{ u.container_id or 'لا توجد حاوية نشطة' }}</code></td>
            </tr>
            {% endfor %}
        </tbody>
    </table>
</div>
{% endblock %}
"""

# ----------------- مساعدات النظام (Docker Control & Helpers) -----------------

def get_user_container(user):
    """جلب حاوية العميل أو إنشاؤها إن لم تكن موجودة"""
    if not user.container_id:
        container = client.containers.run(
            "python:3.10-slim",
            detach=True,
            tty=True,
            name=f"user_space_container_{user.id}",
            mem_limit="256m",  # تحديد الرام
            nano_cpus=500000000,  # نصف معالج لحماية خادمك
            working_dir="/app"
        )
        user.container_id = container.id
        db.commit()
        # إنشاء بيئة العمل الافتراضية داخل الحاوية
        container.exec_run("mkdir -p /app")
        container.exec_run("touch /app/main.py")
    else:
        try:
            container = client.containers.get(user.container_id)
            if container.status != "running":
                container.start()
        except Exception:
            user.container_id = None
            db.commit()
            return get_user_container(user)
    return container

def list_container_files(container):
    """الحصول على قائمة الملفات والمجلدات داخل حاوية المستخدم الحالية"""
    res = container.exec_run("ls -la /app")
    output = res.output.decode('utf-8')
    lines = output.split('\n')[3:] # تخطي الأسطر العلوية لـ ls
    items = []
    for line in lines:
        parts = line.split()
        if len(parts) >= 9:
            name = parts[-1]
            if name in ['.', '..']: continue
            is_dir = line.startswith('d')
            size = parts[4] + " Bytes" if not is_dir else "مجلد"
            items.append({'name': name, 'is_dir': is_dir, 'size': size})
    return items

# ----------------- مسارات وخدمات الويب (Routes) -----------------

@app.route('/base_layout')
def base_layout(): return BASE_LAYOUT

@app.route('/')
def index(): return redirect(url_for('login'))

@app.route('/register', methods=['GET', 'POST'])
def register():
    if request.method == 'POST':
        username = request.form['username']
        password = request.form['password']
        if User.query.filter_by(username=username).first():
            flash('اسم المستخدم مسجل مسبقاً!')
            return redirect(url_for('register'))
        new_user = User(username=username, password=password)
        db.add(new_user)
        db.commit()
        flash('تم إنشاء استضافتك بنجاح، سجل دخولك الآن!')
        return redirect(url_for('login'))
    return render_template_string(REGISTER_HTML)

@app.route('/login', methods=['GET', 'POST'])
def login():
    if request.method == 'POST':
        username = request.form['username']
        password = request.form['password']
        user = User.query.filter_by(username=username, password=password).first()
        if user:
            session['user_id'] = user.id
            session['username'] = user.username
            session['is_admin'] = user.is_admin
            return redirect(url_for('dashboard'))
        flash('خطأ في البيانات الدخول!')
    return render_template_string(LOGIN_HTML)

@app.route('/dashboard')
def dashboard():
    if 'user_id' not in session: return redirect(url_for('login'))
    user = User.query.get(session['user_id'])
    container = get_user_container(user)
    files = list_container_files(container)
    terminal_output = session.pop('terminal_output', '')
    return render_template_string(DASHBOARD_HTML, user=user, files_list=files, terminal_output=terminal_output)

# 1. تحديث اسم ملف التشغيل الرئيسي
@app.route('/update_main_file', methods=['POST'])
def update_main_file():
    if 'user_id' not in session: return redirect(url_for('login'))
    user = User.query.get(session['user_id'])
    new_name = request.form['main_file'].strip()
    if new_name:
        user.main_file = new_name
        db.commit()
        flash(f'تم تعديل اسم ملف التشغيل الرئيسي إلى {new_name}')
    return redirect(url_for('dashboard'))

# 2. تنفيذ أمر تيرمنال مباشر
@app.route('/exec_command', methods=['POST'])
def exec_command():
    if 'user_id' not in session: return redirect(url_for('login'))
    user = User.query.get(session['user_id'])
    container = get_user_container(user)
    command = request.form['command']
    
    # تنفيذ الأمر المعزول
    res = container.exec_run(f"sh -c '{command}'", workdir="/app")
    session['terminal_output'] = f"$ {command}\n" + res.output.decode('utf-8')
    return redirect(url_for('dashboard'))

# 3. إنشاء ملف أو مجلد جديد في الاستضافة
@app.route('/create_item', methods=['POST'])
def create_item():
    if 'user_id' not in session: return redirect(url_for('login'))
    user = User.query.get(session['user_id'])
    container = get_user_container(user)
    name = request.form['name'].strip()
    item_type = request.form['type']
    
    if name:
        if item_type == 'file':
            container.exec_run(f"touch /app/{name}")
        else:
            container.exec_run(f"mkdir -p /app/{name}")
        flash('تم الإنشاء بنجاح!')
    return redirect(url_for('dashboard'))

# 4. رفع ملف من الحاسوب إلى الحاوية المعزولة لـ Docker
@app.route('/upload_file', methods=['POST'])
def upload_file():
    if 'user_id' not in session: return redirect(url_for('login'))
    user = User.query.get(session['user_id'])
    container = get_user_container(user)
    file = request.files['file']
    
    if file:
        filename = file.filename
        file_data = file.read()
        
        # تحويل الملف المرفوع إلى ملف tar لكي يفهمه Docker API ويضعه بالمسار المعزول
        tar_stream = io.BytesIO()
        with tarfile.open(fileobj=tar_stream, mode='w') as tar:
            tarinfo = tarfile.TarInfo(name=filename)
            tarinfo.size = len(file_data)
            tar.addfile(tarinfo, io.BytesIO(file_data))
        
        tar_stream.seek(0)
        container.put_archive("/app", tar_stream)
        flash('تم رفع الملف بنجاح إلى سيرفرك الشخصي!')
        
    return redirect(url_for('dashboard'))

# 5. حذف ملف أو مجلد
@app.route('/delete_item')
def delete_item():
    if 'user_id' not in session: return redirect(url_for('login'))
    user = User.query.get(session['user_id'])
    container = get_user_container(user)
    name = request.args.get('name')
    if name:
        container.exec_run(f"rm -rf /app/{name}")
        flash(f'تم حذف {name} نهائياً!')
    return redirect(url_for('dashboard'))

# 6. تشغيل الكود البرمجي في الخلفية وعرض السجلات
@app.route('/run_app')
def run_app():
    if 'user_id' not in session: return redirect(url_for('login'))
    user = User.query.get(session['user_id'])
    container = get_user_container(user)
    
    # تشغيل ملف البايثون المختار من المستخدم بالخلفية
    res = container.exec_run(f"python3 {user.main_file}", workdir="/app", detach=False)
    session['terminal_output'] = f"[تشغيل السيرفر لملف {user.main_file}...]\n" + res.output.decode('utf-8')
    return redirect(url_for('dashboard'))

# 7. إيقاف تشغيل الملف/الحاوية
@app.route('/stop_app')
def stop_app():
    if 'user_id' not in session: return redirect(url_for('login'))
    user = User.query.get(session['user_id'])
    container = get_user_container(user)
    container.restart() # إعادة تشغيل الحاوية لتطهير وإيقاف كل العمليات التي تعمل بالخلفية
    session['terminal_output'] = "[تم إيقاف التطبيق وتصفير العمليات الجارية بنجاح]"
    return redirect(url_for('dashboard'))

@app.route('/admin')
def admin():
    if 'user_id' not in session or not session.get('is_admin'): return "غير مصرح لك", 403
    all_users = User.query.all()
    return render_template_string(ADMIN_HTML, users=all_users)

@app.route('/logout')
def logout():
    session.clear()
    return redirect(url_for('login'))

if __name__ == '__main__':
    app.run(debug=True, host='0.0.0.0', port=5000)
