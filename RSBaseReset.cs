using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Management;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Text;
using System.Threading;
using System.Windows.Forms;
using Microsoft.Win32;

[assembly: AssemblyTitle("RS-Base / RS-Next Emergency Reset & Relaunch Tool")]
[assembly: AssemblyDescription("RSViewerフリーズ解除・watcher.vbs常駐再起動・プロトコル直接復旧ツール")]
[assembly: AssemblyCompany("医療法人小野会")]
[assembly: AssemblyProduct("RS-Base / RS-Next Client Reset Helper")]
[assembly: AssemblyVersion("2.4.3.0")]
[assembly: AssemblyFileVersion("2.4.3.0")]

namespace RSBaseResetTool
{
    internal static class NativeWin
    {
        [DllImport("user32.dll")]
        public static extern bool SetProcessDPIAware();
    }

    internal static class Program
    {
        private static string ResolveClientRoot()
        {
            string exeDir = Path.GetDirectoryName(Application.ExecutablePath) ?? "";
            if (File.Exists(Path.Combine(exeDir, "watcher.vbs")))
            {
                return exeDir;
            }
            if (Directory.Exists(@"C:\RSBASE"))
            {
                return @"C:\RSBASE";
            }
            string localApp = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "RSBASE");
            Directory.CreateDirectory(localApp);
            return localApp;
        }

        private static void RegisterProtocol(string exePath)
        {
            try
            {
                if (string.IsNullOrEmpty(exePath) || !File.Exists(exePath)) return;
                using (RegistryKey baseKey = Registry.CurrentUser.CreateSubKey(@"Software\Classes\rsnext-reset"))
                {
                    if (baseKey == null) return;
                    baseKey.SetValue("", "URL:RS-Next / RS-Base Viewer Reset Protocol");
                    baseKey.SetValue("URL Protocol", "");
                    using (RegistryKey iconKey = baseKey.CreateSubKey("DefaultIcon"))
                    {
                        if (iconKey != null) iconKey.SetValue("", "\"" + exePath + "\",0");
                    }
                    using (RegistryKey cmdKey = baseKey.CreateSubKey(@"shell\open\command"))
                    {
                        if (cmdKey != null) cmdKey.SetValue("", "\"" + exePath + "\" \"%1\"");
                    }
                }
            }
            catch { }
        }

        private static Dictionary<string, string> ParseQuery(string query)
        {
            var dict = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            if (string.IsNullOrEmpty(query)) return dict;
            string q = query.TrimStart('?');
            foreach (string part in q.Split('&'))
            {
                if (string.IsNullOrEmpty(part)) continue;
                int eq = part.IndexOf('=');
                if (eq > 0)
                {
                    string k = Uri.UnescapeDataString(part.Substring(0, eq));
                    string v = Uri.UnescapeDataString(part.Substring(eq + 1));
                    dict[k] = v;
                }
                else
                {
                    dict[Uri.UnescapeDataString(part)] = "1";
                }
            }
            return dict;
        }

        private static int KillProcessesByRule()
        {
            int killed = 0;
            // 1. Kill hung/background RSView.exe & RSView2D.exe
            foreach (string procName in new[] { "RSView2D", "RSView" })
            {
                try
                {
                    foreach (Process p in Process.GetProcessesByName(procName))
                    {
                        try
                        {
                            p.Kill();
                            p.WaitForExit(1500);
                            killed++;
                        }
                        catch { }
                    }
                }
                catch { }
            }

            // 2. Kill hung watcher.vbs & stuck rsview_http_launch.ps1 (never touch RSBASE.vbs or unrelated powershell)
            try
            {
                using (var searcher = new ManagementObjectSearcher(
                    "SELECT ProcessId, Name, CommandLine FROM Win32_Process WHERE Name='wscript.exe' OR Name='cscript.exe' OR Name='powershell.exe'"))
                using (var results = searcher.Get())
                {
                    int selfPid = Process.GetCurrentProcess().Id;
                    foreach (ManagementObject mo in results)
                    {
                        int pid = Convert.ToInt32(mo["ProcessId"]);
                        if (pid == selfPid) continue;
                        string cmd = Convert.ToString(mo["CommandLine"] ?? "");
                        if (cmd.IndexOf("watcher.vbs", StringComparison.OrdinalIgnoreCase) >= 0 ||
                            cmd.IndexOf("rsview_http_launch.ps1", StringComparison.OrdinalIgnoreCase) >= 0)
                        {
                            try
                            {
                                using (Process p = Process.GetProcessById(pid))
                                {
                                    p.Kill();
                                    p.WaitForExit(1500);
                                    killed++;
                                }
                            }
                            catch { }
                        }
                    }
                }
            }
            catch { }

            return killed;
        }

