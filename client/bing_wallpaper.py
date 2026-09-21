#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Bing 每日壁纸客户端 - 系统托盘应用
使用 tianlingzi.top 提供的 Bing 每日壁纸服务
资源占用极低：无主窗口，仅系统托盘菜单交互
"""

import json
import os
import sys
import re
import glob
import threading
import subprocess
import urllib.request
import urllib.parse
import ctypes
import winreg
from datetime import datetime, timedelta
from PIL import Image
import pystray


# ==================== 常量 ====================
APP_NAME = "BingWallpaper"
APP_TITLE = "Bing 每日壁纸"

# PyInstaller 打包后：exe 所在目录（config/cache 放这里）；未打包：脚本所在目录
if getattr(sys, 'frozen', False):
    BASE_DIR = os.path.dirname(sys.executable)
    _BUNDLE_DIR = getattr(sys, '_MEIPASS', BASE_DIR)
else:
    BASE_DIR = os.path.dirname(os.path.abspath(__file__))
    _BUNDLE_DIR = BASE_DIR

ICON_DIR = os.path.join(_BUNDLE_DIR, "icon")
CONFIG_PATH = os.path.join(BASE_DIR, "config.json")
CACHE_DIR = os.path.join(BASE_DIR, "cache")

API_BASE = "https://www.tianlingzi.top/bing"
WEBSITE_URL_BASE = "https://www.tianlingzi.top/bing"
WEBSITE_URL = f"{WEBSITE_URL_BASE}/dashboard.php"
# 远程图片直链前缀（固定命名，轮巡模式可直接构造 URL 下载，跳过 API 调用）
CACHE_BASE = "https://www.tianlingzi.top/bing/cache"
CACHE_NAME_FMT = "tianlingzi.top.{date}.{res}.jpg"

# 近两年随机日期的范围：今年和去年
ROTATION_YEAR_COUNT = 2  # 今年 + 去年 = 2 年

RESOLUTION_1080P = "1920x1080"
RESOLUTION_4K = "uhd"
RESOLUTION_LABELS = {RESOLUTION_1080P: "1080P", RESOLUTION_4K: "4K"}

MODE_DAILY = "daily"
MODE_DATE = "date"
MODE_SCHEDULED = "scheduled"

# Windows SystemParametersInfo
SPI_SETDESKWALLPAPER = 0x0014
SPIF_UPDATEINIFILE = 0x01
SPIF_SENDWININICHANGE = 0x02

AUTOSTART_REG_KEY = r"Software\Microsoft\Windows\CurrentVersion\Run"

# 间隔预设（分钟）
INTERVAL_PRESETS = [5, 10, 15, 30, 60, 180, 360]
INTERVAL_LABELS = {
    5: "5 分钟", 10: "10 分钟", 15: "15 分钟",
    30: "30 分钟", 60: "1 小时", 180: "3 小时", 360: "6 小时",
}

# 缓存天数预设（0 = 永不清理）
CACHE_DAYS_PRESETS = [7, 14, 30, 0]
CACHE_DAYS_LABELS = {7: "7 天", 14: "14 天", 30: "30 天", 0: "永久"}


# ==================== 配置管理 ====================
DEFAULT_CONFIG = {
    "resolution": RESOLUTION_1080P,
    "mode": MODE_DAILY,
    "specific_date": "",
    "schedule_interval_minutes": 60,
    "cache_days": 7,
    "_daily_applied_date": "",   # 持久化：每日模式已应用的日期（防重启/休眠后状态丢失）
    "_date_applied_key": "",     # 持久化：指定日期模式已应用的标记
    "_current_wallpaper_date": "", # 持久化：当前应用的壁纸日期，供"查看详情"使用
}


def load_config():
    if os.path.exists(CONFIG_PATH):
        try:
            with open(CONFIG_PATH, "r", encoding="utf-8") as f:
                return {**DEFAULT_CONFIG, **json.load(f)}
        except Exception:
            pass
    return dict(DEFAULT_CONFIG)


def save_config(cfg):
    try:
        with open(CONFIG_PATH, "w", encoding="utf-8") as f:
            json.dump(cfg, f, ensure_ascii=False, indent=2)
    except Exception as e:
        print(f"保存配置失败: {e}")


# ==================== API 客户端 ====================
def _http_get(url, timeout=15):
    req = urllib.request.Request(url, headers={"User-Agent": "BingWallpaperClient/1.0"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return resp.read()


def build_image_url(date_str, resolution):
    """按约定命名直接构造远程图片直链 URL（跳过 API 调用）"""
    return f"{CACHE_BASE}/{CACHE_NAME_FMT.format(date=date_str, res=resolution)}"


def get_daily_target_date(now):
    """每日模式的目标日期：00:15 前视为昨天（防止服务器未更新），00:15 后视为今天"""
    if now.hour == 0 and now.minute < 15:
        return (now - timedelta(days=1)).strftime("%Y%m%d")
    return now.strftime("%Y%m%d")


def random_past_date(max_years=2):
    """
    随机生成一个"过去"的有效日期（今年或去年，不会是今天之后）。
    - 先随机年份（今年 / 去年 / …，max_years 控制范围）
    - 再随机 1-12 月
    - 再按该月实际天数随机日（避免 2/30 这种无效日期）
    返回 YYYYMMDD 字符串；保证日期 <= 今天。
    """
    import random
    today = datetime.now().date()
    year_candidates = [today.year - i for i in range(max_years)]
    # 越近的年份概率略高（让更新鲜的壁纸出现更频繁），但全部随机也行；此处纯均匀分布
    for _ in range(64):  # 重试上限
        year = random.choice(year_candidates)
        month = random.randint(1, 12)
        # 该月实际最大天数：计算下月 1 日减 1 日
        if month == 12:
            next_first = datetime(year + 1, 1, 1)
        else:
            next_first = datetime(year, month + 1, 1)
        max_day = (next_first - timedelta(days=1)).day
        day = random.randint(1, max_day)
        dt = datetime(year, month, day).date()
        if dt <= today:
            return dt.strftime("%Y%m%d")
    # 极端情况下回退到昨天
    return (today - timedelta(days=1)).strftime("%Y%m%d")


def fetch_wallpaper(action="latest", date=None):
    """从 API 获取单条壁纸信息"""
    try:
        if action == "latest":
            url = f"{API_BASE}/api.php?action=latest"
        elif action == "date" and date:
            url = f"{API_BASE}/api.php?action=date&date={date}"
        else:
            return None
        data = json.loads(_http_get(url).decode("utf-8"))
        if data.get("ok") and data.get("data"):
            return data["data"]
    except Exception as e:
        print(f"API 请求失败: {e}")
    return None


def download_image(url, save_path):
    """下载图片到本地"""
    try:
        data = _http_get(url, timeout=60)
        with open(save_path, "wb") as f:
            f.write(data)
        return True
    except Exception as e:
        print(f"下载图片失败: {e}")
    return False


# ==================== 缓存管理 ====================
def get_cache_path(date_str, resolution):
    """缓存文件名与网站一致: tianlingzi.top.YYYYMMDD.分辨率.jpg"""
    return os.path.join(CACHE_DIR, f"tianlingzi.top.{date_str}.{resolution}.jpg")


def ensure_cache_dir():
    if not os.path.exists(CACHE_DIR):
        os.makedirs(CACHE_DIR, exist_ok=True)


def get_cached_image(date_str, resolution):
    """按日期+分辨率匹配缓存文件（通配前缀，兼容服务器命名）"""
    if not os.path.exists(CACHE_DIR):
        return None
    pattern = os.path.join(CACHE_DIR, f"*.{date_str}.{resolution}.jpg")
    for path in glob.glob(pattern):
        if os.path.getsize(path) > 0:
            return path
    return None


def clean_expired_cache(cache_days):
    """清理过期缓存，返回清理数量。cache_days=0 表示永久不清理。
    按文件下载时间（mtime）判断，而非文件名中的壁纸日期。"""
    if not cache_days or cache_days <= 0:
        return 0
    if not os.path.exists(CACHE_DIR):
        return 0
    cutoff = datetime.now() - timedelta(days=cache_days)
    cutoff_ts = cutoff.timestamp()
    count = 0
    for fname in os.listdir(CACHE_DIR):
        if not fname.endswith(".jpg"):
            continue
        fpath = os.path.join(CACHE_DIR, fname)
        try:
            # 文件最后修改时间 = 下载完成时间
            if os.path.getmtime(fpath) < cutoff_ts:
                os.remove(fpath)
                count += 1
        except Exception:
            pass
    return count


def clear_all_cache():
    """立即清理所有缓存"""
    count = 0
    if os.path.exists(CACHE_DIR):
        for fname in os.listdir(CACHE_DIR):
            if fname.endswith(".jpg"):
                try:
                    os.remove(os.path.join(CACHE_DIR, fname))
                    count += 1
                except Exception:
                    pass
    return count


# ==================== 壁纸设置 ====================
def set_wallpaper(image_path):
    """设置 Windows 桌面壁纸"""
    try:
        abs_path = os.path.abspath(image_path)
        result = ctypes.windll.user32.SystemParametersInfoW(
            SPI_SETDESKWALLPAPER, 0, abs_path,
            SPIF_UPDATEINIFILE | SPIF_SENDWININICHANGE,
        )
        return bool(result)
    except Exception as e:
        print(f"设置壁纸失败: {e}")
    return False


# ==================== 开机启动管理 ====================
def is_autostart_enabled():
    try:
        key = winreg.OpenKey(winreg.HKEY_CURRENT_USER, AUTOSTART_REG_KEY, 0, winreg.KEY_READ)
        winreg.QueryValueEx(key, APP_NAME)
        winreg.CloseKey(key)
        return True
    except FileNotFoundError:
        return False
    except Exception:
        return False


def set_autostart(enabled):
    try:
        if enabled:
            if getattr(sys, "frozen", False):
                cmd = f'"{sys.executable}"'
            else:
                cmd = f'"{sys.executable}" "{os.path.abspath(__file__)}"'
            key = winreg.OpenKey(winreg.HKEY_CURRENT_USER, AUTOSTART_REG_KEY, 0, winreg.KEY_SET_VALUE)
            winreg.SetValueEx(key, APP_NAME, 0, winreg.REG_SZ, cmd)
            winreg.CloseKey(key)
        else:
            try:
                key = winreg.OpenKey(winreg.HKEY_CURRENT_USER, AUTOSTART_REG_KEY, 0, winreg.KEY_SET_VALUE)
                winreg.DeleteValue(key, APP_NAME)
                winreg.CloseKey(key)
            except FileNotFoundError:
                pass
        return True
    except Exception as e:
        print(f"设置开机启动失败: {e}")
    return False


# ==================== 输入框 ====================
def show_input_box(prompt, title, default=""):
    """使用 PowerShell 显示原生输入框（避免 tkinter 线程冲突）"""
    try:
        ps = (
            "Add-Type -AssemblyName Microsoft.VisualBasic;"
            f"$r=[Microsoft.VisualBasic.Interaction]::InputBox('{prompt}','{title}','{default}');"
            "Write-Output $r"
        )
        result = subprocess.run(
            ["powershell", "-NoProfile", "-Command", ps],
            capture_output=True, text=True, timeout=120,
        )
        out = result.stdout.strip()
        return out or None
    except Exception as e:
        print(f"输入框错误: {e}")
    return None


def show_interval_dialog(default_minutes=60):
    """
    显示自定义间隔窗口（使用 Python 内置 tkinter，无外部进程、无 PowerShell 黑窗）：
      - Spinbox（数值输入框，带上下箭头按钮）
      - Combobox（单位下拉：分钟/小时）
      - 确定/取消按钮
    返回 int 分钟数，取消返回 None。
    在独立线程中创建 Tk 根窗口，对话结束后立即销毁 mainloop 退出，绝不残留。
    """
    import tkinter as tk
    from tkinter import ttk
    import queue

    result_q = queue.Queue(maxsize=1)

    # 根据默认分钟数反算默认展示值和单位
    if default_minutes >= 60 and default_minutes % 60 == 0:
        default_val = default_minutes // 60
        default_unit = 1  # 小时
    else:
        default_val = default_minutes
        default_unit = 0  # 分钟

    def run_dialog():
        try:
            root = tk.Tk()
        except Exception as e:
            # tkinter 在某些无显示环境会失败，回退到 inputbox
            print(f"tkinter 初始化失败: {e}")
            result_q.put(None)
            return

        try:
            root.title("自定义间隔")
            root.resizable(False, False)
            # 置顶显示，确保在托盘菜单弹出后仍然可以看到
            root.attributes('-topmost', True)
            # 去除最小化/最大化按钮
            root.attributes('-toolwindow', True)
            # 居中显示
            w, h = 250, 150
            sw = root.winfo_screenwidth()
            sh = root.winfo_screenheight()
            root.geometry(f"{w}x{h}+{(sw-w)//2}+{(sh-h)//2}")

            # 变量
            var_value = tk.StringVar(value=str(default_val))
            var_unit = tk.StringVar(value="小时" if default_unit == 1 else "分钟")
            MIN_LIMITS = {"分钟": (1, 59), "小时": (1, 24)}

            def on_unit_change(*_):
                unit = var_unit.get()
                lo, hi = MIN_LIMITS[unit]
                spin.config(from_=lo, to=hi)
                try:
                    cur = int(var_value.get())
                except ValueError:
                    cur = lo
                if cur < lo:
                    var_value.set(str(lo))
                elif cur > hi:
                    var_value.set(str(hi))

            # 数值
            frm = ttk.Frame(root, padding=10)
            frm.pack(fill="both", expand=True)

            ttk.Label(frm, text="数值：").grid(row=0, column=0, sticky="w", pady=4)
            lo, hi = MIN_LIMITS[var_unit.get()]
            spin = tk.Spinbox(
                frm, from_=lo, to=hi, textvariable=var_value,
                width=10, justify="left",
            )
            spin.grid(row=0, column=1, sticky="we", pady=4)

            ttk.Label(frm, text="单位：").grid(row=1, column=0, sticky="w", pady=4)
            combo = ttk.Combobox(
                frm, textvariable=var_unit,
                values=["分钟", "小时"], state="readonly", width=8,
            )
            combo.grid(row=1, column=1, sticky="we", pady=4)
            combo.bind("<<ComboboxSelected>>", on_unit_change)

            # 按钮
            btns = ttk.Frame(frm)
            btns.grid(row=2, column=0, columnspan=2, pady=(12, 0), sticky="we")

            def on_ok(*_):
                try:
                    val = int(var_value.get())
                except ValueError:
                    return
                unit_idx = 0 if var_unit.get() == "分钟" else 1
                minutes = val * 60 if unit_idx == 1 else val
                if 1 <= minutes <= 1440:
                    result_q.put(minutes)
                else:
                    result_q.put(None)
                root.destroy()

            def on_cancel(*_):
                result_q.put(None)
                root.destroy()

            ttk.Button(btns, text="确定", command=on_ok).pack(side="right", padx=4)
            ttk.Button(btns, text="取消", command=on_cancel).pack(side="right", padx=4)

            root.bind("<Return>", on_ok)
            root.bind("<Escape>", on_cancel)
            root.protocol("WM_DELETE_WINDOW", on_cancel)

            root.mainloop()
        except Exception as e:
            print(f"间隔对话框错误: {e}")
            try:
                result_q.put_nowait(None)
            except Exception:
                pass
            try:
                root.destroy()
            except Exception:
                pass

    t = threading.Thread(target=run_dialog, daemon=True)
    t.start()
    t.join(timeout=300)  # 最长 5 分钟超时
    if result_q.empty():
        return None
    v = result_q.get()
    return v if isinstance(v, int) else None


def show_date_dialog(default_date=None):
    """
    显示自定义日期窗口（tkinter，无外部进程、无黑窗）：
      - 年/月/日 三个 Spinbox（自带上下按钮）
      - 快捷按钮：今天 / 昨天
      - 确定 / 取消
    返回 YYYYMMDD 字符串，取消返回 None。
    """
    import tkinter as tk
    from tkinter import ttk
    import queue

    result_q = queue.Queue(maxsize=1)

    # 解析默认值
    today_dt = datetime.now()
    if default_date and len(default_date) == 8 and default_date.isdigit():
        try:
            def_y = int(default_date[:4])
            def_m = int(default_date[4:6])
            def_d = int(default_date[6:8])
            datetime(def_y, def_m, def_d)  # 校验合法
        except (ValueError, TypeError):
            def_y, def_m, def_d = today_dt.year, today_dt.month, today_dt.day
    else:
        def_y, def_m, def_d = today_dt.year, today_dt.month, today_dt.day

    def run_dialog():
        try:
            root = tk.Tk()
        except Exception as e:
            print(f"tkinter 初始化失败: {e}")
            result_q.put(None)
            return

        try:
            root.title("自定义日期")
            root.resizable(False, False)
            root.attributes('-topmost', True)
            root.attributes('-toolwindow', True)

            w, h = 270, 170
            sw = root.winfo_screenwidth()
            sh = root.winfo_screenheight()
            root.geometry(f"{w}x{h}+{(sw-w)//2}+{(sh-h)//2}")

            var_y = tk.StringVar(value=str(def_y))
            var_m = tk.StringVar(value=str(def_m))
            var_d = tk.StringVar(value=str(def_d))

            def _days_in_month(y, m):
                """返回指定年月的最大天数"""
                if m == 12:
                    nxt = datetime(y + 1, 1, 1)
                else:
                    nxt = datetime(y, m + 1, 1)
                return (nxt - timedelta(days=1)).day

            def _clamp_day(*_):
                """切换年月时，把日约束到当月合法范围"""
                try:
                    y = int(var_y.get())
                    m = int(var_m.get())
                except ValueError:
                    return
                max_day = _days_in_month(y, m)
                try:
                    d = int(var_d.get())
                except ValueError:
                    d = 1
                if d > max_day:
                    var_d.set(str(max_day))
                spn_d.config(from_=1, to=max_day)

            frm = ttk.Frame(root, padding=10)
            frm.pack(fill="both", expand=True)

            # 年
            ttk.Label(frm, text="年：").grid(row=0, column=0, sticky="w", pady=2)
            spn_y = tk.Spinbox(
                frm, from_=2010, to=today_dt.year + 1,
                textvariable=var_y, width=8, command=_clamp_day,
            )
            spn_y.grid(row=0, column=1, sticky="w", pady=2)
            var_y.trace_add("write", _clamp_day)

            # 月
            ttk.Label(frm, text="月：").grid(row=1, column=0, sticky="w", pady=2)
            spn_m = tk.Spinbox(
                frm, from_=1, to=12,
                textvariable=var_m, width=8, command=_clamp_day,
            )
            spn_m.grid(row=1, column=1, sticky="w", pady=2)
            var_m.trace_add("write", _clamp_day)

            # 日（初始上限按默认年月）
            max_day_init = _days_in_month(def_y, def_m)
            ttk.Label(frm, text="日：").grid(row=2, column=0, sticky="w", pady=2)
            spn_d = tk.Spinbox(frm, from_=1, to=max_day_init, textvariable=var_d, width=8)
            spn_d.grid(row=2, column=1, sticky="w", pady=2)

            # 快捷按钮
            quick = ttk.Frame(frm)
            quick.grid(row=0, column=2, rowspan=3, padx=(14, 0), sticky="ns")

            def _set_today():
                t = datetime.now()
                var_y.set(str(t.year)); var_m.set(str(t.month)); var_d.set(str(t.day))

            def _set_yesterday():
                t = datetime.now() - timedelta(days=1)
                var_y.set(str(t.year)); var_m.set(str(t.month)); var_d.set(str(t.day))

            ttk.Button(quick, text="今天", command=_set_today, width=7).pack(pady=2)
            ttk.Button(quick, text="昨天", command=_set_yesterday, width=7).pack(pady=2)

            # 确定 / 取消
            btns = ttk.Frame(frm)
            btns.grid(row=3, column=0, columnspan=3, pady=(10, 0), sticky="we")

            def on_ok(*_):
                try:
                    y = int(var_y.get())
                    m = int(var_m.get())
                    d = int(var_d.get())
                    dt = datetime(y, m, d)
                except (ValueError, TypeError):
                    return
                # 不允许超过今天（Bing 还没生成未来图片）
                if dt.date() > today_dt.date():
                    return
                result_q.put(dt.strftime("%Y%m%d"))
                root.destroy()

            def on_cancel(*_):
                result_q.put(None)
                root.destroy()

            ttk.Button(btns, text="确定", command=on_ok).pack(side="right", padx=4)
            ttk.Button(btns, text="取消", command=on_cancel).pack(side="right", padx=4)

            root.bind("<Return>", on_ok)
            root.bind("<Escape>", on_cancel)
            root.protocol("WM_DELETE_WINDOW", on_cancel)

            root.mainloop()
        except Exception as e:
            print(f"日期对话框错误: {e}")
            try:
                result_q.put_nowait(None)
            except Exception:
                pass
            try:
                root.destroy()
            except Exception:
                pass

    t = threading.Thread(target=run_dialog, daemon=True)
    t.start()
    t.join(timeout=300)
    if result_q.empty():
        return None
    v = result_q.get()
    return v if isinstance(v, str) and len(v) == 8 else None


def show_cache_days_dialog(default_days=7):
    """
    显示自定义缓存天数窗口（tkinter，表单风格，与自定义间隔对话框一致）：
      - Spinbox（数值输入框，带上下箭头，0=永久不清理）
      - 确定按钮含说明文字"0=永不清理"
    返回 int 天数，取消返回 None。
    """
    import tkinter as tk
    from tkinter import ttk
    import queue

    result_q = queue.Queue(maxsize=1)

    def run_dialog():
        try:
            root = tk.Tk()
        except Exception as e:
            print(f"tkinter 初始化失败: {e}")
            result_q.put(None)
            return

        try:
            root.title("自定义缓存天数")
            root.resizable(False, False)
            root.attributes('-topmost', True)
            root.attributes('-toolwindow', True)
            w, h = 280, 140
            sw = root.winfo_screenwidth()
            sh = root.winfo_screenheight()
            root.geometry(f"{w}x{h}+{(sw-w)//2}+{(sh-h)//2}")

            var_value = tk.StringVar(value=str(default_days))

            frm = ttk.Frame(root, padding=10)
            frm.pack(fill="both", expand=True)

            ttk.Label(frm, text="天数：").grid(row=0, column=0, sticky="w", pady=4)
            spin = tk.Spinbox(
                frm, from_=0, to=365,
                textvariable=var_value, width=10, justify="left",
            )
            spin.grid(row=0, column=1, sticky="we", pady=4)

            ttk.Label(frm, text="（0 = 永不清理）").grid(
                row=1, column=0, columnspan=2, sticky="w", pady=2
            )

            btns = ttk.Frame(frm)
            btns.grid(row=2, column=0, columnspan=2, pady=(10, 0), sticky="we")

            def on_ok(*_):
                try:
                    val = int(var_value.get())
                except ValueError:
                    return
                if 0 <= val <= 365:
                    result_q.put(val)
                else:
                    result_q.put(None)
                root.destroy()

            def on_cancel(*_):
                result_q.put(None)
                root.destroy()

            ttk.Button(btns, text="确定", command=on_ok).pack(side="right", padx=4)
            ttk.Button(btns, text="取消", command=on_cancel).pack(side="right", padx=4)

            root.bind("<Return>", on_ok)
            root.bind("<Escape>", on_cancel)
            root.protocol("WM_DELETE_WINDOW", on_cancel)

            root.mainloop()
        except Exception as e:
            print(f"缓存天数对话框错误: {e}")
            try:
                result_q.put_nowait(None)
            except Exception:
                pass
            try:
                root.destroy()
            except Exception:
                pass

    t = threading.Thread(target=run_dialog, daemon=True)
    t.start()
    t.join(timeout=300)
    if result_q.empty():
        return None
    v = result_q.get()
    return v if isinstance(v, int) else None


# ==================== 主应用 ====================
class BingWallpaperApp:
    def __init__(self):
        self.config = load_config()
        self.icon = None
        self._lock = threading.Lock()
        self._stop_event = threading.Event()
        self._poke_event = threading.Event()
        self._scheduler_thread = None
        # 轮巡状态
        self._rotation_dates = []
        self._rotation_index = 0
        self._last_daily_key = None
        self._last_switch_time = None
        self._last_cache_clean = None
        self._current_wallpaper_date = self.config.get("_current_wallpaper_date", "")

    # ---------- 配置变更 ----------
    def update_config(self, **kwargs):
        changed = False
        with self._lock:
            for k, v in kwargs.items():
                if self.config.get(k) != v:
                    self.config[k] = v
                    changed = True
            if changed:
                save_config(self.config)
        if changed:
            self._poke_event.set()
            if self.icon:
                self.icon.update_menu()

    def _persist_wallpaper_date(self, date_str):
        """持久化当前壁纸日期到 config，不触发调度器 poke"""
        self._current_wallpaper_date = date_str
        with self._lock:
            self.config["_current_wallpaper_date"] = date_str
            save_config(self.config)

    # ---------- 壁纸操作 ----------
    def _apply_wallpaper(self, date_str, image_url, resolution):
        """下载/使用缓存并设置壁纸（文件名与服务器一致）"""
        ensure_cache_dir()
        cached = get_cached_image(date_str, resolution)
        if cached:
            if set_wallpaper(cached):
                self._persist_wallpaper_date(date_str)
                return True, f"已设置壁纸（缓存）: {date_str}"
            return False, "设置壁纸失败"
        if image_url:
            file_name = os.path.basename(urllib.parse.urlparse(image_url).path)
            cache_path = os.path.join(CACHE_DIR, file_name)
            if download_image(image_url, cache_path):
                if set_wallpaper(cache_path):
                    self._persist_wallpaper_date(date_str)
                    return True, f"已设置壁纸: {date_str}"
                return False, "设置壁纸失败"
        return False, f"无法获取壁纸: {date_str}"

    def do_daily_update(self):
        """每日模式：直接构造 URL 下载（跳过 JSON API），00:15 前用昨天，之后用今天"""
        with self._lock:
            config = dict(self.config)
        resolution = config["resolution"]
        now = datetime.now()
        target = get_daily_target_date(now)
        image_url = build_image_url(target, resolution)
        success, msg = self._apply_wallpaper(target, image_url, resolution)
        return success, msg, target

    def do_update_wallpaper(self):
        """指定日期模式：通过 API 获取指定日期壁纸，返回 (success, msg, date_str)"""
        with self._lock:
            config = dict(self.config)
        resolution = config["resolution"]

        date_str = config.get("specific_date", "")
        if not date_str:
            return False, "未指定日期", ""
        wp = fetch_wallpaper("date", date_str)
        if not wp:
            return False, f"无法获取 {date_str} 的壁纸", date_str
        image_url = wp.get("images", {}).get(resolution)
        if not image_url:
            return False, f"{date_str} 壁纸无此分辨率", date_str
        ok, msg = self._apply_wallpaper(date_str, image_url, resolution)
        return ok, msg, date_str

    def do_rotate_wallpaper(self):
        """
        定时轮巡模式：随机挑选"近两年内"任意有效日期，直接构造图片 URL 下载。
        - 跳过 JSON API 请求（一次 HTTP 往返），节省时间与可用性依赖
        - 使用最近 50 次随机去重，避免短期内重复同一张
        - 最多尝试 10 次（跳过缓存命中、下载失败的日期）
        """
        import random
        with self._lock:
            config = dict(self.config)
            # 维护一个简单的最近使用集合（按分辨率分组），避免短期重复
            if not hasattr(self, "_rot_last_used"):
                self._rot_last_used = {}  # {resolution: [date, date, ...]}
        resolution = config["resolution"]
        with self._lock:
            history = self._rot_last_used.setdefault(resolution, [])

        max_attempts = 10
        for _ in range(max_attempts):
            # 随机一个近两年日期
            #   - 如果 random_past_date 生成的值落在最近历史中，则再随机一次
            date_str = None
            for _2 in range(20):
                cand = random_past_date(ROTATION_YEAR_COUNT)
                if cand not in history:
                    date_str = cand
                    break
                # 超过 20 次候选仍被历史占满（历史太多），就强行用这个
                date_str = cand
            if not date_str:
                continue

            image_url = build_image_url(date_str, resolution)

            # 1) 命中本地缓存 -> 直接用
            cached = get_cached_image(date_str, resolution)
            if cached:
                if set_wallpaper(cached):
                    # 记入历史，超出容量剔除最早
                    history.append(date_str)
                    if len(history) > 50:
                        del history[:len(history) - 50]
                    self._persist_wallpaper_date(date_str)
                    return True, f"轮巡壁纸（缓存）: {date_str}"
                continue

            # 2) 直接按 URL 下载 -> 设置
            ensure_cache_dir()
            file_name = CACHE_NAME_FMT.format(date=date_str, res=resolution)
            cache_path = os.path.join(CACHE_DIR, file_name)
            if download_image(image_url, cache_path):
                if set_wallpaper(cache_path):
                    history.append(date_str)
                    if len(history) > 50:
                        del history[:len(history) - 50]
                    self._persist_wallpaper_date(date_str)
                    return True, f"轮巡壁纸: {date_str}"
                # 下载成功但设置失败 -> 清理损坏文件，跳过此日期
                try:
                    os.remove(cache_path)
                except Exception:
                    pass

        return False, "轮巡: 多次尝试后未找到可用壁纸"

    def do_update_now(self):
        """立即更新壁纸（默认双击动作），同时更新持久化标记"""
        with self._lock:
            mode = self.config["mode"]
        if mode == MODE_SCHEDULED:
            success, msg = self.do_rotate_wallpaper()
            if success:
                with self._lock:
                    self._last_switch_time = datetime.now()
        elif mode == MODE_DAILY:
            success, msg, wp_date = self.do_daily_update()
            if success:
                self.update_config(**{"_daily_applied_date": wp_date})
        elif mode == MODE_DATE:
            success, msg, wp_date = self.do_update_wallpaper()
            if success:
                with self._lock:
                    target = self.config.get("specific_date", "")
                self.update_config(**{"_date_applied_key": f"date:{target}"})
        else:
            success, msg = False, "未知模式"
        self.notify(msg)

    # ---------- 调度器 ----------
    def start_scheduler(self):
        self._scheduler_thread = threading.Thread(target=self._scheduler_run, daemon=True, name="WallpaperScheduler")
        self._scheduler_thread.start()

    def stop_scheduler(self):
        self._stop_event.set()
        self._poke_event.set()
        if self._scheduler_thread:
            self._scheduler_thread.join(timeout=5)

    def _scheduler_run(self):
        """调度器主循环：每 60 秒检查；同时保证线程异常后不会死。"""
        while not self._stop_event.is_set():
            try:
                self._scheduler_tick()
            except Exception as e:
                # 捕获所有异常，打印但不退出循环
                print(f"[{datetime.now():%Y-%m-%d %H:%M:%S}] 调度器 tick 错误: {e}")
            try:
                # 等待 60 秒或被主动唤醒
                self._poke_event.wait(timeout=60)
            except Exception:
                pass
            if self._poke_event.is_set():
                self._poke_event.clear()
                # 唤醒后重置调度触发条件（保留轮巡索引），避免变更后等待太久
                with self._lock:
                    self._last_switch_time = None
                    # 注意：_daily_applied_date / _date_applied_key 持久化在 config 中
                    # 这里不清空（防止误触发重复更新）；若需要强制切换，通过立即更新按钮即可

    def _scheduler_tick(self):
        with self._lock:
            config = dict(self.config)
        mode = config["mode"]
        now = datetime.now()

        # —— 每日清理一次过期缓存 ——
        if self._last_cache_clean is None or (now - self._last_cache_clean).total_seconds() >= 86400:
            try:
                clean_expired_cache(config.get("cache_days", 7))
            except Exception as e:
                print(f"清理缓存错误: {e}")
            self._last_cache_clean = now

        # —— 每日当日模式 ——
        if mode == MODE_DAILY:
            target = get_daily_target_date(now)
            applied = config.get("_daily_applied_date", "")
            if applied != target:
                # 直接构造 URL 下载，跳过 JSON API（与轮巡机制一致）
                success, msg, wp_date = self.do_daily_update()
                if success:
                    self.update_config(**{"_daily_applied_date": target})
                    self.notify(msg)
                # 下载失败（如 00:15 时服务端偶发延迟）→ 不标记，60 秒后自动重试

        # —— 指定日期模式 ——
        elif mode == MODE_DATE:
            target = config.get("specific_date", "")
            applied_key = config.get("_date_applied_key", "")
            current_key = f"date:{target}"
            if target and applied_key != current_key:
                success, msg, _ = self.do_update_wallpaper()
                if success:
                    self.update_config(**{"_date_applied_key": current_key})
                    self.notify(msg)

        # —— 定时轮巡模式 ——
        elif mode == MODE_SCHEDULED:
            interval = config.get("schedule_interval_minutes", 60) * 60
            last = self._last_switch_time
            need = last is None or (now - last).total_seconds() >= interval
            if need:
                success, msg = self.do_rotate_wallpaper()
                if success:
                    self._last_switch_time = now
                    self.notify(msg)

    # ---------- 通知 ----------
    def notify(self, message, title=None):
        # 通知已关闭，避免弹窗过多；如需开启改为 self.icon.notify(message, title or APP_TITLE)
        pass

    # ---------- 缓存操作 ----------
    def do_clear_all_cache(self):
        count = clear_all_cache()
        self.notify(f"已清理全部 {count} 张缓存壁纸", "缓存清理")

    # ---------- 菜单回调 ----------
    def on_set_resolution(self, resolution):
        self.update_config(resolution=resolution)

    def on_set_mode(self, mode):
        # 切换模式时清除目标模式的"已应用"标记，确保切回时立即触发更新
        if mode == MODE_DAILY:
            self.update_config(mode=mode, _daily_applied_date="")
        elif mode == MODE_DATE:
            self.update_config(mode=mode, _date_applied_key="")
        else:
            self.update_config(mode=mode)

    def on_set_date(self, date_str):
        self.update_config(specific_date=date_str, mode=MODE_DATE)

    def on_custom_date(self):
        with self._lock:
            default = self.config.get("specific_date") or datetime.now().strftime("%Y%m%d")
        result = show_date_dialog(default)
        if result:
            self.on_set_date(result)

    def on_set_interval(self, minutes):
        self.update_config(schedule_interval_minutes=minutes, mode=MODE_SCHEDULED)

    def on_custom_interval(self):
        with self._lock:
            default_minutes = self.config.get("schedule_interval_minutes", 60)
        minutes = show_interval_dialog(default_minutes)
        if minutes is not None:
            self.on_set_interval(minutes)

    def on_set_cache_days(self, days):
        self.update_config(cache_days=days)
        if days == 0:
            self.notify("已设为永久不清理", "缓存设置")
        else:
            count = clean_expired_cache(days)
            self.notify(f"已设为 {days} 天，清理 {count} 张过期壁纸", "缓存设置")

    def on_custom_cache_days(self):
        with self._lock:
            default_days = self.config.get("cache_days", 7)
        days = show_cache_days_dialog(default_days)
        if days is not None:
            self.on_set_cache_days(days)

    def on_update_now(self):
        threading.Thread(target=self.do_update_now, daemon=True).start()

    def on_toggle_autostart(self):
        new_state = not is_autostart_enabled()
        if set_autostart(new_state):
            self.notify("已开启开机启动" if new_state else "已关闭开机启动")
            if self.icon:
                self.icon.update_menu()

    def on_open_website(self):
        try:
            os.startfile(WEBSITE_URL)
        except Exception as e:
            self.notify(f"打开网站失败: {e}", "错误")

    def on_view_wallpaper_details(self):
        """打开当前壁纸的详情页"""
        import webbrowser
        date_str = self._current_wallpaper_date
        if not date_str or len(date_str) != 8:
            self.notify("当前无壁纸信息，请先更新壁纸", "提示")
            return
        url = f"{WEBSITE_URL}?date={date_str}"
        try:
            webbrowser.open(url)
        except Exception as e:
            self.notify(f"打开详情失败: {e}", "错误")

    def on_exit(self, icon, item=None):
        self.stop_scheduler()
        if icon:
            icon.stop()

    # ---------- 菜单构建 ----------
    @staticmethod
    def _cb(fn, arg):
        """创建符合 pystray 要求的回调（恰好2个参数 icon/item，绑定额外参数）"""
        def callback(icon, item):
            fn(arg)
        return callback

    def _recent_dates(self, count=14):
        dates = []
        today = datetime.now()
        for i in range(count):
            d = today - timedelta(days=i)
            ds = d.strftime("%Y%m%d")
            label = f"{ds[:4]}-{ds[4:6]}-{ds[6:8]}"
            if i == 0:
                label += "（今天）"
            elif i == 1:
                label += "（昨天）"
            dates.append((ds, label))
        return dates

    def build_menu(self):
        # 分辨率子菜单
        res_items = [
            pystray.MenuItem(
                label,
                self._cb(self.on_set_resolution, res),
                checked=lambda i, r=res: self.config["resolution"] == r,
                radio=True,
            )
            for res, label in RESOLUTION_LABELS.items()
        ]

        # 指定日期子菜单
        date_items = [
            pystray.MenuItem(
                label,
                self._cb(self.on_set_date, ds),
                checked=lambda i, d=ds: (
                    self.config.get("specific_date") == d
                    and self.config["mode"] == MODE_DATE
                ),
                radio=True,
            )
            for ds, label in self._recent_dates()
        ]
        date_items.append(pystray.Menu.SEPARATOR)
        date_items.append(
            pystray.MenuItem("自定义日期…", lambda icon, item: self.on_custom_date())
        )

        # 定时轮巡子菜单
        interval_items = [
            pystray.MenuItem(
                INTERVAL_LABELS[m],
                self._cb(self.on_set_interval, m),
                checked=lambda i, mm=m: (
                    self.config.get("schedule_interval_minutes") == mm
                    and self.config["mode"] == MODE_SCHEDULED
                ),
                radio=True,
            )
            for m in INTERVAL_PRESETS
        ]
        interval_items.append(pystray.Menu.SEPARATOR)

        # —— 自定义: XX 分钟（radio 项，选中就用上次自定义的值；值不在预设列表中则自动勾选） ——
        def _custom_minutes_label():
            """在菜单中显示自定义项的标签：自定义: XX 分钟 / XX 小时"""
            m = self.config.get("schedule_interval_minutes", 60)
            if m >= 60 and m % 60 == 0:
                return f"自定义: {m // 60} 小时"
            return f"自定义: {m} 分钟"

        def _custom_is_checked(_):
            """当当前间隔不在预设列表中时，自定义 radio 被勾选。"""
            m = self.config.get("schedule_interval_minutes", 60)
            if self.config.get("mode") != MODE_SCHEDULED:
                return False
            return m not in INTERVAL_PRESETS

        def _custom_on_activate(icon, item):
            """选择自定义 radio：直接弹窗修改数值，确定后立即应用自定义模式"""
            with self._lock:
                cur = self.config.get("schedule_interval_minutes", 60)
                # 上次自定义值若在预设列表中，则保留数值不变（用户可能就是要这个，只是不想勾预设）
                default = cur
            minutes = show_interval_dialog(default)
            if minutes is not None:
                self.on_set_interval(minutes)

        interval_items.append(
            pystray.MenuItem(
                lambda _: _custom_minutes_label(),
                _custom_on_activate,
                checked=_custom_is_checked,
                radio=True,
            )
        )

        # 修改自定义…（弹出对话框改数值）
        interval_items.append(
            pystray.MenuItem("修改自定义…", lambda icon, item: self.on_custom_interval())
        )

        # 模式子菜单
        mode_items = [
            pystray.MenuItem(
                "每日当日",
                lambda icon, item: self.on_set_mode(MODE_DAILY),
                checked=lambda i: self.config["mode"] == MODE_DAILY,
                radio=True,
            ),
            pystray.MenuItem("指定日期", pystray.Menu(*date_items)),
            pystray.MenuItem("定时轮巡", pystray.Menu(*interval_items)),
        ]

        # 缓存子菜单
        cache_items = [
            pystray.MenuItem(
                CACHE_DAYS_LABELS[d],
                self._cb(self.on_set_cache_days, d),
                checked=lambda i, dd=d: self.config["cache_days"] == dd,
                radio=True,
            )
            for d in CACHE_DAYS_PRESETS
        ]
        # 自定义天数（不在预设中时显示当前值）
        custom_cache_label = f"自定义: {self.config['cache_days']} 天"
        if self.config["cache_days"] == 0:
            custom_cache_label = "自定义: 永久"
        cache_items.append(
            pystray.MenuItem(
                custom_cache_label,
                lambda icon, item: self.on_custom_cache_days(),
                checked=lambda i: self.config["cache_days"] not in CACHE_DAYS_PRESETS,
                radio=True,
            )
        )
        cache_items.append(pystray.Menu.SEPARATOR)
        cache_items.append(
            pystray.MenuItem("立即清理缓存", lambda icon, item: self.do_clear_all_cache())
        )

        # 主菜单
        return pystray.Menu(
            pystray.MenuItem(APP_TITLE, None, enabled=False),
            pystray.Menu.SEPARATOR,
            pystray.MenuItem(
                "查看当前壁纸详情",
                lambda icon, item: self.on_view_wallpaper_details(),
            ),
            pystray.MenuItem("分辨率", pystray.Menu(*res_items)),
            pystray.MenuItem("切换模式", pystray.Menu(*mode_items)),
            pystray.Menu.SEPARATOR,
            pystray.MenuItem(
                "立即更新壁纸",
                lambda icon, item: self.on_update_now(),
                default=True,
            ),
            pystray.Menu.SEPARATOR,
            pystray.MenuItem("缓存设置", pystray.Menu(*cache_items)),
            pystray.MenuItem(
                "开机启动",
                lambda icon, item: self.on_toggle_autostart(),
                checked=lambda i: is_autostart_enabled(),
            ),
            pystray.MenuItem("打开壁纸网站", lambda icon, item: self.on_open_website()),
            pystray.Menu.SEPARATOR,
            pystray.MenuItem("退出", lambda icon, item: self.on_exit(icon, item)),
        )

    # ---------- 图标加载 ----------
    def _load_icon(self):
        for name in ("bing.ico", "bing.png"):
            path = os.path.join(ICON_DIR, name)
            if os.path.exists(path):
                try:
                    img = Image.open(path)
                    if img.mode != "RGBA":
                        img = img.convert("RGBA")
                    return img
                except Exception:
                    pass
        return Image.new("RGBA", (64, 64), (0, 80, 160, 255))

    # ---------- 启动 ----------
    def run(self):
        clean_expired_cache(self.config.get("cache_days", 7))
        self.icon = pystray.Icon(
            APP_NAME,
            self._load_icon(),
            APP_TITLE,
            self.build_menu(),
        )
        self.start_scheduler()
        self._poke_event.set()
        self.icon.run()


def main():
    BingWallpaperApp().run()


if __name__ == "__main__":
    main()
