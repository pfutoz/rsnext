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

[assembly: AssemblyTitle("RS-Base Client Setup")]
[assembly: AssemblyDescription("医療法人小野会 RS-Base クライアント連携インストーラー (常駐監視・CD書込・自動更新・ログ診断)")]
[assembly: AssemblyCompany("医療法人小野会 おの肛門科胃腸科内視鏡クリニック")]
[assembly: AssemblyProduct("RS-Base Client Integration Installer")]
[assembly: AssemblyVersion("2.2.2.0")]
[assembly: AssemblyFileVersion("2.2.2.0")]

namespace RSBaseInstaller
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

    internal sealed class InstallOptions
    {
        public string CustomServerUrl = "";
        public string SandboxDir = "";
        public bool Silent = false;
        public bool NoLaunch = false;
        public bool NoReport = false;
    }

    internal sealed class InstallResult
    {
        public bool Ok;
        public bool WasUpgrade;
        public bool WshEnabled = true;
        public string InstalledDir = "";
        public string Version = "";
        public string Message = "";
    }

    internal sealed class SetupForm : Form
    {
        private readonly InstallOptions _opts;
        private Label _lblStatusTitle;
        private Label _lblBadgeMode;
        private ProgressBar _progressBar;
        private TextBox _txtLog;
        private Panel _summaryPanel;
        private Label _lblSummaryHeadline;
        private Label _lblSummaryDetail;
        private Button _btnClose;
        private Button _btnUninstall;
        private Icon _appIcon;

        public int ExitCodeResult { get; private set; }

        public SetupForm(InstallOptions opts)
        {
            _opts = opts;
            ExitCodeResult = 0;
            InitializeUi();
        }

        private Icon CreateMedicalDiscIcon()
        {
            using (Bitmap bmp = new Bitmap(64, 64))
            using (Graphics g = Graphics.FromImage(bmp))
            {
                g.SmoothingMode = SmoothingMode.AntiAlias;
                using (LinearGradientBrush bg = new LinearGradientBrush(
                    new Rectangle(0, 0, 64, 64),
                    Color.FromArgb(6, 78, 59),
                    Color.FromArgb(22, 163, 74),
                    45f))
                {
                    g.FillEllipse(bg, 3, 3, 58, 58);
                }
                using (Pen ring = new Pen(Color.FromArgb(187, 247, 208), 2.5f))
                {
                    g.DrawEllipse(ring, 4, 4, 56, 56);
                }
                using (Brush white = new SolidBrush(Color.White))
                {
                    g.FillRectangle(white, 27, 16, 10, 32);
                    g.FillRectangle(white, 16, 27, 32, 10);
                }
                return Icon.FromHandle(bmp.GetHicon());
            }
        }

        private void InitializeUi()
        {
            Font baseFont = new Font("Yu Gothic UI", 9.5f, FontStyle.Regular);
            this.Font = baseFont;
            this.AutoScaleMode = AutoScaleMode.Dpi;
            this.Text = "RS-Base PC連携ツール セットアップ (v2.2.2)";
            this.ClientSize = new Size(560, 420);
            this.FormBorderStyle = FormBorderStyle.FixedDialog;
            this.MaximizeBox = false;
            this.StartPosition = FormStartPosition.CenterScreen;
            this.BackColor = Color.FromArgb(248, 250, 252);

            try
            {
                _appIcon = CreateMedicalDiscIcon();
                this.Icon = _appIcon;
            }
            catch { }

            // Header Panel (v2.2 Green Gradient)
            Panel header = new Panel
            {
                Dock = DockStyle.Top,
                Height = 76,
                BackColor = Color.FromArgb(6, 78, 59)
            };
            header.Paint += (s, e) =>
            {
                using (LinearGradientBrush lg = new LinearGradientBrush(
                    header.ClientRectangle,
                    Color.FromArgb(6, 78, 59),
                    Color.FromArgb(22, 163, 74),
                    0f))
                {
                    e.Graphics.FillRectangle(lg, header.ClientRectangle);
                }
            };

            Label lblHeaderTitle = new Label
            {
                Text = "🏥 RS-Base PC連携ツール セットアップ",
                ForeColor = Color.White,
                BackColor = Color.Transparent,
                Font = new Font("Yu Gothic UI", 13f, FontStyle.Bold),
                AutoSize = true,
                Location = new Point(18, 14)
            };
            Label lblHeaderSub = new Label
            {
                Text = "医療法人小野会 おの肛門科胃腸科内視鏡クリニック ／ ビューワ起動・CD書込・自動診断ログ",
                ForeColor = Color.FromArgb(209, 250, 229),
                BackColor = Color.Transparent,
                Font = new Font("Yu Gothic UI", 8.5f, FontStyle.Regular),
                AutoSize = true,
                Location = new Point(20, 44)
            };
            header.Controls.Add(lblHeaderTitle);
            header.Controls.Add(lblHeaderSub);
            this.Controls.Add(header);

            // Status Row
            _lblStatusTitle = new Label
            {
                Text = "セットアップを準備しています...",
                Font = new Font("Yu Gothic UI", 10.5f, FontStyle.Bold),
                ForeColor = Color.FromArgb(15, 23, 42),
                AutoSize = true,
                Location = new Point(20, 90)
            };
            _lblBadgeMode = new Label
            {
                Text = "環境確認中",
                Font = new Font("Yu Gothic UI", 8.5f, FontStyle.Bold),
                ForeColor = Color.FromArgb(22, 101, 52),
                BackColor = Color.FromArgb(220, 252, 231),
                AutoSize = true,
                Padding = new Padding(8, 3, 8, 3),
                Location = new Point(430, 89)
            };
            this.Controls.Add(_lblStatusTitle);
            this.Controls.Add(_lblBadgeMode);

            // ProgressBar
            _progressBar = new ProgressBar
            {
                Location = new Point(20, 120),
                Size = new Size(520, 18),
                Minimum = 0,
                Maximum = 100,
                Value = 5
            };
            this.Controls.Add(_progressBar);

            // Summary Card
            _summaryPanel = new Panel
            {
                Location = new Point(20, 148),
                Size = new Size(520, 74),
                BackColor = Color.FromArgb(240, 253, 244),
                BorderStyle = BorderStyle.FixedSingle,
                Visible = false
            };
            _lblSummaryHeadline = new Label
            {
                Text = "",
                Font = new Font("Yu Gothic UI", 9.8f, FontStyle.Bold),
                ForeColor = Color.FromArgb(20, 83, 45),
                AutoSize = false,
                Location = new Point(12, 8),
                Size = new Size(494, 24)
            };
            _lblSummaryDetail = new Label
            {
                Text = "",
                Font = new Font("Yu Gothic UI", 8.5f, FontStyle.Regular),
                ForeColor = Color.FromArgb(22, 101, 52),
                AutoSize = false,
                Location = new Point(12, 33),
                Size = new Size(494, 36)
            };
            _summaryPanel.Controls.Add(_lblSummaryHeadline);
            _summaryPanel.Controls.Add(_lblSummaryDetail);
            this.Controls.Add(_summaryPanel);

            // Log TextBox
            _txtLog = new TextBox
            {
                Location = new Point(20, 148),
                Size = new Size(520, 206),
                Multiline = true,
                ReadOnly = true,
                ScrollBars = ScrollBars.Vertical,
                BackColor = Color.FromArgb(15, 23, 42),
                ForeColor = Color.FromArgb(226, 232, 240),
                Font = new Font("Consolas", 9f, FontStyle.Regular),
                BorderStyle = BorderStyle.FixedSingle
            };
            this.Controls.Add(_txtLog);

            // Bottom Buttons
            _btnUninstall = new Button
            {
                Text = "PC連携を解除",
                Location = new Point(20, 368),
                Size = new Size(145, 36),
                FlatStyle = FlatStyle.System,
                Enabled = false
            };
            _btnUninstall.Click += async (s, e) => await RunUninstallAsync();

            _btnClose = new Button
            {
                Text = "処理中...",
                Location = new Point(380, 368),
                Size = new Size(160, 36),
                BackColor = Color.FromArgb(21, 128, 61),
                ForeColor = Color.White,
                FlatStyle = FlatStyle.Flat,
                Font = new Font("Yu Gothic UI", 9.5f, FontStyle.Bold),
                Enabled = false
            };
            _btnClose.FlatAppearance.BorderSize = 0;
            _btnClose.Click += (s, e) => this.Close();

            this.Controls.Add(_btnUninstall);
            this.Controls.Add(_btnClose);
            this.AcceptButton = _btnClose;

            this.Shown += async (s, e) => await RunInstallAsync();
        }

        private void AppendLog(string msg)
        {
            if (this.InvokeRequired)
            {
                this.BeginInvoke(new Action<string>(AppendLog), msg);
                return;
            }
            string line = "[" + DateTime.Now.ToString("HH:mm:ss") + "] " + msg + Environment.NewLine;
            _txtLog.AppendText(line);
        }

        private void SetProgressUi(int pct, string title, string badge = null)
        {
            if (this.InvokeRequired)
            {
                this.BeginInvoke(new Action<int, string, string>(SetProgressUi), pct, title, badge);
                return;
            }
            _progressBar.Value = Math.Max(0, Math.Min(100, pct));
            _lblStatusTitle.Text = title;
            if (!string.IsNullOrEmpty(badge))
            {
                _lblBadgeMode.Text = badge;
            }
        }

        public static bool IsWindows10Or11()
        {
            try
            {
                using (RegistryKey rk = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\Windows NT\CurrentVersion"))
                {
                    if (rk != null)
                    {
                        object majorObj = rk.GetValue("CurrentMajorVersionNumber");
                        if (majorObj != null && Convert.ToInt32(majorObj) >= 10) return true;
                        string prodName = Convert.ToString(rk.GetValue("ProductName") ?? "");
                        if (prodName.Contains("Windows 10") || prodName.Contains("Windows 11")) return true;
                    }
                }
            }
            catch { }
            return Environment.OSVersion.Version.Major >= 10;
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

        public static void AppendLocalClientLog(string targetDir, string level, string ev, string msg)
        {
            try
            {
                string baseDir = !string.IsNullOrEmpty(targetDir) && Directory.Exists(targetDir)
                    ? targetDir
                    : Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "RSBASE");
                string logDir = Path.Combine(baseDir, "logs");
                Directory.CreateDirectory(logDir);
                string logFp = Path.Combine(logDir, "client.log");
                if (File.Exists(logFp) && new FileInfo(logFp).Length > 524288)
                {
                    string oldFp = logFp + ".old";
                    if (File.Exists(oldFp)) File.Delete(oldFp);
                    File.Move(logFp, oldFp);
                }
                string line = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + " [" + level + "] [setup] [" + ev + "] " + msg + Environment.NewLine;
                File.AppendAllText(logFp, line, Encoding.GetEncoding(932));
            }
            catch { }
        }

        public static void SendServerReport(
            InstallOptions opts,
            string level,
            string eventName,
            string message,
            string detail,
            string installedDir,
            string version,
            bool wshOk)
        {
            if (opts != null && opts.NoReport) return;

            List<string> candidates = new List<string>();
            if (opts != null && !string.IsNullOrWhiteSpace(opts.CustomServerUrl))
            {
                candidates.Add(opts.CustomServerUrl.TrimEnd('/'));
            }
            candidates.Add("http://192.168.1.16/rsbase");
            candidates.Add("http://127.0.0.1/rsbase");

            bool smbOk = Directory.Exists(@"\\192.168.1.16\htdocs\rsbase\tmp") || Directory.Exists(@"C:\Apache24\htdocs\rsbase\tmp");
            bool rsViewOk = File.Exists(@"C:\RSBASE\RSB_public_html\RSViewer\rsview.exe");
            bool ecgOk = File.Exists(@"C:\RSBASE\RSB_public_html\denshi\FEV-05.exe");
            string deskTool = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory), @"DicomTool\dicom_convert.py");
            bool jpegOk = File.Exists(deskTool) || File.Exists(@"C:\Users\Owner\Desktop\DicomTool\dicom_convert.py");
            bool cdburnOk = !string.IsNullOrEmpty(installedDir) && File.Exists(Path.Combine(installedDir, "cd_burn.ps1"));

            var fields = new Dictionary<string, string>
            {
                { "action", "report" },
                { "source", "setup" },
                { "level", level },
                { "event", eventName },
                { "hostname", Environment.MachineName },
                { "username", Environment.UserName },
                { "client_ips", GetLocalIPv4Csv() },
                { "version", string.IsNullOrEmpty(version) ? "2.2.2" : version },
                { "install_dir", installedDir ?? "" },
                { "os_info", GetOsDescription() },
                { "client_ts", DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") },
                { "wsh_ok", wshOk ? "1" : "0" },
                { "smb_ok", smbOk ? "1" : "0" },
                { "rsview_ok", rsViewOk ? "1" : "0" },
                { "ecg_ok", ecgOk ? "1" : "0" },
                { "jpeg_tool_ok", jpegOk ? "1" : "0" },
                { "cdburn_ok", cdburnOk ? "1" : "0" },
                { "message", message ?? "" },
                { "detail", detail ?? "" }
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
                    req.Timeout = 3000;
                    req.ReadWriteTimeout = 3000;
                    req.ContentLength = payloadBytes.Length;
                    using (Stream st = req.GetRequestStream())
                    {
                        st.Write(payloadBytes, 0, payloadBytes.Length);
                    }
                    using (HttpWebResponse resp = (HttpWebResponse)req.GetResponse())
                    {
                        if (resp.StatusCode == HttpStatusCode.OK) return;
                    }
                }
                catch { }
            }
        }

        public static int StopRunningWatcherProcesses(Action<string> logger)
        {
            int stopped = 0;
            try
            {
                using (var searcher = new ManagementObjectSearcher(
                    "SELECT ProcessId, Name, CommandLine FROM Win32_Process WHERE Name='wscript.exe' OR Name='cscript.exe'"))
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
                                    if (logger != null) logger("稼働中の watcher.vbs プロセス (PID: " + pid + ") を安全に停止しました。");
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

        private static byte[] TryHttpGetBytes(string url, int timeoutMs = 4500)
        {
            HttpWebRequest req = (HttpWebRequest)WebRequest.Create(url);
            req.Method = "GET";
            req.Timeout = timeoutMs;
            req.ReadWriteTimeout = timeoutMs;
            req.CachePolicy = new System.Net.Cache.RequestCachePolicy(System.Net.Cache.RequestCacheLevel.NoCacheNoStore);
            using (HttpWebResponse resp = (HttpWebResponse)req.GetResponse())
            {
                if (resp.StatusCode != HttpStatusCode.OK) return null;
                using (Stream st = resp.GetResponseStream())
                using (MemoryStream ms = new MemoryStream())
                {
                    st.CopyTo(ms);
                    return ms.ToArray();
                }
            }
        }

        private static void CreateStartupShortcut(string shortcutPath, string targetVbsPath, string workDir)
        {
            Type shellType = Type.GetTypeFromProgID("WScript.Shell");
            object shell = Activator.CreateInstance(shellType);
            object shortcut = shellType.InvokeMember("CreateShortcut", BindingFlags.InvokeMethod, null, shell, new object[] { shortcutPath });
            Type scType = shortcut.GetType();
            scType.InvokeMember("TargetPath", BindingFlags.SetProperty, null, shortcut, new object[] { "wscript.exe" });
            scType.InvokeMember("Arguments", BindingFlags.SetProperty, null, shortcut, new object[] { "\"" + targetVbsPath + "\"" });
            scType.InvokeMember("WorkingDirectory", BindingFlags.SetProperty, null, shortcut, new object[] { workDir });
            scType.InvokeMember("Description", BindingFlags.SetProperty, null, shortcut, new object[] { "RS-Base Viewer & CD Burner Watcher (Auto-Update)" });
            scType.InvokeMember("Save", BindingFlags.InvokeMethod, null, shortcut, null);
        }

        public static InstallResult ExecuteCoreInstall(
            InstallOptions opts, Action<string> log, Action<int, string, string> progress)
        {
            NativeMethods.UnblockFile(Application.ExecutablePath);
            StringBuilder trace = new StringBuilder();
            Action<string> stepLog = (m) =>
            {
                trace.AppendLine(m);
                if (log != null) log(m);
            };

            bool wshOk = CheckWshEnabled();
            if (!wshOk)
            {
                stepLog("警告: このPCでは Windows Script Host (VBScript実行) がレジストリ設定により無効化されています。");
            }

            if (!IsWindows10Or11())
            {
                string osErr = "このインストーラーは Windows 10 / 11 専用です。その他のOSではブラウザ内蔵ビューワまたはZIPダウンロードをご利用ください。";
                AppendLocalClientLog("", "ERROR", "INSTALL_FAIL", osErr);
                SendServerReport(opts, "ERROR", "INSTALL_FAIL", osErr, trace.ToString(), "", "2.2.2", wshOk);
                return new InstallResult
                {
                    Ok = false,
                    WasUpgrade = false,
                    WshEnabled = wshOk,
                    Message = osErr
                };
            }

            bool isSandbox = !string.IsNullOrWhiteSpace(opts.SandboxDir);
            string primaryDir = isSandbox ? Path.Combine(opts.SandboxDir, "RSBASE") : @"C:\RSBASE";
            string fallbackDir = isSandbox
                ? Path.Combine(opts.SandboxDir, "LocalAppData_RSBASE")
                : Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "RSBASE");
            string startupDir = isSandbox
                ? Path.Combine(opts.SandboxDir, "Startup")
                : Environment.GetFolderPath(Environment.SpecialFolder.Startup);

            Directory.CreateDirectory(startupDir);

            // Step 1: Detect & follow up existing watcher.vbs
            if (progress != null) progress(15, "既存の現在の設定を確認しています...", "環境チェック中");
            bool hadExistingInstall =
                File.Exists(Path.Combine(primaryDir, "watcher.vbs")) ||
                File.Exists(Path.Combine(fallbackDir, "watcher.vbs")) ||
                File.Exists(Path.Combine(startupDir, "watcher.vbs")) ||
                File.Exists(Path.Combine(startupDir, "RSBase_Watcher.lnk")) ||
                File.Exists(Path.Combine(startupDir, "RS-Base_Watcher.lnk"));

            int stoppedCount = 0;
            if (!isSandbox)
            {
                stoppedCount = StopRunningWatcherProcesses(stepLog);
            }
            if (stoppedCount > 0) hadExistingInstall = true;

            string modeBadge = hadExistingInstall ? "既存環境からの更新" : "新規セットアップ";
            if (progress != null) progress(30, hadExistingInstall ? "既存の旧 watcher.vbs を確認・最新パッケージを取得中..." : "サーバーから最新パッケージを取得中...", modeBadge);
            if (hadExistingInstall)
            {
                stepLog("既存の watcher.vbs 導入環境を検出しました。重複設定の整理と安全入れ替えを実行します。");
            }
            else
            {
                stepLog("新規クライアント環境としてセットアップを開始します。");
            }

            // Step 2: Fetch latest watcher.vbs & cd_burn.ps1
            List<string> httpCandidates = new List<string>();
            if (!string.IsNullOrWhiteSpace(opts.CustomServerUrl))
            {
                httpCandidates.Add(opts.CustomServerUrl.TrimEnd('/'));
            }
            httpCandidates.Add("http://192.168.1.16/rsbase");
            httpCandidates.Add("http://127.0.0.1/rsbase");

            byte[] vbsBytes = null;
            byte[] ps1Bytes = null;
            string resolvedVer = "2.2.2";
            string usedSource = "";
            string bust = "?t=" + DateTime.UtcNow.Ticks;

            foreach (string baseUrl in httpCandidates)
            {
                try
                {
                    byte[] verRaw = TryHttpGetBytes(baseUrl + "/watcher_version.txt" + bust, 3000);
                    if (verRaw == null || verRaw.Length == 0) continue;
                    string verTxt = Encoding.ASCII.GetString(verRaw).Trim();
                    if (verTxt.Length < 3 || verTxt.Length > 16 || !char.IsDigit(verTxt[0]) || verTxt.Contains("<")) continue;

                    byte[] candVbs = TryHttpGetBytes(baseUrl + "/watcher.vbs" + bust, 5000);
                    byte[] candPs1 = TryHttpGetBytes(baseUrl + "/cd_burn.ps1" + bust, 5000);
                    if (candVbs != null && candVbs.Length > 500 && candPs1 != null && candPs1.Length > 500)
                    {
                        vbsBytes = candVbs;
                        ps1Bytes = candPs1;
                        resolvedVer = verTxt;
                        usedSource = baseUrl;
                        stepLog("HTTPサーバー (" + baseUrl + ") から最新版 v" + resolvedVer + " を取得しました。");
                        break;
                    }
                }
                catch (Exception ex)
                {
                    stepLog("接続試行 (" + baseUrl + "): " + ex.Message);
                }
            }

            // Fallback to SMB or local folder if HTTP had no watcher_version.txt yet
            if (vbsBytes == null || ps1Bytes == null)
            {
                string[] dirFallbacks = new[] { @"C:\Apache24\htdocs\rsbase", @"\\192.168.1.16\htdocs\rsbase" };
                foreach (string d in dirFallbacks)
                {
                    try
                    {
                        string fv = Path.Combine(d, "watcher.vbs");
                        string fp = Path.Combine(d, "cd_burn.ps1");
                        if (File.Exists(fv) && File.Exists(fp))
                        {
                            vbsBytes = File.ReadAllBytes(fv);
                            ps1Bytes = File.ReadAllBytes(fp);
                            string fver = Path.Combine(d, "watcher_version.txt");
                            if (File.Exists(fver)) resolvedVer = File.ReadAllText(fver, Encoding.ASCII).Trim();
                            usedSource = d;
                            stepLog("共有フォルダ (" + d + ") からパッケージを取得しました。");
                            break;
                        }
                    }
                    catch { }
                }
            }

            if (vbsBytes == null || ps1Bytes == null)
            {
                string netErr = "RS-Baseサーバー (http://192.168.1.16/rsbase) から必要なファイルを取得できませんでした。院内ネットワーク接続をご確認ください。";
                AppendLocalClientLog("", "ERROR", "INSTALL_FAIL", netErr);
                SendServerReport(opts, "ERROR", "INSTALL_FAIL", netErr, trace.ToString(), "", resolvedVer, wshOk);
                return new InstallResult
                {
                    Ok = false,
                    WasUpgrade = hadExistingInstall,
                    WshEnabled = wshOk,
                    Message = netErr
                };
            }

            // Step 3: Write files to C:\RSBASE (or fallback to %LOCALAPPDATA%\RSBASE)
            if (progress != null) progress(65, "システムフォルダへ配置・旧版のバックアップ整理中...", modeBadge);
            string targetDir = primaryDir;
            try
            {
                Directory.CreateDirectory(targetDir);
                string testProbe = Path.Combine(targetDir, "_write_test.tmp");
                File.WriteAllText(testProbe, "ok");
                File.Delete(testProbe);
            }
            catch
            {
                targetDir = fallbackDir;
                Directory.CreateDirectory(targetDir);
                stepLog(@"C:\RSBASE への書込制限のため、" + targetDir + " に配置します。");
            }

            string targetVbs = Path.Combine(targetDir, "watcher.vbs");
            string targetPs1 = Path.Combine(targetDir, "cd_burn.ps1");

            // Preserve backup of legacy watcher.vbs if it wasn't v2.2+
            if (File.Exists(targetVbs))
            {
                try
                {
                    string oldTxt = File.ReadAllText(targetVbs, Encoding.GetEncoding(932));
                    if (!oldTxt.Contains("WATCHER_VERSION"))
                    {
                        string bakPath = Path.Combine(targetDir, "watcher.vbs.legacy_bak");
                        File.Copy(targetVbs, bakPath, true);
                        stepLog("旧バージョンの watcher.vbs を watcher.vbs.legacy_bak に控え保存しました。");
                    }
                }
                catch { }
            }

            File.WriteAllBytes(targetVbs, vbsBytes);
            File.WriteAllBytes(targetPs1, ps1Bytes);
            File.WriteAllText(Path.Combine(targetDir, "watcher_version.txt"), resolvedVer + "\n", Encoding.ASCII);

            NativeMethods.UnblockFile(targetVbs);
            NativeMethods.UnblockFile(targetPs1);
            stepLog("最新スクリプトを配置しました: " + targetDir);

            // Clean up duplicate fallback install if installed to primary C:\RSBASE
            if (string.Equals(targetDir, primaryDir, StringComparison.OrdinalIgnoreCase))
            {
                string dupVbs = Path.Combine(fallbackDir, "watcher.vbs");
                if (File.Exists(dupVbs))
                {
                    try
                    {
                        File.Delete(dupVbs);
                        stepLog("重複防止のため旧ユーザーフォルダ内の古い watcher.vbs を整理しました。");
                    }
                    catch { }
                }
            }

            // Step 4: Clean up legacy Startup duplicates & register single shortcut
            if (progress != null) progress(85, "スタートアップ登録の整理と常駐起動を実行中...", modeBadge);
            string[] legacyStartupItems = new[]
            {
                Path.Combine(startupDir, "watcher.vbs"),
                Path.Combine(startupDir, "RS-Base_Watcher.lnk")
            };
            foreach (string legacyItem in legacyStartupItems)
            {
                if (File.Exists(legacyItem))
                {
                    try
                    {
                        File.Delete(legacyItem);
                        stepLog("旧スタートアップ重複項目を整理しました: " + Path.GetFileName(legacyItem));
                    }
                    catch { }
                }
            }

            string shortcutPath = Path.Combine(startupDir, "RSBase_Watcher.lnk");
            try
            {
                CreateStartupShortcut(shortcutPath, targetVbs, targetDir);
                stepLog("スタートアップ自動起動を登録しました: RSBase_Watcher.lnk");
            }
            catch (Exception scEx)
            {
                stepLog("警告: スタートアップ登録時にエラーが発生しました: " + scEx.Message);
            }

            // Launch new watcher.vbs
            if (!opts.NoLaunch && !isSandbox)
            {
                try
                {
                    ProcessStartInfo psi = new ProcessStartInfo
                    {
                        FileName = "wscript.exe",
                        Arguments = "\"" + targetVbs + "\"",
                        WorkingDirectory = targetDir,
                        UseShellExecute = false,
                        CreateNoWindow = true
                    };
                    Process.Start(psi);
                    stepLog("最新版 watcher.vbs (v" + resolvedVer + "・ログ診断有効) のバックグラウンド連携を開始しました。");
                }
                catch (Exception launchEx)
                {
                    stepLog("エラー: wscript.exe の起動に失敗しました: " + launchEx.Message);
                }
            }

            string level = wshOk ? "INFO" : "WARN";
            string sumMsg = (hadExistingInstall ? "PC連携ツールを更新しました" : "PC連携ツールを新規セットアップしました") +
                " (v" + resolvedVer + " / 配置先: " + targetDir + ")" +
                (wshOk ? "" : " ※WSH(VBScript)が無効化されているため管理者確認が必要です");
            AppendLocalClientLog(targetDir, level, "INSTALL_OK", sumMsg);
            SendServerReport(opts, level, "INSTALL_OK", sumMsg, trace.ToString(), targetDir, resolvedVer, wshOk);

            if (progress != null) progress(100, "セットアップが完了しました！", modeBadge);
            return new InstallResult
            {
                Ok = true,
                WasUpgrade = hadExistingInstall,
                WshEnabled = wshOk,
                InstalledDir = targetDir,
                Version = resolvedVer,
                Message = usedSource
            };
        }

        private async Task RunInstallAsync()
        {
            try
            {
                InstallResult res = await Task.Run(() => ExecuteCoreInstall(_opts, AppendLog, SetProgressUi));
                _btnUninstall.Enabled = true;

                if (res.Ok)
                {
                    _txtLog.Location = new Point(20, 230);
                    _txtLog.Size = new Size(520, 124);
                    _summaryPanel.Visible = true;

                    if (!res.WshEnabled)
                    {
                        _summaryPanel.BackColor = Color.FromArgb(255, 251, 235);
                        _lblSummaryHeadline.ForeColor = Color.FromArgb(146, 64, 14);
                        _lblSummaryDetail.ForeColor = Color.FromArgb(120, 53, 15);
                        _lblSummaryHeadline.Text = "⚠️ 配置完了（Windows設定でVBScriptが無効化されています）";
                        _lblSummaryDetail.Text = "セキュリティ設定で WSH が無効のため常駐スクリプトが動作しない可能性があります。\r\nサーバーの「クライアント連携ログ」へ診断情報を送信しました。";
                    }
                    else if (res.WasUpgrade)
                    {
                        _lblSummaryHeadline.Text = "✅ 既存の旧 watcher.vbs から最新版 (v" + res.Version + ") への更新が完了しました！";
                        _lblSummaryDetail.Text =
                            "配置先: " + res.InstalledDir + " ／ PC連携・自動ログ診断: 有効\r\n" +
                            "🔄 自動アップデート機能が有効です（解除したい場合は RSBaseUninstall.exe または左下ボタン）。";
                    }
                    else
                    {
                        _lblSummaryHeadline.Text = "✅ クライアント連携ツールの新規セットアップが完了しました！(v" + res.Version + ")";
                        _lblSummaryDetail.Text =
                            "配置先: " + res.InstalledDir + " ／ PC連携・自動ログ診断: 有効\r\n" +
                            "🔄 自動アップデート機能が有効です（解除したい場合は RSBaseUninstall.exe または左下ボタン）。";
                    }

                    _btnClose.Text = "完了して閉じる";
                    _btnClose.BackColor = Color.FromArgb(22, 163, 74);
                    _btnClose.Enabled = true;
                    _btnClose.Focus();
                }
                else
                {
                    ExitCodeResult = 1;
                    _summaryPanel.BackColor = Color.FromArgb(254, 242, 242);
                    _lblSummaryHeadline.ForeColor = Color.FromArgb(185, 28, 28);
                    _lblSummaryDetail.ForeColor = Color.FromArgb(153, 27, 27);
                    _lblSummaryHeadline.Text = "⚠️ セットアップを完了できませんでした";
                    _lblSummaryDetail.Text = res.Message;
                    _txtLog.Location = new Point(20, 230);
                    _txtLog.Size = new Size(520, 124);
                    _summaryPanel.Visible = true;
                    AppendLog("エラー: " + res.Message);

                    _btnClose.Text = "閉じる";
                    _btnClose.BackColor = Color.FromArgb(100, 116, 139);
                    _btnClose.Enabled = true;
                }
            }
            catch (Exception ex)
            {
                ExitCodeResult = 2;
                AppendLog("予期しないエラー: " + ex.Message);
                _btnUninstall.Enabled = true;
                _btnClose.Text = "閉じる";
                _btnClose.Enabled = true;
            }
        }

        private async Task RunUninstallAsync()
        {
            var ans = MessageBox.Show(
                this,
                "このPCの RS-Base PC連携ツール (watcher.vbs) を停止し、\nPC起動時の自動起動解除と連携スクリプトの削除を行いますか？\n（※RS-Base本体には一切影響しません）",
                "連携解除（アンインストール）の確認",
                MessageBoxButtons.YesNo,
                MessageBoxIcon.Question);
            if (ans != DialogResult.Yes) return;

            _btnUninstall.Enabled = false;
            await Task.Run(() =>
            {
                StopRunningWatcherProcesses(AppendLog);
                string startupDir = Environment.GetFolderPath(Environment.SpecialFolder.Startup);
                foreach (string name in new[] { "RSBase_Watcher.lnk", "RS-Base_Watcher.lnk", "watcher.vbs" })
                {
                    string p = Path.Combine(startupDir, name);
                    if (File.Exists(p))
                    {
                        try { File.Delete(p); AppendLog("スタートアップ登録を削除しました: " + name); } catch { }
                    }
                }

                string primaryDir = @"C:\RSBASE";
                string fallbackDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "RSBASE");
                string[] managedFiles = new[] { "watcher.vbs", "watcher.vbs.upd", "watcher.vbs.legacy_bak", "cd_burn.ps1", "cd_burn.ps1.upd", "watcher_version.txt" };
                foreach (string d in new[] { primaryDir, fallbackDir })
                {
                    if (!Directory.Exists(d)) continue;
                    foreach (string mf in managedFiles)
                    {
                        string fp = Path.Combine(d, mf);
                        if (File.Exists(fp))
                        {
                            try { File.Delete(fp); AppendLog("連携ファイルを削除しました: " + fp); } catch { }
                        }
                    }
                }

                AppendLocalClientLog(primaryDir, "INFO", "UNINSTALL_OK", "セットアップ画面からPC連携を解除しました");
                SendServerReport(_opts, "INFO", "UNINSTALL_OK", "セットアップ画面からPC連携を解除しました", "", primaryDir, "2.2.2", CheckWshEnabled());
            });

            _summaryPanel.BackColor = Color.FromArgb(255, 251, 235);
            _lblSummaryHeadline.ForeColor = Color.FromArgb(146, 64, 14);
            _lblSummaryDetail.ForeColor = Color.FromArgb(120, 53, 15);
            _lblSummaryHeadline.Text = "PC連携の停止・自動起動解除・スクリプト削除が完了しました";
            _lblSummaryDetail.Text = "再度有効にしたい場合は、もう一度 RSBaseSetup.exe を実行してください。";
            SetProgressUi(100, "連携を解除しました", "解除済み");
        }
    }

    internal static class Program
    {
        [STAThread]
        private static int Main(string[] args)
        {
            NativeMethods.SetProcessDPIAware();
            ServicePointManager.Expect100Continue = false;

            InstallOptions opts = new InstallOptions();
            foreach (string a in args ?? new string[0])
            {
                if (a.Equals("/silent", StringComparison.OrdinalIgnoreCase) || a.Equals("--silent", StringComparison.OrdinalIgnoreCase))
                    opts.Silent = true;
                else if (a.Equals("--no-launch", StringComparison.OrdinalIgnoreCase))
                    opts.NoLaunch = true;
                else if (a.Equals("--no-report", StringComparison.OrdinalIgnoreCase))
                    opts.NoReport = true;
                else if (a.StartsWith("--server=", StringComparison.OrdinalIgnoreCase))
                    opts.CustomServerUrl = a.Substring("--server=".Length).Trim();
                else if (a.StartsWith("--sandbox=", StringComparison.OrdinalIgnoreCase))
                    opts.SandboxDir = a.Substring("--sandbox=".Length).Trim();
            }

            if (opts.Silent)
            {
                InstallResult res = SetupForm.ExecuteCoreInstall(opts, Console.WriteLine, null);
                if (!res.Ok)
                {
                    Console.Error.WriteLine("ERROR: " + res.Message);
                    return 1;
                }
                Console.WriteLine("SUCCESS: upgraded=" + res.WasUpgrade + " dir=" + res.InstalledDir + " ver=" + res.Version);
                return 0;
            }

            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            using (SetupForm frm = new SetupForm(opts))
            {
                Application.Run(frm);
                return frm.ExitCodeResult;
            }
        }
    }
}
