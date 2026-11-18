using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.IO;
using System.Management;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Text;
using System.Threading.Tasks;
using System.Windows.Forms;
using Microsoft.Win32;

[assembly: AssemblyTitle("RS-Base Client Uninstaller")]
[assembly: AssemblyDescription("医療法人小野会 RS-Base クライアント連携 アンインストール＆診断ログ送信ツール")]
[assembly: AssemblyCompany("医療法人小野会 おの肛門科胃腸科内視鏡クリニック")]
[assembly: AssemblyProduct("RS-Base Client Integration Uninstaller")]
[assembly: AssemblyVersion("2.2.2.0")]
[assembly: AssemblyFileVersion("2.2.2.0")]

namespace RSBaseUninstaller
{
    internal static class NativeMethods
    {
        [DllImport("user32.dll")]
        public static extern bool SetProcessDPIAware();

        [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        public static extern bool DeleteFile(string lpFileName);

        public static void UnblockFile(string path)
        {
            if (string.IsNullOrEmpty(path) || !File.Exists(path)) return;
            try { DeleteFile(path + ":Zone.Identifier"); } catch { }
        }
    }

    internal sealed class UninstallOptions
    {
        public string CustomServerUrl = "";
        public string SandboxDir = "";
        public bool Silent = false;
        public bool DiagOnly = false;
        public bool RemoveLogs = false;
        public bool NoReport = false;
    }

    internal sealed class EnvDiagnostic
    {
        public string Hostname = "";
        public string Username = "";
        public string OsInfo = "";
        public string ClientIps = "";
        public string Version = "";
        public string ActiveInstallDir = "";
        public int RunningWatcherCount = 0;
        public List<string> StartupItems = new List<string>();
        public List<string> InstalledFiles = new List<string>();
        public string LocalLogPath = "";
        public bool WshOk = true;
        public bool SmbOk = false;
        public bool RsViewOk = false;
        public bool EcgOk = false;
        public bool JpegToolOk = false;
        public bool CdBurnOk = false;
    }

    internal static class UninstallEngine
    {
        private static readonly string[] ManagedClientFiles = new[]
        {
            "watcher.vbs",
            "watcher.vbs.upd",
            "watcher.vbs.legacy_bak",
            "cd_burn.ps1",
            "cd_burn.ps1.upd",
            "watcher_version.txt"
        };

        private static readonly string[] ManagedStartupNames = new[]
        {
            "RSBase_Watcher.lnk",
            "RS-Base_Watcher.lnk",
            "watcher.vbs"
        };

        public static void ResolvePaths(UninstallOptions opts, out string primaryDir, out string fallbackDir, out string startupDir)
        {
            bool isSandbox = !string.IsNullOrWhiteSpace(opts.SandboxDir);
            primaryDir = isSandbox ? Path.Combine(opts.SandboxDir, "RSBASE") : @"C:\RSBASE";
            fallbackDir = isSandbox
                ? Path.Combine(opts.SandboxDir, "LocalAppData_RSBASE")
                : Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "RSBASE");
            startupDir = isSandbox
                ? Path.Combine(opts.SandboxDir, "Startup")
                : Environment.GetFolderPath(Environment.SpecialFolder.Startup);
        }

