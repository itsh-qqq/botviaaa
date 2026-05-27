import os
import subprocess
import json
from flask import Flask, render_template, request, redirect, url_for
from werkzeug.utils import secure_filename

app = Flask(__name__)

# تحديد المجلدات ومسارات حفظ البيانات
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
BOTS_DIR = os.path.join(BASE_DIR, 'bots')
STATUS_FILE = os.path.join(BASE_DIR, 'bots_status.json')

# إنشاء مجلد البوتات إذا لم يكن موجوداً
if not os.path.exists(BOTS_DIR):
    os.makedirs(BOTS_DIR)

# قاموس لتخزين كائنات العمليات النشطة (في الذاكرة الحالية)
active_processes = {}

def load_saved_status():
    """تحميل الحالات المسجلة للبوتات (هل كانت تعمل أم متوقفة قبل إعادة التشغيل)"""
    if os.path.exists(STATUS_FILE):
        try:
            with open(STATUS_FILE, 'r') as f:
                return json.load(f)
        except:
            return {}
    return {}

def save_status(status_dict):
    """حفظ حالات البوتات في ملف JSON ثابت"""
    with open(STATUS_FILE, 'w') as f:
        json.dump(status_dict, f, indent=4)

def get_bots_list():
    """جلب أسماء كافة ملفات بايثون المرفوعة في المجلد"""
    if os.path.exists(BOTS_DIR):
        return [f for f in os.listdir(BOTS_DIR) if f.endswith('.py')]
    return []

def sync_and_auto_start():
    """تزامن العمليات وتشغيل البوتات التي كانت حالتها 'running' تلقائياً عند بدء السيرفر"""
    saved_status = load_saved_status()
    current_bots = get_bots_list()
    new_status = {}

    for bot in current_bots:
        # إذا كان البوت مسجلاً كـ "يعمل"، ولم نقم بتشغيله بعد في هذه الجلسة
        if saved_status.get(bot) == "running":
            if bot not in active_processes or active_processes[bot].poll() is not None:
                try:
                    bot_path = os.path.join(BOTS_DIR, bot)
                    process = subprocess.Popen(['python', bot_path], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                    active_processes[bot] = process
                    new_status[bot] = "running"
                except Exception as e:
                    print(f"خيار تشغيل تلقائي فشل للبوت {bot}: {e}")
                    new_status[bot] = "stopped"
            else:
                new_status[bot] = "running"
        else:
            new_status[bot] = "stopped"
            
    save_status(new_status)

@app.route('/')
def index():
    # التأكد من تشغيل وتحديث الحالات قبل عرض الصفحة
    sync_and_auto_start()
    
    bots = get_bots_list()
    saved_status = load_saved_status()
    bot_status = {}
    
    for bot in bots:
        # التحقق الفعلي من العملية في الخلفية
        if bot in active_processes and active_processes[bot].poll() is None:
            bot_status[bot] = "يعمل حالياً 🟢"
        else:
            bot_status[bot] = "متوقف 🔴"
            # تصحيح الملف الثابت إذا انهار البوت بشكل مفاجئ
            if saved_status.get(bot) == "running" and (bot not in active_processes or active_processes[bot].poll() is not None):
                saved_status[bot] = "stopped"
                save_status(saved_status)
                
    return render_template('index.html', bots=bots, status=bot_status)

@app.route('/upload', methods=['POST'])
def upload_file():
    """استقبال الملف المرفوع وحفظه فورا"""
    if 'bot_file' not in request.files:
        return "لم يتم العثور على حقل الملف", 400
    file = request.files['bot_file']
    if file.filename == '':
        return "لم يتم اختيار ملف", 400
    if file and file.filename.endswith('.py'):
        filename = secure_filename(file.filename)
        file.save(os.path.join(BOTS_DIR, filename))
        
        # تسجيل حالة البوت الجديد كمتوقف افتراضياً
        saved_status = load_saved_status()
        saved_status[filename] = "stopped"
        save_status(saved_status)
        
        return redirect(url_for('index'))
    return "صيغة ملف غير مدعومة، يرجى رفع ملفات .py فقط", 400

@app.route('/action/<bot_name>/<action>')
def bot_action(bot_name, action):
    bot_path = os.path.join(BOTS_DIR, bot_name)
    saved_status = load_saved_status()
    
    if action == 'start':
        if bot_name not in active_processes or active_processes[bot_name].poll() is not None:
            process = subprocess.Popen(['python', bot_path])
            active_processes[bot_name] = process
            saved_status[bot_name] = "running"
            save_status(saved_status)
            
    elif action == 'stop':
        if bot_name in active_processes and active_processes[bot_name].poll() is None:
            active_processes[bot_name].terminate()
            active_processes[bot_name].wait()
            del active_processes[bot_name]
        saved_status[bot_name] = "stopped"
        save_status(saved_status)
        
    elif action == 'delete':
        # إيقاف البوت أولاً إذا كان يعمل قبل الحذف
        if bot_name in active_processes and active_processes[bot_name].poll() is None:
            active_processes[bot_name].terminate()
            active_processes[bot_name].wait()
            del active_processes[bot_name]
        
        # حذف الملف من القرص
        if os.path.exists(bot_path):
            os.remove(bot_path)
            
        if bot_name in saved_status:
            del saved_status[bot_name]
        save_status(saved_status)
        
    return redirect(url_for('index'))

if __name__ == '__main__':
    # تشغيل التزامن الأولي عند إقلاع السيرفر
    sync_and_auto_start()
    port = int(os.environ.get("PORT", 8080))
    app.run(host='0.0.0.0', port=port)
