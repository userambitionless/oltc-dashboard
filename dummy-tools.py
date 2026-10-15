import tkinter as tk
from tkinter import ttk, messagebox
import requests
import random
import threading
import time
from datetime import datetime

API_URL = "http://localhost/oltc-dashboard/public/api/record.php"
API_KEY = "42213914a9664577b247de3fb56379dd892d397b23b56389d597be57b27892fb"

TOTAL_DATA = 10
INTERVAL = 10


class DummySenderApp:
    def __init__(self, root):
        self.root = root
        self.root.title("OLTC Dummy Data Sender")
        self.root.geometry("560x620")
        self.root.resizable(False, False)

        self.running = False
        self.thread = None

        self.status_var = tk.StringVar(value="Siap mengirim data dummy")
        self.progress_var = tk.DoubleVar(value=0)
        self.count_var = tk.StringVar(value="0 / 10")
        self.timer_var = tk.StringVar(value="-")

        self.build_ui()

    def build_ui(self):
        main = ttk.Frame(self.root, padding=20)
        main.pack(fill="both", expand=True)

        title = ttk.Label(
            main,
            text="OLTC Dummy Data Sender",
            font=("Segoe UI", 18, "bold")
        )
        title.pack(anchor="w")

        subtitle = ttk.Label(
            main,
            text="Kirim 10 nilai counter dummy ke API setiap 10 detik.",
            font=("Segoe UI", 10)
        )
        subtitle.pack(anchor="w", pady=(4, 18))

        info = ttk.LabelFrame(main, text="Konfigurasi", padding=12)
        info.pack(fill="x")

        ttk.Label(info, text="API:").grid(row=0, column=0, sticky="w")
        ttk.Label(
            info,
            text=API_URL,
            wraplength=430
        ).grid(row=0, column=1, sticky="w", padx=(10, 0))

        ttk.Label(info, text="Interval:").grid(row=1, column=0, sticky="w", pady=(8, 0))
        ttk.Label(info, text="10 detik").grid(row=1, column=1, sticky="w", padx=(10, 0), pady=(8, 0))

        ttk.Label(info, text="Jumlah data:").grid(row=2, column=0, sticky="w", pady=(8, 0))
        ttk.Label(info, text="10 data").grid(row=2, column=1, sticky="w", padx=(10, 0), pady=(8, 0))

        control = ttk.Frame(main)
        control.pack(fill="x", pady=16)

        self.start_button = ttk.Button(
            control,
            text="Mulai Kirim Data",
            command=self.start_sending
        )
        self.start_button.pack(side="left")

        self.stop_button = ttk.Button(
            control,
            text="Hentikan",
            command=self.stop_sending,
            state="disabled"
        )
        self.stop_button.pack(side="left", padx=(8, 0))

        progress_frame = ttk.LabelFrame(main, text="Progress", padding=12)
        progress_frame.pack(fill="x")

        ttk.Label(progress_frame, textvariable=self.status_var).pack(anchor="w")

        self.progress = ttk.Progressbar(
            progress_frame,
            variable=self.progress_var,
            maximum=TOTAL_DATA
        )
        self.progress.pack(fill="x", pady=10)

        status_row = ttk.Frame(progress_frame)
        status_row.pack(fill="x")

        ttk.Label(status_row, text="Terkirim:").pack(side="left")
        ttk.Label(
            status_row,
            textvariable=self.count_var,
            font=("Segoe UI", 10, "bold")
        ).pack(side="left", padx=(5, 25))

        ttk.Label(status_row, text="Timer:").pack(side="left")
        ttk.Label(
            status_row,
            textvariable=self.timer_var,
            font=("Segoe UI", 10, "bold")
        ).pack(side="left", padx=5)

        log_frame = ttk.LabelFrame(main, text="Log Pengiriman", padding=8)
        log_frame.pack(fill="both", expand=True, pady=(16, 0))

        self.log = tk.Text(
            log_frame,
            height=18,
            width=65,
            state="disabled",
            font=("Consolas", 9)
        )
        self.log.pack(side="left", fill="both", expand=True)

        scrollbar = ttk.Scrollbar(
            log_frame,
            orient="vertical",
            command=self.log.yview
        )
        scrollbar.pack(side="right", fill="y")
        self.log.configure(yscrollcommand=scrollbar.set)

    def write_log(self, text):
        def update():
            self.log.configure(state="normal")
            self.log.insert("end", text + "\n")
            self.log.see("end")
            self.log.configure(state="disabled")

        self.root.after(0, update)

    def set_status(self, text):
        self.root.after(0, lambda: self.status_var.set(text))

    def set_progress(self, value):
        self.root.after(0, lambda: self.progress_var.set(value))
        self.root.after(0, lambda: self.count_var.set(f"{int(value)} / {TOTAL_DATA}"))

    def set_timer(self, value):
        self.root.after(0, lambda: self.timer_var.set(value))

    def start_sending(self):
        if self.running:
            return

        self.running = True
        self.start_button.config(state="disabled")
        self.stop_button.config(state="normal")
        self.progress_var.set(0)
        self.count_var.set("0 / 10")
        self.timer_var.set("-")

        self.log.configure(state="normal")
        self.log.delete("1.0", "end")
        self.log.configure(state="disabled")

        self.write_log("=" * 55)
        self.write_log("MEMULAI PENGIRIMAN 10 DATA DUMMY")
        self.write_log("=" * 55)

        self.thread = threading.Thread(
            target=self.send_data,
            daemon=True
        )
        self.thread.start()

    def stop_sending(self):
        self.running = False
        self.set_status("Pengiriman dihentikan.")
        self.set_timer("-")
        self.start_button.config(state="normal")
        self.stop_button.config(state="disabled")
        self.write_log(">> Pengiriman dihentikan oleh pengguna.")

    def send_data(self):
        success_count = 0

        for i in range(TOTAL_DATA):
            if not self.running:
                return

            value = random.randint(100000, 999999)
            now = datetime.now()

            payload = {
                "tanggal": now.strftime("%Y-%m-%d"),
                "jam": now.strftime("%H:%M:%S"),
                "nilai_data": str(value)
            }

            self.set_status(f"Mengirim data {i + 1} dari {TOTAL_DATA}...")
            self.write_log("")
            self.write_log("-" * 55)
            self.write_log(f"Data ke-{i + 1} dari {TOTAL_DATA}")
            self.write_log(f"Tanggal : {payload['tanggal']}")
            self.write_log(f"Jam     : {payload['jam']}")
            self.write_log(f"Nilai   : {payload['nilai_data']}")

            try:
                response = requests.post(
                    API_URL,
                    headers={"X-API-Key": API_KEY},
                    data=payload,
                    timeout=15
                )

                response.raise_for_status()
                result = response.json()

                success_count += 1
                record_id = result.get("data", {}).get("id", "-")

                self.write_log("STATUS  : BERHASIL")
                self.write_log(f"ID      : {record_id}")
                self.set_progress(i + 1)

            except requests.exceptions.RequestException as e:
                self.write_log("STATUS  : GAGAL")
                self.write_log(f"ERROR   : {e}")

                try:
                    self.write_log(f"DETAIL  : {response.text}")
                except Exception:
                    pass

            except Exception as e:
                self.write_log("STATUS  : GAGAL")
                self.write_log(f"ERROR   : {e}")

            if i < TOTAL_DATA - 1 and self.running:
                for seconds in range(INTERVAL, 0, -1):
                    if not self.running:
                        return

                    self.set_status(
                        f"Menunggu data berikutnya... ({seconds} detik)"
                    )
                    self.set_timer(f"{seconds} detik")
                    time.sleep(1)

        if self.running:
            self.running = False
            self.set_status(
                f"Selesai. {success_count} dari {TOTAL_DATA} data berhasil dikirim."
            )
            self.set_timer("-")
            self.start_button.config(state="normal")
            self.stop_button.config(state="disabled")

            self.write_log("")
            self.write_log("=" * 55)
            self.write_log("SELESAI")
            self.write_log(f"{success_count} dari {TOTAL_DATA} data berhasil dikirim.")
            self.write_log("=" * 55)

            self.root.after(
                100,
                lambda: messagebox.showinfo(
                    "Selesai",
                    f"Pengiriman selesai.\n\n"
                    f"Berhasil: {success_count}\n"
                    f"Total: {TOTAL_DATA}"
                )
            )


if __name__ == "__main__":
    root = tk.Tk()
    app = DummySenderApp(root)
    root.mainloop()