        public static string GetOsDescription()
        {
            try
            {
                using (RegistryKey rk = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\Windows NT\CurrentVersion"))
                {
                    if (rk != null)
                    {
                        string prod = Convert.ToString(rk.GetValue("ProductName") ?? "Windows");
                        string dispVer = Convert.ToString(rk.GetValue("DisplayVersion") ?? "");
                        string build = Convert.ToString(rk.GetValue("CurrentBuildNumber") ?? "");
                        int buildNum;
                        if (int.TryParse(build, out buildNum) && buildNum >= 22000 && prod.Contains("Windows 10"))
                        {
                            prod = prod.Replace("Windows 10", "Windows 11");
                        }
                        return (prod + (string.IsNullOrEmpty(dispVer) ? "" : " " + dispVer) + (string.IsNullOrEmpty(build) ? "" : " (Build " + build + ")")).Trim();
                    }
                }
            }
            catch { }
            return Environment.OSVersion.VersionString;
        }

        public static string GetLocalIPv4Csv()
        {
            List<string> ips = new List<string>();
            try
            {
                foreach (NetworkInterface ni in NetworkInterface.GetAllNetworkInterfaces())
                {
                    if (ni.OperationalStatus != OperationalStatus.Up) continue;
                    if (ni.NetworkInterfaceType == NetworkInterfaceType.Loopback) continue;
                    foreach (UnicastIPAddressInformation ua in ni.GetIPProperties().UnicastAddresses)
                    {
                        if (ua.Address.AddressFamily == AddressFamily.InterNetwork)
                        {
                            string ip = ua.Address.ToString();
                            if (!ip.StartsWith("169.254.") && ip != "127.0.0.1" && !ips.Contains(ip))
                            {
                                ips.Add(ip);
                            }
                        }
                    }
                }
            }
            catch { }
            return string.Join(",", ips);
        }

        public static bool CheckWshEnabled()
        {
            try
            {
                using (RegistryKey hkcu = Registry.CurrentUser.OpenSubKey(@"Software\Microsoft\Windows Script Host\Settings"))
                {
                    if (hkcu != null)
                    {
                        object v = hkcu.GetValue("Enabled");
                        if (v != null && Convert.ToInt32(v) == 0) return false;
                    }
                }
                using (RegistryKey hklm = Registry.LocalMachine.OpenSubKey(@"Software\Microsoft\Windows Script Host\Settings"))
                {
                    if (hklm != null)
                    {
                        object v = hklm.GetValue("Enabled");
                        if (v != null && Convert.ToInt32(v) == 0) return false;
                    }
                }
            }
            catch { }
            return true;
        }

        public static int CountRunningWatchers()
        {
            int count = 0;
            try
            {
                using (var searcher = new ManagementObjectSearcher(
                    "SELECT ProcessId, CommandLine FROM Win32_Process WHERE Name='wscript.exe' OR Name='cscript.exe'"))
                using (var results = searcher.Get())
                {
                    foreach (ManagementObject mo in results)
                    {
                        string cmd = Convert.ToString(mo["CommandLine"] ?? "");
                        if (cmd.IndexOf("watcher.vbs", StringComparison.OrdinalIgnoreCase) >= 0)
                        {
                            count++;
                        }
                    }
                }
            }
            catch { }
            return count;
        }

        public static int StopRunningWatchers(Action<string> logger)
        {
            int stopped = 0;
            try
            {
                using (var searcher = new ManagementObjectSearcher(
                    "SELECT ProcessId, CommandLine FROM Win32_Process WHERE Name='wscript.exe' OR Name='cscript.exe'"))
                using (var results = searcher.Get())
                {
                    foreach (ManagementObject mo in results)
                    {
                        string cmd = Convert.ToString(mo["CommandLine"] ?? "");
                        if (cmd.IndexOf("watcher.vbs", StringComparison.OrdinalIgnoreCase) >= 0)
                        {
                            int pid = Convert.ToInt32(mo["ProcessId"]);
                            try
                            {
                                using (Process p = Process.GetProcessById(pid))
                                {
                                    p.Kill();
                                    p.WaitForExit(2000);
                                    stopped++;
                                    if (logger != null) logger("常駐プロセス watcher.vbs (PID: " + pid + ") を停止しました。");
                                }
                            }
                            catch { }
                        }
                    }
                }
            }
            catch (Exception ex)
            {
                if (logger != null) logger("プロセス確認情報: " + ex.Message);
            }
            return stopped;
        }

        public static EnvDiagnostic ScanEnvironment(UninstallOptions opts)
        {
            string primaryDir, fallbackDir, startupDir;
            ResolvePaths(opts, out primaryDir, out fallbackDir, out startupDir);
            bool isSandbox = !string.IsNullOrWhiteSpace(opts.SandboxDir);

            EnvDiagnostic d = new EnvDiagnostic
            {
                Hostname = Environment.MachineName,
                Username = Environment.UserName,
                OsInfo = GetOsDescription(),
                ClientIps = GetLocalIPv4Csv(),
                WshOk = CheckWshEnabled(),
                RunningWatcherCount = isSandbox ? 0 : CountRunningWatchers()
            };

            foreach (string sName in ManagedStartupNames)
            {
                string sp = Path.Combine(startupDir, sName);
                if (File.Exists(sp)) d.StartupItems.Add(sp);
            }

            foreach (string dir in new[] { primaryDir, fallbackDir })
            {
                if (!Directory.Exists(dir)) continue;
                foreach (string fName in ManagedClientFiles)
                {
                    string fp = Path.Combine(dir, fName);
                    if (File.Exists(fp))
                    {
                        d.InstalledFiles.Add(fp);
                        if (string.IsNullOrEmpty(d.ActiveInstallDir)) d.ActiveInstallDir = dir;
                    }
                }
                string verFp = Path.Combine(dir, "watcher_version.txt");
                if (string.IsNullOrEmpty(d.Version) && File.Exists(verFp))
                {
                    try { d.Version = File.ReadAllText(verFp, Encoding.ASCII).Trim(); } catch { }
                }
                string logFp = Path.Combine(Path.Combine(dir, "logs"), "client.log");
                if (string.IsNullOrEmpty(d.LocalLogPath) && File.Exists(logFp))
                {
                    d.LocalLogPath = logFp;
                }
            }

            string tmpLog = Path.Combine(Path.GetTempPath(), "rsbase_client.log");
            if (string.IsNullOrEmpty(d.LocalLogPath) && File.Exists(tmpLog))
            {
                d.LocalLogPath = tmpLog;
            }

            d.SmbOk = Directory.Exists(@"\\192.168.1.16\htdocs\rsbase\tmp") || Directory.Exists(@"C:\Apache24\htdocs\rsbase\tmp");
            d.RsViewOk = File.Exists(@"C:\RSBASE\RSB_public_html\RSViewer\rsview.exe");
            d.EcgOk = File.Exists(@"C:\RSBASE\RSB_public_html\denshi\FEV-05.exe");
            string deskTool = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory), @"DicomTool\dicom_convert.py");
            d.JpegToolOk = File.Exists(deskTool) || File.Exists(@"C:\Users\Owner\Desktop\DicomTool\dicom_convert.py");
            d.CdBurnOk = File.Exists(Path.Combine(primaryDir, "cd_burn.ps1")) || File.Exists(Path.Combine(fallbackDir, "cd_burn.ps1"));

