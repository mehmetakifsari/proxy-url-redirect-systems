for proc in psutil.process_iter(['pid', 'name', 'cmdline']):
    try:
        if 'python3' in (proc.info['name'] or '') or (
            proc.info['cmdline'] and 'python3' in proc.info['cmdline'][0]
        ):
            with proc.oneshot():
                cpu = proc.cpu_percent(interval=0.1)
                mem = proc.memory_percent()
                cmd = ' '.join(proc.info['cmdline'])
                print(f"PID: {proc.info['pid']}")
                print(f"CPU: {cpu:.1f}%, RAM: {mem:.2f}%")
                print(f"Komut: {cmd}")
                print("-" * 40)
    except (psutil.NoSuchProcess, psutil.AccessDenied):
        continue