        private static void HealZeroSmbConfig(string clientRoot)
        {
            try
            {
                string pubHtml = Path.Combine(clientRoot, "RSB_public_html");
                string viewerDir = Path.Combine(pubHtml, "RSViewer");
                string viewerExe = Path.Combine(viewerDir, "RSView.exe");
                if (!File.Exists(viewerExe)) return;

                string datPath = Path.Combine(viewerDir, "RSB_Server.dat");
                File.WriteAllText(datPath, pubHtml.TrimEnd('\\') + "\\\r\n", Encoding.GetEncoding(932));

                string licV = Path.Combine(viewerDir, "RSView_LIC.dat");
                string licP = Path.Combine(pubHtml, "RSView_LIC.dat");
                if (File.Exists(licV) && !File.Exists(licP)) File.Copy(licV, licP, true);
                else if (File.Exists(licP) && !File.Exists(licV)) File.Copy(licP, licV, true);
            }
            catch { }
        }

        private static bool RestartWatcher(string clientRoot)
        {
            try
            {
                string vbsPath = Path.Combine(clientRoot, "watcher.vbs");
                if (!File.Exists(vbsPath)) return false;

                ProcessStartInfo psi = new ProcessStartInfo
                {
                    FileName = "wscript.exe",
                    Arguments = "\"" + vbsPath + "\"",
                    WorkingDirectory = clientRoot,
                    UseShellExecute = true,
                    WindowStyle = ProcessWindowStyle.Normal
                };
                Process.Start(psi);
                return true;
            }
            catch
            {
                return false;
            }
        }

        private static void AppendLog(string clientRoot, string msg)
        {
            try
            {
                string logDir = Path.Combine(clientRoot, "logs");
                Directory.CreateDirectory(logDir);
                string logFp = Path.Combine(logDir, "client.log");
                string line = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + " [INFO] [reset] [EMERGENCY_RESET] " + msg + Environment.NewLine;
                File.AppendAllText(logFp, line, Encoding.GetEncoding(932));
            }
            catch { }
        }

        private static Form CreateStatusBanner(string title, string sub, Color bg)
        {
            Form frm = new Form
            {
                Text = "RSViewer 連携リセット",
                ClientSize = new Size(440, 86),
                FormBorderStyle = FormBorderStyle.FixedToolWindow,
                ControlBox = false,
                StartPosition = FormStartPosition.CenterScreen,
                TopMost = true,
                BackColor = bg
            };
            Label l1 = new Label
            {
                Text = title,
                ForeColor = Color.White,
                Font = new Font("Yu Gothic UI", 10.5f, FontStyle.Bold),
                Location = new Point(16, 14),
                Size = new Size(408, 26)
            };
            Label l2 = new Label
            {
                Text = sub,
                ForeColor = Color.FromArgb(226, 232, 240),
                Font = new Font("Yu Gothic UI", 8.8f, FontStyle.Regular),
                Location = new Point(16, 44),
                Size = new Size(408, 28)
            };
            frm.Controls.Add(l1);
            frm.Controls.Add(l2);
            return frm;
        }