            return d;
        }

        public static string ReadLocalLogTail(string logPath, int maxLines = 80)
        {
            if (string.IsNullOrEmpty(logPath) || !File.Exists(logPath)) return "";
            try
            {
                string[] lines;
                try
                {
                    lines = File.ReadAllLines(logPath, Encoding.UTF8);
                }
                catch
                {
                    lines = File.ReadAllLines(logPath, Encoding.GetEncoding(932));
                }
                int start = Math.Max(0, lines.Length - maxLines);
                StringBuilder sb = new StringBuilder();
                for (int i = start; i < lines.Length; i++)
                {
                    sb.AppendLine(lines[i]);
                }
                return sb.ToString().Trim();
            }
            catch (Exception ex)
            {
                return "(ローカルログ読込エラー: " + ex.Message + ")";
            }
        }

        public static void AppendLocalClientLog(UninstallOptions opts, string level, string ev, string msg)
        {
            try
            {
                string primaryDir, fallbackDir, startupDir;
                ResolvePaths(opts, out primaryDir, out fallbackDir, out startupDir);
                string baseDir = Directory.Exists(primaryDir) ? primaryDir : fallbackDir;
                string logDir = Path.Combine(baseDir, "logs");
                Directory.CreateDirectory(logDir);
                string logFp = Path.Combine(logDir, "client.log");
                if (File.Exists(logFp) && new FileInfo(logFp).Length > 524288)
                {
                    string oldFp = logFp + ".old";
                    if (File.Exists(oldFp)) File.Delete(oldFp);
                    File.Move(logFp, oldFp);
                }
                string line = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + " [" + level + "] [uninstall] [" + ev + "] " + msg + Environment.NewLine;
                File.AppendAllText(logFp, line, Encoding.GetEncoding(932));
            }
            catch { }
        }

