import os
import subprocess
from flask import Flask, render_template, request, redirect, url_for
from werkzeug.utils import secure_filename

app = Flask(__name__)

BOTS_DIR = os.path.join(os.path.dirname(__file__), 'bots')
if not os.path.exists(BOTS_DIR):
    os.makedirs(BOTS_DIR)

running_bots = {}

def get_bots_list():
    if os.path.exists(BOTS_DIR):
        return [f for f in os.listdir(BOTS_DIR) if f.endswith('.py')]
    return []

@app.route('/')
def index():
    bots = get_bots_list()
    bot_status = {}
    for bot in bots:
        if bot in running_bots and running_bots[bot].poll() is None:
            bot_status[bot] = "يعمل 🟢"
        else:
            bot_status[bot] = "متوقف 🔴"
    return render_template('index.html', bots=bots, status=bot_status)

@app.route('/upload', methods=['POST'])
def upload_file():
    if 'bot_file' not in request.files:
        return "لم يتم اختيار أي ملف", 400
    file = request.files['bot_file']
    if file.filename == '':
        return "اسم الملف فارغ", 400
    if file and file.filename.endswith('.py'):
        filename = secure_filename(file.filename)
        file.save(os.path.join(BOTS_DIR, filename))
        return redirect(url_for('index'))
    return "يُسمح فقط بملفات بايثون (.py)", 400

@app.route('/action/<bot_name>/<action>')
def bot_action(bot_name, action):
    bot_path = os.path.join(BOTS_DIR, bot_name)
    if action == 'start':
        if bot_name not in running_bots or running_bots[bot_name].poll() is not None:
            # تشغيل البوت في عملية منفصلة بالخلفية
            process = subprocess.Popen(['python', bot_path])
            running_bots[bot_name] = process
    elif action == 'stop':
        if bot_name in running_bots and running_bots[bot_name].poll() is None:
            running_bots[bot_name].terminate()
            running_bots[bot_name].wait()
    return redirect(url_for('index'))

if __name__ == '__main__':
    port = int(os.environ.get("PORT", 8080))
    app.run(host='0.0.0.0', port=port)