        [STAThread]
        private static int Main(string[] args)
        {
            NativeWin.SetProcessDPIAware();
            string clientRoot = ResolveClientRoot();
            string selfExe = Application.ExecutablePath;
            string installedResetExe = Path.Combine(clientRoot, "RSBaseReset.exe");
            RegisterProtocol(File.Exists(installedResetExe) ? installedResetExe : selfExe);

            bool registerOnly = false;
            bool silent = false;
            string bundleUrl = "";
            string cacheKey = "";
            bool purgeKey = false;

            foreach (string rawArg in args ?? new string[0])
            {
                string a = (rawArg ?? "").Trim();
                if (a.Equals("--register-only", StringComparison.OrdinalIgnoreCase))
                {
                    registerOnly = true;
                }
                else if (a.Equals("--silent", StringComparison.OrdinalIgnoreCase) || a.Equals("/silent", StringComparison.OrdinalIgnoreCase))
                {
                    silent = true;
                }
                else if (a.StartsWith("rsnext-reset://", StringComparison.OrdinalIgnoreCase))
                {
                    try
                    {
                        Uri u = new Uri(a);
                        var q = ParseQuery(u.Query);
                        if (q.ContainsKey("url")) bundleUrl = q["url"];
                        if (q.ContainsKey("key")) cacheKey = q["key"];
                        if (q.ContainsKey("purge") && q["purge"] == "1") purgeKey = true;
                    }
                    catch { }
                }
            }

            if (registerOnly)
            {
                return 0;
            }

            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);

            Form banner = null;
            if (!silent)
            {
                banner = CreateStatusBanner(
                    "🔄 RSViewer・常駐連携をリセット中...",
                    "裏画面の残存プロセス停止と常駐ツールの再起動を実行しています",
                    Color.FromArgb(15, 23, 42));
                banner.Show();
                Application.DoEvents();
            }

            int killed = KillProcessesByRule();
            Thread.Sleep(250);
            HealZeroSmbConfig(clientRoot);

            if (purgeKey && !string.IsNullOrWhiteSpace(cacheKey))
            {
                try
                {
                    string safeKey = System.Text.RegularExpressions.Regex.Replace(cacheKey, @"[^A-Za-z0-9_\-]", "_");
                    string cacheRoot = Path.Combine(clientRoot, "dicom_cache");
                    string readyFp = Path.Combine(cacheRoot, safeKey + ".ready");
                    string dirFp = Path.Combine(cacheRoot, safeKey);
                    if (File.Exists(readyFp)) File.Delete(readyFp);
                    if (Directory.Exists(dirFp)) Directory.Delete(dirFp, true);
                }
                catch { }
            }

            bool watcherStarted = RestartWatcher(clientRoot);
            AppendLog(clientRoot, "強制リセット実行完了 (停止プロセス数=" + killed + ", watcher再起動=" + watcherStarted + (string.IsNullOrEmpty(bundleUrl) ? "" : ", 再起動URL指定あり") + ")");

            if (!string.IsNullOrWhiteSpace(bundleUrl))
            {
                string ps1 = Path.Combine(clientRoot, "rsview_http_launch.ps1");
                if (File.Exists(ps1))
                {
                    try
                    {
                        ProcessStartInfo psi = new ProcessStartInfo
                        {
                            FileName = "powershell.exe",
                            Arguments = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -STA -File \"" + ps1 + "\" -BundleUrl \"" + bundleUrl + "\" -CacheKey \"" + cacheKey + "\"",
                            WorkingDirectory = clientRoot,
                            UseShellExecute = false,
                            CreateNoWindow = true
                        };
                        Process.Start(psi);
                    }
                    catch { }
                }
            }

            if (banner != null)
            {
                banner.Close();
                banner.Dispose();

                string doneSub = !string.IsNullOrWhiteSpace(bundleUrl)
                    ? "常駐連携を再起動し、検査データを RSViewer で直接開いています..."
                    : "裏画面ロック解除と watcher.vbs の再起動が完了しました。もう一度検査をクリックしてください。";
                using (Form okFrm = CreateStatusBanner("✅ RSViewer・PC連携をリセットしました", doneSub, Color.FromArgb(6, 95, 70)))
                {
                    okFrm.Show();
                    Application.DoEvents();
                    Thread.Sleep(1500);
                    okFrm.Close();
                }
            }

            return 0;
        }
    }
}