        public static bool SendDiagnosticReport(
            UninstallOptions opts,
            EnvDiagnostic diag,
            string level,
            string eventName,
            string message,
            string extraLog,
            Action<string> logger)
        {
            List<string> candidates = new List<string>();
            if (!string.IsNullOrWhiteSpace(opts.CustomServerUrl))
            {
                candidates.Add(opts.CustomServerUrl.TrimEnd('/'));
            }
            candidates.Add("http://192.168.1.16/rsbase");
            candidates.Add("http://127.0.0.1/rsbase");

            StringBuilder detail = new StringBuilder();
            detail.AppendLine("[端末環境スナップショット]");
            detail.AppendLine("OS: " + diag.OsInfo);
            detail.AppendLine("WSH(VBScript実行): " + (diag.WshOk ? "有効" : "無効(レジストリ制限)"));
            detail.AppendLine("常駐watcher.vbs数: " + diag.RunningWatcherCount);
            detail.AppendLine("スタートアップ登録: " + (diag.StartupItems.Count > 0 ? string.Join(", ", diag.StartupItems) : "なし"));
            detail.AppendLine("配置ファイル: " + (diag.InstalledFiles.Count > 0 ? string.Join(", ", diag.InstalledFiles) : "なし"));
            detail.AppendLine("SMB共有到達: " + (diag.SmbOk ? "OK" : "NG") + " / RSViewer: " + (diag.RsViewOk ? "有" : "無") + " / FEV-05: " + (diag.EcgOk ? "有" : "無"));

            if (!string.IsNullOrWhiteSpace(extraLog))
            {
                detail.AppendLine();
                detail.AppendLine("[アンインストール実行ログ]");
                detail.AppendLine(extraLog.Trim());
            }

            string logTail = ReadLocalLogTail(diag.LocalLogPath, 80);
            if (!string.IsNullOrWhiteSpace(logTail))
            {
                detail.AppendLine();
                detail.AppendLine("[ローカル client.log 直近履歴 (" + diag.LocalLogPath + ")]");
                detail.AppendLine(logTail);
            }

            var fields = new Dictionary<string, string>
            {
                { "action", "report" },
                { "source", "uninstall" },
                { "level", level },
                { "event", eventName },
                { "hostname", diag.Hostname },
                { "username", diag.Username },
                { "client_ips", diag.ClientIps },
                { "version", string.IsNullOrEmpty(diag.Version) ? "2.2.2" : diag.Version },
                { "install_dir", diag.ActiveInstallDir },
                { "os_info", diag.OsInfo },
                { "client_ts", DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") },
                { "wsh_ok", diag.WshOk ? "1" : "0" },
                { "smb_ok", diag.SmbOk ? "1" : "0" },
                { "rsview_ok", diag.RsViewOk ? "1" : "0" },
                { "ecg_ok", diag.EcgOk ? "1" : "0" },
                { "jpeg_tool_ok", diag.JpegToolOk ? "1" : "0" },
                { "cdburn_ok", diag.CdBurnOk ? "1" : "0" },
                { "message", message },
                { "detail", detail.ToString() }
            };

            StringBuilder postBody = new StringBuilder();
            foreach (var kv in fields)
            {
                if (postBody.Length > 0) postBody.Append('&');
                postBody.Append(Uri.EscapeDataString(kv.Key));
                postBody.Append('=');
                postBody.Append(Uri.EscapeDataString(kv.Value ?? ""));
            }
            byte[] payloadBytes = Encoding.UTF8.GetBytes(postBody.ToString());

            foreach (string baseUrl in candidates)
            {
                try
                {
                    HttpWebRequest req = (HttpWebRequest)WebRequest.Create(baseUrl + "/client_logs.php?action=report");
                    req.Method = "POST";
                    req.ContentType = "application/x-www-form-urlencoded; charset=UTF-8";
                    req.Timeout = 3500;
                    req.ReadWriteTimeout = 3500;
                    req.ContentLength = payloadBytes.Length;
                    using (Stream st = req.GetRequestStream())
                    {
                        st.Write(payloadBytes, 0, payloadBytes.Length);
                    }
                    using (HttpWebResponse resp = (HttpWebResponse)req.GetResponse())
                    {
                        if (resp.StatusCode == HttpStatusCode.OK)
                        {
                            if (logger != null) logger("RS-Baseサーバー (" + baseUrl + ") へ診断ログを送信しました。");
                            return true;
                        }
                    }
                }
                catch { }
            }

            if (logger != null) logger("※サーバーへ診断ログを送信できませんでした（オフラインまたは通信制限）。PC内ログは保持されます。");
            return false;
        }

        public static int ExecuteUninstall(
            UninstallOptions opts,
            bool sendDiagReport,
            bool removeLocalLogs,
            Action<string> log)
        {
            NativeMethods.UnblockFile(Application.ExecutablePath);
            EnvDiagnostic preDiag = ScanEnvironment(opts);
            StringBuilder opLog = new StringBuilder();
            Action<string> stepLog = (msg) =>
            {
                opLog.AppendLine(msg);
                if (log != null) log(msg);
            };

            bool isSandbox = !string.IsNullOrWhiteSpace(opts.SandboxDir);
            string primaryDir, fallbackDir, startupDir;
            ResolvePaths(opts, out primaryDir, out fallbackDir, out startupDir);

            // 1. Stop running watcher.vbs processes
            int stopped = 0;
            if (!isSandbox)
            {
                stopped = StopRunningWatchers(stepLog);
            }
            if (stopped == 0)
            {
                stepLog("稼働中の watcher.vbs プロセスはありませんでした。");
            }

            // 2. Remove Startup shortcuts/scripts
            int removedStartup = 0;
            if (Directory.Exists(startupDir))
            {
                foreach (string sName in ManagedStartupNames)
                {
                    string sp = Path.Combine(startupDir, sName);
                    if (File.Exists(sp))
                    {
                        try
                        {
                            File.Delete(sp);
                            removedStartup++;
                            stepLog("自動起動（スタートアップ）を解除しました: " + sName);
                        }
                        catch (Exception ex)
                        {
                            stepLog("スタートアップ削除エラー (" + sName + "): " + ex.Message);
                        }
                    }
                }
            }
            if (removedStartup == 0)
            {
                stepLog("スタートアップ内の登録項目はありませんでした。");
            }

            // 3. Safely delete ONLY managed client integration files (NEVER touch RSB_public_html!)
            int removedFiles = 0;
            foreach (string dir in new[] { primaryDir, fallbackDir })
            {
                if (!Directory.Exists(dir)) continue;
                foreach (string fName in ManagedClientFiles)
                {
                    string fp = Path.Combine(dir, fName);
                    if (File.Exists(fp))
                    {
                        try
                        {
                            File.Delete(fp);
                            removedFiles++;
                            stepLog("連携ファイルを削除しました: " + fp);
                        }
                        catch (Exception ex)
                        {
                            stepLog("ファイル削除エラー (" + fp + "): " + ex.Message);
                        }
                    }
                }
            }

            // Clean up temp CD burn script copy if present
            if (!isSandbox)
            {
                string tmpPs1 = Path.Combine(Path.GetTempPath(), "rsbase_cd_burn.ps1");
                if (File.Exists(tmpPs1))
                {
                    try { File.Delete(tmpPs1); removedFiles++; stepLog("一時スクリプトを整理しました: " + tmpPs1); } catch { }
                }
            }

            // 4. Send diagnostic + uninstall report BEFORE optionally deleting local log file
            if (sendDiagReport && !opts.NoReport)
            {
                preDiag.CdBurnOk = false;
                SendDiagnosticReport(
                    opts,
                    preDiag,
                    "INFO",
                    "UNINSTALL_OK",
                    "PC連携ツールをアンインストールしました (停止:" + stopped + "件 / 削除:" + removedFiles + "ファイル)",
                    opLog.ToString(),
                    stepLog);
            }

            // 5. Handle local logs (keep for audit or delete if requested)
            if (removeLocalLogs)
            {
                foreach (string dir in new[] { primaryDir, fallbackDir })
                {
                    string logDir = Path.Combine(dir, "logs");
                    if (!Directory.Exists(logDir)) continue;
                    foreach (string lf in new[] { "client.log", "client.log.old" })
                    {
                        string lfp = Path.Combine(logDir, lf);
                        if (File.Exists(lfp))
                        {
                            try { File.Delete(lfp); stepLog("ローカル診断ログを削除しました: " + lfp); } catch { }
                        }
                    }
                    try
                    {
                        if (Directory.GetFileSystemEntries(logDir).Length == 0) Directory.Delete(logDir, false);
                    }
                    catch { }
                }
                if (!isSandbox)
                {
                    string tmpLog = Path.Combine(Path.GetTempPath(), "rsbase_client.log");
                    if (File.Exists(tmpLog))
                    {
                        try { File.Delete(tmpLog); } catch { }
                    }
                }
            }
            else
            {
                AppendLocalClientLog(opts, "INFO", "UNINSTALL_OK", "アンインストール完了 (停止:" + stopped + ", 削除:" + removedFiles + ")");
            }

            // Clean up fallback LocalAppData\RSBASE directory ONLY if completely empty (NEVER delete C:\RSBASE!)
            if (Directory.Exists(fallbackDir))
            {
                try
                {
                    if (Directory.GetFileSystemEntries(fallbackDir).Length == 0)
                    {
                        Directory.Delete(fallbackDir, false);
                    }
                }
                catch { }
            }

            stepLog("RS-Base本体フォルダ (RSB_public_html等) は保護されています。");
            return 0;
        }
    }

    internal sealed class UninstallForm : Form
    {
        private readonly UninstallOptions _opts;
        private EnvDiagnostic _currentDiag;
        private Label _lblStatusTitle;
        private Label _lblBadge;
        private Panel _summaryPanel;
        private Label _lblSummaryHead;
        private Label _lblSummaryBody;
        private TextBox _txtLog;
        private CheckBox _chkSendDiag;
        private CheckBox _chkRemoveLogs;
        private Button _btnOpenLog;
        private Button _btnDiagOnly;
        private Button _btnUninstall;
        private Button _btnClose;
        private Icon _appIcon;

        public int ExitCodeResult { get; private set; }

        public UninstallForm(UninstallOptions opts)
        {
            _opts = opts;
            ExitCodeResult = 0;
            InitializeUi();
        }

        private Icon CreateUninstallIcon()
        {
            using (Bitmap bmp = new Bitmap(64, 64))
            using (Graphics g = Graphics.FromImage(bmp))
            {
                g.SmoothingMode = SmoothingMode.AntiAlias;
                using (LinearGradientBrush bg = new LinearGradientBrush(
                    new Rectangle(0, 0, 64, 64),
                    Color.FromArgb(153, 27, 27),
                    Color.FromArgb(220, 38, 38),
                    45f))
                {
                    g.FillEllipse(bg, 3, 3, 58, 58);
                }
                using (Pen ring = new Pen(Color.FromArgb(254, 202, 202), 2.5f))
                {
                    g.DrawEllipse(ring, 4, 4, 56, 56);
                }
                using (Brush white = new SolidBrush(Color.White))
                {
                    g.FillRectangle(white, 16, 27, 32, 10);
                }
                return Icon.FromHandle(bmp.GetHicon());
            }
        }

        private void InitializeUi()
        {
            this.Font = new Font("Yu Gothic UI", 9.5f, FontStyle.Regular);
            this.AutoScaleMode = AutoScaleMode.Dpi;
            this.Text = "RS-Base PC連携 アンインストール専用ツール (v2.2.2)";
            this.ClientSize = new Size(580, 475);
            this.FormBorderStyle = FormBorderStyle.FixedDialog;
            this.MaximizeBox = false;
            this.StartPosition = FormStartPosition.CenterScreen;
            this.BackColor = Color.FromArgb(248, 250, 252);

            try
            {
                _appIcon = CreateUninstallIcon();
                this.Icon = _appIcon;
            }
            catch { }

            Panel header = new Panel
            {
                Dock = DockStyle.Top,
                Height = 76,
                BackColor = Color.FromArgb(127, 29, 29)
            };
            header.Paint += (s, e) =>
            {
                using (LinearGradientBrush lg = new LinearGradientBrush(
                    header.ClientRectangle,
                    Color.FromArgb(127, 29, 29),
                    Color.FromArgb(185, 28, 28),
                    0f))
                {
                    e.Graphics.FillRectangle(lg, header.ClientRectangle);
                }
            };

            Label lblHeaderTitle = new Label
            {
                Text = "🗑️ RS-Base PC連携 アンインストール専用ツール",
                ForeColor = Color.White,
                BackColor = Color.Transparent,
                Font = new Font("Yu Gothic UI", 12.8f, FontStyle.Bold),
                AutoSize = true,
                Location = new Point(18, 14)
            };
            Label lblHeaderSub = new Label
            {
                Text = "常駐プログラムの停止・自動起動解除・動作不良時の診断ログ送信（RS-Base本体には影響しません）",
                ForeColor = Color.FromArgb(254, 226, 226),
                BackColor = Color.Transparent,
                Font = new Font("Yu Gothic UI", 8.4f, FontStyle.Regular),
                AutoSize = true,
                Location = new Point(20, 44)
            };
            header.Controls.Add(lblHeaderTitle);
            header.Controls.Add(lblHeaderSub);
            this.Controls.Add(header);

            _lblStatusTitle = new Label
            {
                Text = "このPCの連携状態を確認しています...",
                Font = new Font("Yu Gothic UI", 10.2f, FontStyle.Bold),
                ForeColor = Color.FromArgb(15, 23, 42),
                AutoSize = true,
                Location = new Point(20, 88)
            };
            _lblBadge = new Label
            {
                Text = "確認中",
                Font = new Font("Yu Gothic UI", 8.5f, FontStyle.Bold),
                ForeColor = Color.FromArgb(153, 27, 27),
                BackColor = Color.FromArgb(254, 226, 226),
                AutoSize = true,
                Padding = new Padding(8, 3, 8, 3),
                Location = new Point(445, 87)
            };
            this.Controls.Add(_lblStatusTitle);
            this.Controls.Add(_lblBadge);

            _summaryPanel = new Panel
            {
                Location = new Point(20, 118),
                Size = new Size(540, 68),
                BackColor = Color.FromArgb(255, 251, 235),
                BorderStyle = BorderStyle.FixedSingle
            };
            _lblSummaryHead = new Label
            {
                Text = "下の「アンインストールを実行」を押すと、PC連携を安全に解除します",
                Font = new Font("Yu Gothic UI", 9.5f, FontStyle.Bold),
                ForeColor = Color.FromArgb(146, 64, 14),
                AutoSize = false,
                Location = new Point(10, 7),
                Size = new Size(518, 22)
            };
            _lblSummaryBody = new Label
            {
                Text = "※RS-Base本体（C:\\RSBASE\\RSB_public_html 等の既存ビューワ・検査データ）は一切削除しません。\r\n※うまく動作しなかった場合は、チェックを入れたまま解除すると原因調査用ログが自動送信されます。",
                Font = new Font("Yu Gothic UI", 8.3f, FontStyle.Regular),
                ForeColor = Color.FromArgb(120, 53, 15),
                AutoSize = false,
                Location = new Point(10, 29),
                Size = new Size(518, 34)
            };
            _summaryPanel.Controls.Add(_lblSummaryHead);
            _summaryPanel.Controls.Add(_lblSummaryBody);
            this.Controls.Add(_summaryPanel);

            _txtLog = new TextBox
            {
                Location = new Point(20, 194),
                Size = new Size(540, 156),
                Multiline = true,
                ReadOnly = true,
                ScrollBars = ScrollBars.Vertical,
                BackColor = Color.FromArgb(15, 23, 42),
                ForeColor = Color.FromArgb(226, 232, 240),
                Font = new Font("Consolas", 9f, FontStyle.Regular),
                BorderStyle = BorderStyle.FixedSingle
            };
            this.Controls.Add(_txtLog);

            _chkSendDiag = new CheckBox
            {
                Text = "解除前にこのPCの診断情報・動作ログをRS-Baseサーバーへ自動送信する（推奨）",
                Checked = !_opts.NoReport,
                AutoSize = true,
                Font = new Font("Yu Gothic UI", 8.8f, FontStyle.Bold),
                ForeColor = Color.FromArgb(30, 41, 59),
                Location = new Point(20, 360)
            };
            _chkRemoveLogs = new CheckBox
            {
                Text = "PC内のローカル診断ログファイル (logs\\client.log) も一緒に削除する",
                Checked = _opts.RemoveLogs,
                AutoSize = true,
                Font = new Font("Yu Gothic UI", 8.6f, FontStyle.Regular),
                ForeColor = Color.FromArgb(71, 85, 105),
                Location = new Point(20, 385)
            };
            this.Controls.Add(_chkSendDiag);
            this.Controls.Add(_chkRemoveLogs);

            _btnOpenLog = new Button
            {
                Text = "📂 ローカルログ表示",
                Location = new Point(20, 420),
                Size = new Size(138, 38),
                FlatStyle = FlatStyle.System
            };
            _btnOpenLog.Click += (s, e) => OpenOrSaveLocalLog();

            _btnDiagOnly = new Button
            {
                Text = "📡 ログ送信のみ",
                Location = new Point(164, 420),
                Size = new Size(124, 38),
                FlatStyle = FlatStyle.System
            };
            _btnDiagOnly.Click += async (s, e) => await RunDiagOnlyAsync();

            _btnUninstall = new Button
            {
                Text = "🗑️ アンインストールを実行",
                Location = new Point(296, 420),
                Size = new Size(176, 38),
                BackColor = Color.FromArgb(185, 28, 28),
                ForeColor = Color.White,
                FlatStyle = FlatStyle.Flat,
                Font = new Font("Yu Gothic UI", 9.3f, FontStyle.Bold)
            };
            _btnUninstall.FlatAppearance.BorderSize = 0;
            _btnUninstall.Click += async (s, e) => await RunUninstallUiAsync();

            _btnClose = new Button
            {
                Text = "閉じる",
                Location = new Point(478, 420),
                Size = new Size(82, 38),
                FlatStyle = FlatStyle.System
            };
            _btnClose.Click += (s, e) => this.Close();

            this.Controls.Add(_btnOpenLog);
            this.Controls.Add(_btnDiagOnly);
            this.Controls.Add(_btnUninstall);
            this.Controls.Add(_btnClose);

            this.Shown += (s, e) => RefreshPreScan();
        }

        private void AppendLog(string msg)
        {
            if (this.InvokeRequired)
            {
                this.BeginInvoke(new Action<string>(AppendLog), msg);
                return;
            }
            _txtLog.AppendText("[" + DateTime.Now.ToString("HH:mm:ss") + "] " + msg + Environment.NewLine);
        }

        private void RefreshPreScan()
        {
            _currentDiag = UninstallEngine.ScanEnvironment(_opts);
            _txtLog.Clear();
            AppendLog("PC名: " + _currentDiag.Hostname + " / ユーザー: " + _currentDiag.Username + " / IP: " + (_currentDiag.ClientIps == "" ? "(未検出)" : _currentDiag.ClientIps));
            AppendLog("OS: " + _currentDiag.OsInfo);
            AppendLog("常駐 watcher.vbs プロセス: " + (_currentDiag.RunningWatcherCount > 0 ? (_currentDiag.RunningWatcherCount + " 件稼働中") : "なし（停止中）"));
            AppendLog("スタートアップ自動起動: " + (_currentDiag.StartupItems.Count > 0 ? "登録あり (" + _currentDiag.StartupItems.Count + "件)" : "なし"));
            AppendLog("配置済み連携ファイル: " + (_currentDiag.InstalledFiles.Count > 0 ? (_currentDiag.InstalledFiles.Count + "件 (" + _currentDiag.ActiveInstallDir + ")") : "なし"));
            AppendLog("環境診断: WSH=" + (_currentDiag.WshOk ? "OK" : "NG(制限中)") +
                " / SMB共有=" + (_currentDiag.SmbOk ? "OK" : "未接続") +
                " / RSViewer=" + (_currentDiag.RsViewOk ? "有" : "無") +
                " / 心電図FEV=" + (_currentDiag.EcgOk ? "有" : "無"));

            bool hasAny = _currentDiag.RunningWatcherCount > 0 || _currentDiag.StartupItems.Count > 0 || _currentDiag.InstalledFiles.Count > 0;
            if (hasAny)
            {
                _lblStatusTitle.Text = "PC連携ツールが検出されました（解除可能）";
                _lblBadge.Text = "導入済み";
                _lblBadge.ForeColor = Color.FromArgb(153, 27, 27);
                _lblBadge.BackColor = Color.FromArgb(254, 226, 226);
            }
            else
            {
                _lblStatusTitle.Text = "現在このPCに有効なPC連携ファイルは登録されていません";
                _lblBadge.Text = "未導入 / 解除済み";
                _lblBadge.ForeColor = Color.FromArgb(22, 101, 52);
                _lblBadge.BackColor = Color.FromArgb(220, 252, 231);
            }
        }

        private void OpenOrSaveLocalLog()
        {
            if (_currentDiag != null && !string.IsNullOrEmpty(_currentDiag.LocalLogPath) && File.Exists(_currentDiag.LocalLogPath))
            {
                try
                {
                    Process.Start("notepad.exe", "\"" + _currentDiag.LocalLogPath + "\"");
                    return;
                }
                catch { }
            }

            // If no local client.log exists yet, export current diagnostic snapshot to Desktop and open it
            try
            {
                string desk = Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory);
                string outFp = Path.Combine(desk, "RSBase_ClientDiag_" + DateTime.Now.ToString("yyyyMMdd_HHmmss") + ".txt");
                File.WriteAllText(outFp, _txtLog.Text, Encoding.UTF8);
                AppendLog("現在の診断情報をデスクトップへ保存しました: " + outFp);
                Process.Start("notepad.exe", "\"" + outFp + "\"");
            }
            catch (Exception ex)
            {
                MessageBox.Show(this, "ログを開けませんでした: " + ex.Message, "確認", MessageBoxButtons.OK, MessageBoxIcon.Information);
            }
        }

        private async Task RunDiagOnlyAsync()
        {
            _btnDiagOnly.Enabled = false;
            AppendLog("診断ログをサーバーへ送信しています...");
            EnvDiagnostic snap = await Task.Run(() => UninstallEngine.ScanEnvironment(_opts));
            bool ok = await Task.Run(() => UninstallEngine.SendDiagnosticReport(
                _opts,
                snap,
                "INFO",
                "DIAG_SNAPSHOT",
                "アンインストールツールから手動診断ログが送信されました",
                "",
                AppendLog));

            if (ok)
            {
                MessageBox.Show(this, "RS-Baseサーバーへ現在の環境診断・動作ログを送信しました。\nWeb画面の「クライアント連携ログ」から確認できます。", "送信完了", MessageBoxButtons.OK, MessageBoxIcon.Information);
            }
            else
            {
                MessageBox.Show(this, "サーバーへ接続できなかったため送信できませんでした。\n「ローカルログ表示」ボタンからPC上で直接ログを確認できます。", "通信エラー", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            }
            _btnDiagOnly.Enabled = true;
        }

        private async Task RunUninstallUiAsync()
        {
            DialogResult ans = MessageBox.Show(
                this,
                "このPCの RS-Base PC連携ツール（watcher.vbs）を停止し、\n" +
                "PC起動時の自動起動と連携用スクリプトファイルをすべて削除します。\n\n" +
                "※RS-Base本体（C:\\RSBASE\\RSB_public_html 等）には一切影響しません。\n" +
                "アンインストールを実行しますか？",
                "アンインストールの確認",
                MessageBoxButtons.YesNo,
                MessageBoxIcon.Warning);
            if (ans != DialogResult.Yes) return;

            _btnUninstall.Enabled = false;
            _btnDiagOnly.Enabled = false;
            bool sendDiag = _chkSendDiag.Checked;
            bool rmLogs = _chkRemoveLogs.Checked;

            _lblStatusTitle.Text = "アンインストールを実行しています...";
            _lblBadge.Text = "処理中";
            AppendLog("--- アンインストール処理を開始します ---");

            await Task.Run(() => UninstallEngine.ExecuteUninstall(_opts, sendDiag, rmLogs, AppendLog));

            _lblStatusTitle.Text = "アンインストールが完了しました";
            _lblBadge.Text = "解除完了";
            _lblBadge.ForeColor = Color.FromArgb(22, 101, 52);
            _lblBadge.BackColor = Color.FromArgb(220, 252, 231);

            _summaryPanel.BackColor = Color.FromArgb(240, 253, 244);
            _lblSummaryHead.ForeColor = Color.FromArgb(20, 83, 45);
            _lblSummaryBody.ForeColor = Color.FromArgb(22, 101, 52);
            _lblSummaryHead.Text = "✅ PC連携ツールの停止・自動起動解除・ファイル削除が完了しました";
            _lblSummaryBody.Text =
                "今後はWeb画面の「内蔵DICOMビューワ」や「ZIPで一括ダウンロード」をそのままご利用いただけます。\r\n" +
                "再度PC連携を使いたくなった場合は、いつでも RSBaseSetup.exe から再設定できます。";

            _btnUninstall.Text = "アンインストール完了";
            _btnClose.Text = "完了";
            _btnClose.BackColor = Color.FromArgb(22, 163, 74);
            _btnClose.ForeColor = Color.White;
            _btnClose.FlatStyle = FlatStyle.Flat;
            _btnClose.Focus();
        }
    }

    internal static class Program
    {
        [STAThread]
        private static int Main(string[] args)
        {
            NativeMethods.SetProcessDPIAware();
            ServicePointManager.Expect100Continue = false;

            UninstallOptions opts = new UninstallOptions();
            foreach (string a in args ?? new string[0])
            {
                if (a.Equals("/silent", StringComparison.OrdinalIgnoreCase) || a.Equals("--silent", StringComparison.OrdinalIgnoreCase))
                    opts.Silent = true;
                else if (a.Equals("--diag-only", StringComparison.OrdinalIgnoreCase))
                    opts.DiagOnly = true;
                else if (a.Equals("--remove-logs", StringComparison.OrdinalIgnoreCase))
                    opts.RemoveLogs = true;
                else if (a.Equals("--no-report", StringComparison.OrdinalIgnoreCase))
                    opts.NoReport = true;
                else if (a.StartsWith("--server=", StringComparison.OrdinalIgnoreCase))
                    opts.CustomServerUrl = a.Substring("--server=".Length).Trim();
                else if (a.StartsWith("--sandbox=", StringComparison.OrdinalIgnoreCase))
                    opts.SandboxDir = a.Substring("--sandbox=".Length).Trim();
            }

            if (opts.DiagOnly)
            {
                EnvDiagnostic d = UninstallEngine.ScanEnvironment(opts);
                bool ok = UninstallEngine.SendDiagnosticReport(opts, d, "INFO", "DIAG_SNAPSHOT", "CLI診断スナップショット送信", "", Console.WriteLine);
                return ok ? 0 : 1;
            }

            if (opts.Silent)
            {
                return UninstallEngine.ExecuteUninstall(opts, !opts.NoReport, opts.RemoveLogs, Console.WriteLine);
            }

            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            using (UninstallForm frm = new UninstallForm(opts))
            {
                Application.Run(frm);
                return frm.ExitCodeResult;
            }
        }
    }
}

