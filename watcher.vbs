' --- watcher.vbs v2.4.3 (Unified RS-Base & RS-Next Zero-SMB HTTP DICOM + Emergency Reset Protocol Auto-Register) ---
Option Explicit

Const WATCHER_VERSION = "2.4.3"

Dim objShell, objFSO, network, prodRoot, localRoot, prodHttpBase, rsnextHttpBase, localHttpBase
Dim normalExePath, ecgExePath
Dim desktopPath, toolDir, scriptPath, selfDirPath
Dim myIPs, loopCount, currentIp, hasIpCached
Dim candidateRoots, rootIdx, activeRoot, activeTmpDir, checkIPs, myBurnFile, myTriggerFile
Dim smbAnyAccessible, rsbaseSmbAccessible, lastSmbState, httpPollText, httpConnOk
Dim rsbaseHttpCooldown, rsnextHttpCooldown
Dim lastDispatchLine, lastDispatchTimer
Dim objFile, fileContent, payload, triggered
Dim isSelfTest, customServerArg, argIdx, argVal

Set objShell = CreateObject("WScript.Shell")
Set objFSO   = CreateObject("Scripting.FileSystemObject")
Set network  = CreateObject("WScript.Network")

' CLI Arguments (--self-test / --server=...)
isSelfTest = False
customServerArg = ""
If WScript.Arguments.Count > 0 Then
    For argIdx = 0 To WScript.Arguments.Count - 1
        argVal = Trim(WScript.Arguments(argIdx))
        If LCase(argVal) = "--self-test" Then
            isSelfTest = True
        ElseIf Left(LCase(argVal), 9) = "--server=" Then
            customServerArg = Mid(argVal, 10)
            If Right(customServerArg, 1) = "/" Then
                customServerArg = Left(customServerArg, Len(customServerArg) - 1)
            End If
        End If
    Next
End If

' Configuration Paths
prodRoot       = "\\192.168.1.16\htdocs\rsbase\"
localRoot      = "C:\Apache24\htdocs\rsbase\"
prodHttpBase   = "http://192.168.1.16/rsbase"
rsnextHttpBase = "http://192.168.1.163/rsnext"
localHttpBase  = "http://127.0.0.1/rsbase"
normalExePath  = "C:\RSBASE\RSB_public_html\RSViewer\rsview.exe"
ecgExePath     = "C:\RSBASE\RSB_public_html\denshi\FEV-05.exe"
selfDirPath    = objFSO.GetParentFolderName(WScript.ScriptFullName)
rsbaseHttpCooldown = 0
rsnextHttpCooldown = 0
lastDispatchLine  = ""
lastDispatchTimer = -100

' DicomTool Path (Desktop\DicomTool with fallback to C:\Users\Owner\Desktop\DicomTool)
desktopPath = objShell.SpecialFolders("Desktop")
toolDir = desktopPath & "\DicomTool\"
If Not objFSO.FolderExists(toolDir) Then
    toolDir = "C:\Users\Owner\Desktop\DicomTool\"
End If
scriptPath = toolDir & "dicom_convert.py"

' ------------------------------------------------------------------------------
' Formatting & Local Rotating Log Helpers (Max 512KB + .old)
' ------------------------------------------------------------------------------
Function Pad2(n)
    Pad2 = Right("0" & CStr(n), 2)
End Function

Function NowIsoStr()
    Dim dt
    dt = Now
    NowIsoStr = Year(dt) & "-" & Pad2(Month(dt)) & "-" & Pad2(Day(dt)) & " " & _
                Pad2(Hour(dt)) & ":" & Pad2(Minute(dt)) & ":" & Pad2(Second(dt))
End Function

Function ResolveLocalLogFile()
    Dim lDir, lFile
    On Error Resume Next
    If LCase(selfDirPath & "\") = LCase(localRoot) Or LCase(selfDirPath & "\") = LCase(prodRoot) Or LCase(selfDirPath & "\") = "c:\apache24\htdocs\rsnext\" Then
        ResolveLocalLogFile = objFSO.GetSpecialFolder(2) & "\rsbase_client.log"
        On Error Goto 0
        Exit Function
    End If

    lDir = selfDirPath & "\logs"
    If Not objFSO.FolderExists(lDir) Then
        objFSO.CreateFolder lDir
    End If
    If Err.Number = 0 And objFSO.FolderExists(lDir) Then
        lFile = lDir & "\client.log"
    Else
        Err.Clear
        lFile = objFSO.GetSpecialFolder(2) & "\rsbase_client.log"
    End If
    ResolveLocalLogFile = lFile
    On Error Goto 0
End Function

Sub WriteLocalLog(levelStr, eventStr, msgStr, detailStr)
    Dim logFp, oldFp, fObj, tsLine
    On Error Resume Next
    logFp = ResolveLocalLogFile()
    If Len(logFp) = 0 Then Exit Sub

    If objFSO.FileExists(logFp) Then
        If objFSO.GetFile(logFp).Size > 524288 Then
            oldFp = logFp & ".old"
            If objFSO.FileExists(oldFp) Then objFSO.DeleteFile oldFp, True
            objFSO.MoveFile logFp, oldFp
        End If
    End If

    tsLine = NowIsoStr() & " [" & levelStr & "] [watcher] [" & eventStr & "] " & msgStr
    If Len(detailStr) > 0 Then
        tsLine = tsLine & " | " & Replace(Replace(detailStr, vbCr, " "), vbLf, " ")
    End If

    Set fObj = objFSO.OpenTextFile(logFp, 8, True)
    If Err.Number = 0 Then
        fObj.WriteLine tsLine
        fObj.Close
    End If
    Set fObj = Nothing
    On Error Goto 0
End Sub

' ------------------------------------------------------------------------------
' UTF-8 URL Encoder & HTTP Diagnostic Reporter (Syncs to RS-Base & RS-Next)
' ------------------------------------------------------------------------------
Function UrlEncodeUtf8(rawStr)
    Dim stm, binData, i, bVal, outStr
    UrlEncodeUtf8 = ""
    If Len(rawStr) = 0 Then Exit Function

    On Error Resume Next
    Set stm = CreateObject("ADODB.Stream")
    If Err.Number <> 0 Or (stm Is Nothing) Then
        Err.Clear
        On Error Goto 0
        Exit Function
    End If

    stm.Type = 2 ' adTypeText
    stm.Charset = "utf-8"
    stm.Open
    stm.WriteText rawStr
    stm.Position = 0
    stm.Type = 1 ' adTypeBinary
    If stm.Size > 3 Then
        stm.Position = 3 ' Skip UTF-8 BOM
        binData = stm.Read
    Else
        binData = ""
    End If
    stm.Close
    Set stm = Nothing

    outStr = ""
    If Not IsEmpty(binData) Then
        For i = 1 To LenB(binData)
            bVal = AscB(MidB(binData, i, 1))
            If (bVal >= 48 And bVal <= 57) Or _
               (bVal >= 65 And bVal <= 90) Or _
               (bVal >= 97 And bVal <= 122) Or _
               bVal = 45 Or bVal = 95 Or bVal = 46 Then
                outStr = outStr & Chr(bVal)
            ElseIf bVal = 32 Then
                outStr = outStr & "+"
            Else
                outStr = outStr & "%" & Right("0" & Hex(bVal), 2)
            End If
        Next
    End If
    UrlEncodeUtf8 = outStr
    On Error Goto 0
End Function

Function HttpPostForm(url, formBody, timeoutMs)
    Dim http
    HttpPostForm = False
    On Error Resume Next
    Set http = CreateObject("MSXML2.ServerXMLHTTP.6.0")
    If Err.Number <> 0 Then
        Err.Clear
        Set http = CreateObject("MSXML2.XMLHTTP")
    End If
    If Not (http Is Nothing) Then
        http.setTimeouts timeoutMs, timeoutMs, timeoutMs, timeoutMs
        Err.Clear
        http.Open "POST", url, False
        http.setRequestHeader "Content-Type", "application/x-www-form-urlencoded; charset=UTF-8"
        http.setRequestHeader "Cache-Control", "no-cache"
        http.Send formBody
        If Err.Number = 0 And http.Status = 200 Then
            HttpPostForm = True
        End If
    End If
    Set http = Nothing
    On Error Goto 0
End Function

Function Bool01(bVal)
    If bVal Then
        Bool01 = "1"
    Else
        Bool01 = "0"
    End If
End Function

Sub ReportClientEvent(levelStr, eventStr, msgStr, detailStr, smbOkFlag, recordEventFlag)
    Dim ipCsv, cdburnExists, postData, candidates, idx, baseU, sentAny
    On Error Resume Next

    If recordEventFlag = "1" Then
        Call WriteLocalLog(levelStr, eventStr, msgStr, detailStr)
    End If

    ipCsv = ""
    If IsArray(myIPs) Then
        If UBound(myIPs) >= 0 Then ipCsv = Join(myIPs, ",")
    End If

    cdburnExists = objFSO.FileExists(selfDirPath & "\cd_burn.ps1") Or objFSO.FileExists("C:\RSBASE\cd_burn.ps1")

    postData = "action=report" & _
        "&source=watcher" & _
        "&level=" & UrlEncodeUtf8(levelStr) & _
        "&event=" & UrlEncodeUtf8(eventStr) & _
        "&version=" & UrlEncodeUtf8(WATCHER_VERSION) & _
        "&hostname=" & UrlEncodeUtf8(network.ComputerName) & _
        "&username=" & UrlEncodeUtf8(network.UserName) & _
        "&client_ips=" & UrlEncodeUtf8(ipCsv) & _
        "&install_dir=" & UrlEncodeUtf8(selfDirPath) & _
        "&client_ts=" & UrlEncodeUtf8(NowIsoStr()) & _
        "&wsh_ok=1" & _
        "&smb_ok=" & Bool01(smbOkFlag) & _
        "&rsview_ok=" & Bool01(objFSO.FileExists(normalExePath)) & _
        "&ecg_ok=" & Bool01(objFSO.FileExists(ecgExePath)) & _
        "&jpeg_tool_ok=" & Bool01(objFSO.FileExists(scriptPath)) & _
        "&cdburn_ok=" & Bool01(cdburnExists) & _
        "&record_event=" & recordEventFlag & _
        "&message=" & UrlEncodeUtf8(msgStr) & _
        "&detail=" & UrlEncodeUtf8(detailStr)

    If Len(customServerArg) > 0 Then
        candidates = Array(customServerArg)
    Else
        candidates = Array(prodHttpBase, rsnextHttpBase)
    End If

    sentAny = False
    For idx = 0 To UBound(candidates)
        baseU = candidates(idx)
        If Len(baseU) > 0 Then
            If HttpPostForm(baseU & "/client_logs.php?action=report", postData, 1500) Then
                sentAny = True
            End If
        End If
    Next

    If (Not sentAny) And Len(customServerArg) = 0 Then
        Call HttpPostForm(localHttpBase & "/client_logs.php?action=report", postData, 1200)
    End If
    On Error Goto 0
End Sub

' ------------------------------------------------------------------------------
' HTTP Helpers (Binary-safe + Adaptive Poll Helper)
' ------------------------------------------------------------------------------
Function HttpGetText(url, timeoutMs)
    Dim okDummy
    HttpGetText = HttpGetPoll(url, timeoutMs, okDummy)
End Function

Function HttpGetPoll(url, timeoutMs, ByRef connOkOut)
    Dim http, txt
    HttpGetPoll = ""
    connOkOut = False
    On Error Resume Next
    Set http = CreateObject("MSXML2.ServerXMLHTTP.6.0")
    If Err.Number <> 0 Then
        Err.Clear
        Set http = CreateObject("MSXML2.XMLHTTP")
    End If
    If Not (http Is Nothing) Then
        http.setTimeouts timeoutMs, timeoutMs, timeoutMs, timeoutMs
        Err.Clear
        http.Open "GET", url, False
        http.setRequestHeader "Cache-Control", "no-cache"
        http.Send
        If Err.Number = 0 And http.Status = 200 Then
            connOkOut = True
            txt = Trim(Replace(Replace(http.responseText, vbCr, ""), vbLf, ""))
            HttpGetPoll = txt
        End If
    End If
    Set http = Nothing
    On Error Goto 0
End Function

Function HttpDownloadBinary(url, savePath)
    Dim http, stm
    HttpDownloadBinary = False
    On Error Resume Next
    Set http = CreateObject("MSXML2.ServerXMLHTTP.6.0")
    If Err.Number <> 0 Then
        Err.Clear
        Set http = CreateObject("MSXML2.XMLHTTP")
    End If
    If Not (http Is Nothing) Then
        http.setTimeouts 3000, 3000, 12000, 12000
        Err.Clear
        http.Open "GET", url, False
        http.setRequestHeader "Cache-Control", "no-cache"
        http.Send
        If Err.Number = 0 And http.Status = 200 Then
            If LenB(http.responseBody) > 200 Then
                Set stm = CreateObject("ADODB.Stream")
                stm.Type = 1 ' adTypeBinary
                stm.Open
                stm.Write http.responseBody
                stm.SaveToFile savePath, 2 ' adSaveCreateOverWrite
                stm.Close
                Set stm = Nothing
                If Err.Number = 0 And objFSO.FileExists(savePath) Then
                    HttpDownloadBinary = True
                End If
            End If
        End If
    End If
    Set http = Nothing
    On Error Goto 0
End Function

Function IsValidVersionTag(verStr)
    Dim ch
    IsValidVersionTag = False
    If Len(verStr) < 3 Or Len(verStr) > 16 Then Exit Function
    ch = Left(verStr, 1)
    If ch >= "0" And ch <= "9" And InStr(verStr, ".") > 0 And InStr(verStr, "<") = 0 Then
        IsValidVersionTag = True
    End If
End Function

' ------------------------------------------------------------------------------
' Ensure Local RSViewer Zero-SMB Configuration & Silent Provisioning
' ------------------------------------------------------------------------------
Sub EnsureLocalRsViewerZeroSmb()
    Dim pubDir, viewDir, datPath, licView, licPub, curDat, fDat, launchPs1
    On Error Resume Next
    pubDir  = "C:\RSBASE\RSB_public_html"
    viewDir = pubDir & "\RSViewer"
    datPath = viewDir & "\RSB_Server.dat"
    licView = viewDir & "\RSView_LIC.dat"
    licPub  = pubDir & "\RSView_LIC.dat"

    If objFSO.FileExists(normalExePath) Then
        curDat = ""
        If objFSO.FileExists(datPath) Then
            Set fDat = objFSO.OpenTextFile(datPath, 1, False)
            If Not fDat.AtEndOfStream Then curDat = Trim(Replace(Replace(fDat.ReadAll, vbCr, ""), vbLf, ""))
            fDat.Close
            Set fDat = Nothing
        End If
        If LCase(curDat) <> LCase(pubDir & "\") Then
            Set fDat = objFSO.OpenTextFile(datPath, 2, True)
            fDat.WriteLine pubDir & "\"
            fDat.Close
            Set fDat = Nothing
            Call WriteLocalLog("INFO", "ZERO_SMB_HEAL", "RSB_Server.dat をローカル完結パスへ自動補正しました", "old=" & curDat & " -> new=" & pubDir & "\")
        End If
        If objFSO.FileExists(licView) And (Not objFSO.FileExists(licPub)) Then
            objFSO.CopyFile licView, licPub, True
        ElseIf objFSO.FileExists(licPub) And (Not objFSO.FileExists(licView)) Then
            objFSO.CopyFile licPub, licView, True
        End If
    ElseIf Not isSelfTest Then
        launchPs1 = ResolveHttpLauncherPs1()
        If Len(launchPs1) > 0 And objFSO.FileExists(launchPs1) Then
            objShell.Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -STA -File """ & launchPs1 & """ -ProvisionOnly", 0, False
        End If
    End If
    On Error Goto 0
End Sub

Function ResolveHttpLauncherPs1()
    Dim oPs1, cPs1, tPs1, bust
    On Error Resume Next
    ResolveHttpLauncherPs1 = ""
    oPs1 = selfDirPath & "\rsview_http_launch.ps1"
    cPs1 = "C:\RSBASE\rsview_http_launch.ps1"
    tPs1 = objFSO.GetSpecialFolder(2) & "\rsbase_rsview_http_launch.ps1"

    If objFSO.FileExists(oPs1) Then
        ResolveHttpLauncherPs1 = oPs1
        On Error Goto 0
        Exit Function
    ElseIf objFSO.FileExists(cPs1) Then
        ResolveHttpLauncherPs1 = cPs1
        On Error Goto 0
        Exit Function
    End If

    bust = "?t=" & CLng(Timer * 100)
    If HttpDownloadBinary(rsnextHttpBase & "/rsview_http_launch.ps1" & bust, tPs1) Then
        ResolveHttpLauncherPs1 = tPs1
    ElseIf HttpDownloadBinary(prodHttpBase & "/rsview_http_launch.ps1" & bust, tPs1) Then
        ResolveHttpLauncherPs1 = tPs1
    End If
    On Error Goto 0
End Function

Sub EnsureResetToolAndProtocol(hintBaseUrl)
    Dim resetExe, bust
    On Error Resume Next
    If LCase(selfDirPath & "\") = LCase(localRoot) Or LCase(selfDirPath & "\") = LCase(prodRoot) Or LCase(selfDirPath & "\") = "c:\apache24\htdocs\rsnext\" Then
        On Error Goto 0
        Exit Sub
    End If

    resetExe = selfDirPath & "\RSBaseReset.exe"
    If Not objFSO.FileExists(resetExe) Then
        bust = "?t=" & CLng(Timer * 100)
        If Len(hintBaseUrl) > 0 Then
            Call HttpDownloadBinary(hintBaseUrl & "/RSBaseReset.exe" & bust, resetExe)
        End If
        If Not objFSO.FileExists(resetExe) Then
            Call HttpDownloadBinary(rsnextHttpBase & "/RSBaseReset.exe" & bust, resetExe)
        End If
        If Not objFSO.FileExists(resetExe) Then
            Call HttpDownloadBinary(prodHttpBase & "/RSBaseReset.exe" & bust, resetExe)
        End If
    End If

    If objFSO.FileExists(resetExe) Then
        objShell.RegWrite "HKCU\Software\Classes\rsnext-reset\", "URL:RS-Next / RS-Base Viewer Reset Protocol", "REG_SZ"
        objShell.RegWrite "HKCU\Software\Classes\rsnext-reset\URL Protocol", "", "REG_SZ"
        objShell.RegWrite "HKCU\Software\Classes\rsnext-reset\shell\open\command\", """" & resetExe & """ ""%1""", "REG_SZ"
    End If
    On Error Goto 0
End Sub

Sub CheckSelfUpdate(smbState)
    Dim baseCandidates, i, baseUrl, remoteVer, activeBaseUrl
    Dim selfPath, selfDir, tmpVbs, tmpPs1, dstPs1, tmpHttpPs1, dstHttpPs1, tmpResetExe, dstResetExe, fChk, chkHead, cacheBust

    On Error Resume Next
    cacheBust = "?t=" & CLng(Timer * 100)

    If Len(customServerArg) > 0 Then
        baseCandidates = Array(customServerArg)
    ElseIf objFSO.FolderExists(localRoot & "tmp\") Then
        baseCandidates = Array(prodHttpBase, rsnextHttpBase, localHttpBase)
    Else
        baseCandidates = Array(prodHttpBase, rsnextHttpBase)
    End If

    remoteVer = ""
    activeBaseUrl = ""
    For i = 0 To UBound(baseCandidates)
        baseUrl = baseCandidates(i)
        remoteVer = HttpGetText(baseUrl & "/watcher_version.txt" & cacheBust, 2000)
        If IsValidVersionTag(remoteVer) Then
            activeBaseUrl = baseUrl
            Exit For
        End If
    Next

    selfPath = WScript.ScriptFullName
    selfDir  = objFSO.GetParentFolderName(selfPath)

    ' Do not overwrite the server master copy if run directly inside htdocs
    If LCase(selfDir & "\") = LCase(localRoot) Or LCase(selfDir & "\") = LCase(prodRoot) Or LCase(selfDir & "\") = "c:\apache24\htdocs\rsnext\" Then
        On Error Goto 0
        Exit Sub
    End If

    dstHttpPs1  = selfDir & "\rsview_http_launch.ps1"
    dstResetExe = selfDir & "\RSBaseReset.exe"
    If activeBaseUrl <> "" And (Not objFSO.FileExists(dstHttpPs1)) Then
        Call HttpDownloadBinary(activeBaseUrl & "/rsview_http_launch.ps1" & cacheBust, dstHttpPs1)
    End If
    Call EnsureResetToolAndProtocol(activeBaseUrl)

    If activeBaseUrl = "" Or remoteVer = WATCHER_VERSION Then
        On Error Goto 0
        Exit Sub
    End If

    tmpVbs      = selfDir & "\watcher.vbs.upd"
    tmpPs1      = selfDir & "\cd_burn.ps1.upd"
    dstPs1      = selfDir & "\cd_burn.ps1"
    tmpHttpPs1  = selfDir & "\rsview_http_launch.ps1.upd"
    tmpResetExe = selfDir & "\RSBaseReset.exe.upd"

    If HttpDownloadBinary(activeBaseUrl & "/watcher.vbs" & cacheBust, tmpVbs) Then
        chkHead = ""
        Set fChk = objFSO.OpenTextFile(tmpVbs, 1, False)
        If Not fChk.AtEndOfStream Then chkHead = fChk.Read(160)
        fChk.Close
        Set fChk = Nothing

        If InStr(chkHead, "watcher.vbs") > 0 And InStr(chkHead, "Option Explicit") > 0 Then
            If HttpDownloadBinary(activeBaseUrl & "/cd_burn.ps1" & cacheBust, tmpPs1) Then
                objFSO.CopyFile tmpPs1, dstPs1, True
                objFSO.DeleteFile tmpPs1, True
            End If
            If HttpDownloadBinary(activeBaseUrl & "/rsview_http_launch.ps1" & cacheBust, tmpHttpPs1) Then
                objFSO.CopyFile tmpHttpPs1, dstHttpPs1, True
                objFSO.DeleteFile tmpHttpPs1, True
            End If
            If HttpDownloadBinary(activeBaseUrl & "/RSBaseReset.exe" & cacheBust, tmpResetExe) Then
                objFSO.CopyFile tmpResetExe, dstResetExe, True
                objFSO.DeleteFile tmpResetExe, True
                Call EnsureResetToolAndProtocol(activeBaseUrl)
            End If

            Err.Clear
            objFSO.CopyFile tmpVbs, selfPath, True
            If Err.Number = 0 Then
                objFSO.DeleteFile tmpVbs, True
                Call ReportClientEvent("INFO", "SELF_UPDATE_OK", "watcher.vbs を v" & WATCHER_VERSION & " から v" & remoteVer & " へ自動更新しました", "source=" & activeBaseUrl, smbState, "1")
                objShell.Run "wscript.exe """ & selfPath & """", 0, False
                WScript.Quit 0
            Else
                Call ReportClientEvent("ERROR", "SELF_UPDATE_FAIL", "自動更新の上書き保存に失敗しました", "err=" & Err.Description & " | path=" & selfPath, smbState, "1")
                Err.Clear
            End If
        Else
            If objFSO.FileExists(tmpVbs) Then objFSO.DeleteFile tmpVbs, True
            Call ReportClientEvent("WARN", "SELF_UPDATE_INVALID", "取得した更新ファイルの検証に失敗したため更新をスキップしました", "source=" & activeBaseUrl, smbState, "1")
        End If
    Else
        Call ReportClientEvent("WARN", "SELF_UPDATE_DL_FAIL", "新バージョン (v" & remoteVer & ") のダウンロードに失敗しました", "source=" & activeBaseUrl, smbState, "1")
    End If
    On Error Goto 0
End Sub

' ------------------------------------------------------------------------------
' Launch Direct CD-R Burner GUI (Windows IMAPI2)
' ------------------------------------------------------------------------------
Sub LaunchCdBurner(rawPayload, activeRootDir, viaChannel, smbState)
    Dim pArr, bZip, bSrc, bVol, bPat, sPs1, oPs1, lPs1, cmdStr
    On Error Resume Next

    pArr = Split(rawPayload, "|")
    bZip = ""
    bSrc = ""
    bVol = "MED_EXAM_CD"
    bPat = ""
    If UBound(pArr) >= 0 Then bZip = Trim(pArr(0))
    If UBound(pArr) >= 1 Then bSrc = Trim(pArr(1))
    If UBound(pArr) >= 2 Then
        If Len(Trim(pArr(2))) > 0 Then bVol = Trim(pArr(2))
    End If
    If UBound(pArr) >= 3 Then bPat = Trim(pArr(3))

    oPs1 = objFSO.GetParentFolderName(WScript.ScriptFullName) & "\cd_burn.ps1"
    lPs1 = objFSO.GetSpecialFolder(2) & "\rsbase_cd_burn.ps1"
    sPs1 = ""
    If Len(activeRootDir) > 0 Then sPs1 = activeRootDir & "cd_burn.ps1"

    If objFSO.FileExists(oPs1) Then
        objFSO.CopyFile oPs1, lPs1, True
    ElseIf Len(sPs1) > 0 And objFSO.FileExists(sPs1) Then
        objFSO.CopyFile sPs1, lPs1, True
    ElseIf objFSO.FileExists("C:\RSBASE\cd_burn.ps1") Then
        objFSO.CopyFile "C:\RSBASE\cd_burn.ps1", lPs1, True
    ElseIf Not HttpDownloadBinary(prodHttpBase & "/cd_burn.ps1?t=" & CLng(Timer * 100), lPs1) Then
        Call HttpDownloadBinary(rsnextHttpBase & "/cd_burn.ps1?t=" & CLng(Timer * 100), lPs1)
    End If

    If objFSO.FileExists(lPs1) Then
        Err.Clear
        cmdStr = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -STA -File """ & lPs1 & """ -ZipUrl """ & bZip & """ -SourceDir """ & bSrc & """ -VolumeName """ & bVol & """ -PatientLabel """ & bPat & """"
        objShell.Run cmdStr, 0, False
        If Err.Number = 0 Then
            Call ReportClientEvent("INFO", "TRIGGER_OK", "CD書き込みツールを起動しました (" & viaChannel & ")", "mode=CDBURN | vol=" & bVol & " | zip=" & bZip, smbState, "1")
        Else
            Call ReportClientEvent("ERROR", "LAUNCH_ERROR", "CD書き込みツールの起動に失敗しました", "err=" & Err.Description & " | cmd=" & cmdStr, smbState, "1")
            Err.Clear
        End If
    Else
        Call ReportClientEvent("ERROR", "CDBURN_SCRIPT_MISSING", "CD書き込みスクリプト (cd_burn.ps1) が見つかりません", "checked=" & oPs1 & ", " & lPs1, smbState, "1")
        MsgBox "CD書き込みスクリプト (cd_burn.ps1) が見つかりません。" & vbCrLf & "RSBaseSetup.exe を実行して連携ツールを更新してください。", 16, "CD書き込みツール不在"
    End If
    On Error Goto 0
End Sub

' ------------------------------------------------------------------------------
' Launch Local RSViewer over Pure HTTP DICOM Cache (Zero-SMB)
' ------------------------------------------------------------------------------
Sub LaunchHttpDicomViewer(rawPayload, activeRootDir, viaChannel, smbState)
    Dim pArr, bUrl, bKey, srcPs1, runPs1, cmdStr
    On Error Resume Next

    pArr = Split(rawPayload, "|")
    bUrl = ""
    bKey = ""
    If UBound(pArr) >= 0 Then bUrl = Trim(pArr(0))
    If UBound(pArr) >= 1 Then bKey = Trim(pArr(1))
    If Len(bUrl) = 0 Then Exit Sub

    srcPs1 = ResolveHttpLauncherPs1()
    runPs1 = objFSO.GetSpecialFolder(2) & "\rsbase_rsview_http_launch.ps1"
    If Len(srcPs1) > 0 And objFSO.FileExists(srcPs1) Then
        If LCase(srcPs1) <> LCase(runPs1) Then
            objFSO.CopyFile srcPs1, runPs1, True
        End If
    End If

    If objFSO.FileExists(runPs1) Then
        Err.Clear
        cmdStr = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -STA -File """ & runPs1 & """ -BundleUrl """ & bUrl & """ -CacheKey """ & bKey & """"
        objShell.Run cmdStr, 0, False
        If Err.Number <> 0 Then
            Call ReportClientEvent("ERROR", "LAUNCH_ERROR", "RSViewer HTTPランチャーの起動に失敗しました", "err=" & Err.Description & " | cmd=" & cmdStr, smbState, "1")
            Err.Clear
        End If
    Else
        Call ReportClientEvent("ERROR", "HTTP_LAUNCHER_MISSING", "RSViewer HTTP起動スクリプト (rsview_http_launch.ps1) が取得できませんでした", "url=" & bUrl, smbState, "1")
        MsgBox "RSViewer HTTP起動スクリプト (rsview_http_launch.ps1) が見つかりません。" & vbCrLf & "RSBaseSetup.exe を実行して更新してください。", 16, "連携スクリプト不在"
    End If
    On Error Goto 0
End Sub

Sub DispatchTriggerLine(rawLine, activeRootDir, viaChannel, smbState)
    Dim dPos, mName, pLoad, cPath, lCmd, sFolder, pathReachable, nowT
    On Error Resume Next
    rawLine = Trim(rawLine)
    If Len(rawLine) = 0 Then Exit Sub

    ' 4-second duplicate suppression across SMB + HTTP channels
    nowT = Timer
    If rawLine = lastDispatchLine And Abs(nowT - lastDispatchTimer) < 4 Then
        Exit Sub
    End If
    lastDispatchLine = rawLine
    lastDispatchTimer = nowT

    dPos = InStr(rawLine, "|")
    If dPos > 0 Then
        mName = UCase(Trim(Left(rawLine, dPos - 1)))
        pLoad = Mid(rawLine, dPos + 1)
    Else
        mName = "NORMAL"
        pLoad = rawLine
    End If

    If mName = "HTTP_DICOM" Then
        Call LaunchHttpDicomViewer(pLoad, activeRootDir, viaChannel, smbState)
        On Error Goto 0
        Exit Sub
    ElseIf mName = "CLEAR_CACHE" Then
        Dim clrPs1
        clrPs1 = ResolveHttpLauncherPs1()
        If Len(clrPs1) > 0 And objFSO.FileExists(clrPs1) Then
            objShell.Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -STA -File """ & clrPs1 & """ -ClearCacheOnly", 0, False
        End If
        On Error Goto 0
        Exit Sub
    End If

    If mName = "NORMAL" And (Left(LCase(Trim(pLoad)), 7) = "http://" Or Left(LCase(Trim(pLoad)), 8) = "https://") Then
        Call LaunchHttpDicomViewer(pLoad, activeRootDir, viaChannel, smbState)
        On Error Goto 0
        Exit Sub
    End If

    cPath = Replace(pLoad, "/", "\")

    If mName = "CDBURN" Then
        Call LaunchCdBurner(pLoad, activeRootDir, viaChannel, smbState)
    ElseIf mName = "UPDATE" Then
        Call CheckSelfUpdate(smbState)
    ElseIf mName = "ECG" Then
        If Not objFSO.FileExists(ecgExePath) Then
            Call ReportClientEvent("ERROR", "VIEWER_NOT_FOUND", "心電図ビューワ (FEV-05.exe) がこのPCに見つかりません", "expected=" & ecgExePath & " | target=" & cPath & " | via=" & viaChannel, smbState, "1")
            MsgBox "このPCに心電図ビューワ (" & ecgExePath & ") がインストールされていないため起動できませんでした。" & vbCrLf & vbCrLf & "RS-Base / RS-Next 画面のWeb心電図ビューワからブラウザ上で心電図をご確認いただけます。", 48, "心電図ビューワ不在"
        Else
            If InStr(cPath, "\") > 0 Then
                pathReachable = objFSO.FileExists(cPath) Or objFSO.FolderExists(cPath)
                If Not pathReachable Then
                    Call ReportClientEvent("WARN", "TARGET_PATH_INACCESSIBLE", "心電図データパスへこのPCからアクセスできません（共有フォルダ資格情報等を確認）", "target=" & cPath & " | via=" & viaChannel, smbState, "1")
                End If
            End If
            Err.Clear
            lCmd = """" & ecgExePath & """ """ & cPath & """"
            objShell.Run lCmd, 1, False
            If Err.Number = 0 Then
                Call ReportClientEvent("INFO", "TRIGGER_OK", "心電図ビューワ (FEV-05) を起動しました (" & viaChannel & ")", "mode=ECG | target=" & cPath, smbState, "1")
            Else
                Call ReportClientEvent("ERROR", "LAUNCH_ERROR", "心電図ビューワの起動に失敗しました", "err=" & Err.Description & " | cmd=" & lCmd, smbState, "1")
                Err.Clear
            End If
        End If
    ElseIf mName = "JPEG" Then
        If Not objFSO.FileExists(scriptPath) Then
            Call ReportClientEvent("ERROR", "JPEG_TOOL_NOT_FOUND", "胃がん検診DICOM変換ツール (dicom_convert.py) が見つかりません", "expected=" & scriptPath & " | target=" & cPath, smbState, "1")
            MsgBox "DICOM変換ツールが見つかりません。" & vbCrLf & scriptPath, 16, "ツール不在エラー"
        Else
            sFolder = InputBox("保存するサブフォルダ名を入力してください:", "フォルダ名指定")
            If Trim(sFolder) <> "" Then
                If Right(cPath, 1) = "\" Then
                    cPath = Left(cPath, Len(cPath) - 1)
                End If
                Err.Clear
                lCmd = "cmd.exe /k python """ & scriptPath & """ """ & cPath & """ """ & sFolder & """"
                objShell.Run lCmd, 1, False
                If Err.Number = 0 Then
                    Call ReportClientEvent("INFO", "TRIGGER_OK", "胃がん検診JPEG変換ツールを起動しました", "mode=JPEG | subfolder=" & sFolder & " | target=" & cPath, smbState, "1")
                Else
                    Call ReportClientEvent("ERROR", "LAUNCH_ERROR", "胃がん検診JPEG変換ツールの起動に失敗しました", "err=" & Err.Description, smbState, "1")
                    Err.Clear
                End If
            End If
        End If
    Else
        Call EnsureLocalRsViewerZeroSmb()
        If Not objFSO.FileExists(normalExePath) Then
            Call ReportClientEvent("ERROR", "VIEWER_NOT_FOUND", "RSViewer実行ファイル (rsview.exe) がこのPCに見つかりません", "expected=" & normalExePath & " | target=" & cPath & " | via=" & viaChannel, smbState, "1")
            MsgBox "このPCに RSViewer (" & normalExePath & ") がインストールされていないため起動できませんでした。" & vbCrLf & vbCrLf & "RSBaseSetup.exe を実行するか、Web内蔵DICOMビューワをご利用ください。", 48, "RSViewer不在"
        Else
            If InStr(cPath, "\") > 0 Then
                pathReachable = objFSO.FolderExists(cPath) Or objFSO.FileExists(cPath)
                If Not pathReachable Then
                    Call ReportClientEvent("WARN", "TARGET_PATH_INACCESSIBLE", "検査データフォルダへこのPCからアクセスできません", "target=" & cPath & " | via=" & viaChannel, smbState, "1")
                End If
            End If
            Err.Clear
            lCmd = "cmd.exe /c start """" """ & normalExePath & """ """ & cPath & """"
            objShell.Run lCmd, 7, False
            If Err.Number = 0 Then
                Call ReportClientEvent("INFO", "TRIGGER_OK", "RSViewerを起動しました (" & viaChannel & ")", "mode=NORMAL | target=" & cPath, smbState, "1")
            Else
                Call ReportClientEvent("ERROR", "LAUNCH_ERROR", "RSViewerの起動実行時にエラーが発生しました", "err=" & Err.Description & " | cmd=" & lCmd, smbState, "1")
                Err.Clear
            End If
        End If
    End If
    On Error Goto 0
End Sub

' Function to retrieve all active IPv4 addresses (Ethernet, Wi-Fi, VPN)
Function GetAllMyIPs()
    Dim ipList, ip, colItems, objItem, i
    Set ipList = CreateObject("Scripting.Dictionary")
    On Error Resume Next

    Set colItems = GetObject("winmgmts:\\.\root\StandardCimv2").ExecQuery("Select IPAddress from MSFT_NetIPAddress Where AddressFamily = 2")
    If Err.Number = 0 Then
        For Each objItem in colItems
            ip = objItem.IPAddress
            If InStr(ip, ".") > 0 And Left(ip, 7) <> "169.254" And ip <> "127.0.0.1" Then
                If Not ipList.Exists(ip) Then ipList.Add ip, True
            End If
        Next
    End If
    Err.Clear

    Set colItems = GetObject("winmgmts:\\.\root\cimv2").ExecQuery("Select IPAddress from Win32_NetworkAdapterConfiguration Where IPEnabled = True")
    If Err.Number = 0 Then
        For Each objItem in colItems
            If Not IsNull(objItem.IPAddress) Then
                For i = 0 To UBound(objItem.IPAddress)
                    ip = objItem.IPAddress(i)
                    If InStr(ip, ".") > 0 And Left(ip, 7) <> "169.254" And ip <> "127.0.0.1" Then
                        If Not ipList.Exists(ip) Then ipList.Add ip, True
                    End If
                Next
            End If
        Next
    End If
    On Error Goto 0

    GetAllMyIPs = ipList.Keys
End Function

' Initial IP detection & SMB check
myIPs = GetAllMyIPs()
smbAnyAccessible = objFSO.FolderExists(prodRoot & "tmp\") Or objFSO.FolderExists(localRoot & "tmp\")
lastSmbState = smbAnyAccessible

If isSelfTest Then
    Call EnsureLocalRsViewerZeroSmb()
    Call ReportClientEvent("INFO", "SELF_TEST", "watcher.vbs 自己診断テストを実行しました", "script=" & WScript.ScriptFullName, smbAnyAccessible, "1")
    WScript.Echo "SELF_TEST_OK ver=" & WATCHER_VERSION
    WScript.Quit 0
End If

' Initial Auto-Update check & Zero-SMB RSViewer heal/provision at startup
Call CheckSelfUpdate(smbAnyAccessible)
Call EnsureLocalRsViewerZeroSmb()

' Report Startup Diagnostics
Call ReportClientEvent("INFO", "STARTUP", "watcher.vbs (v" & WATCHER_VERSION & ") が常駐を開始しました (RS-Base / RS-Next 統合HTTP連携モード)", "dir=" & selfDirPath & " | smb=" & Bool01(smbAnyAccessible), smbAnyAccessible, "1")

loopCount = 0

' Main Loop (checks every 0.75 second; dual RS-Base + RS-Next HTTP polling)
Do While True
    On Error Resume Next

    hasIpCached = False
    If IsArray(myIPs) Then
        If UBound(myIPs) >= 0 Then hasIpCached = True
    End If
    If ((Not hasIpCached) And (loopCount > 0) And (loopCount Mod 15 = 0)) Or (loopCount > 0 And (loopCount Mod 400 = 0)) Then
        myIPs = GetAllMyIPs()
    End If

    If loopCount > 0 And (loopCount Mod 800 = 0) Then
        Call CheckSelfUpdate(smbAnyAccessible)
        Call EnsureLocalRsViewerZeroSmb()
        Call ReportClientEvent("INFO", "HEARTBEAT", "常駐稼働中", "", smbAnyAccessible, "0")
    End If
    loopCount = loopCount + 1

    triggered = False
    smbAnyAccessible = False
    rsbaseSmbAccessible = False

    If objFSO.FolderExists("C:\Apache24\htdocs\rsnext\tmp\") Or objFSO.FolderExists(localRoot & "tmp\") Then
        candidateRoots = Array("C:\Apache24\htdocs\rsnext\", localRoot, prodRoot)
    Else
        candidateRoots = Array(prodRoot)
    End If

    For rootIdx = 0 To UBound(candidateRoots)
        activeRoot = candidateRoots(rootIdx)
        activeTmpDir = activeRoot & "tmp\"

        If objFSO.FolderExists(activeTmpDir) Then
            smbAnyAccessible = True
            If activeRoot = prodRoot Or activeRoot = localRoot Then
                rsbaseSmbAccessible = True
            End If

            If activeRoot = localRoot Or LCase(activeRoot) = "c:\apache24\htdocs\rsnext\" Then
                If UBound(myIPs) >= 0 Then
                    checkIPs = Split("127.0.0.1," & Join(myIPs, ","), ",")
                Else
                    checkIPs = Array("127.0.0.1")
                End If
            Else
                checkIPs = myIPs
            End If

            For Each currentIp in checkIPs
                ' (1) Dedicated CD Burn file (cdburn_<IP>.txt)
                myBurnFile = activeTmpDir & "cdburn_" & currentIp & ".txt"
                If objFSO.FileExists(myBurnFile) Then
                    fileContent = ""
                    Set objFile = objFSO.OpenTextFile(myBurnFile, 1, False)
                    If Not objFile.AtEndOfStream Then
                        fileContent = Trim(objFile.ReadAll)
                    End If
                    objFile.Close
                    Set objFile = Nothing

                    objFSO.DeleteFile myBurnFile, True
                    triggered = True

                    If Len(fileContent) > 0 Then
                        If Left(fileContent, 7) = "CDBURN|" Then
                            payload = Mid(fileContent, 8)
                        Else
                            payload = fileContent
                        End If
                        Call LaunchCdBurner(payload, activeRoot, "SMB", True)
                    End If
                    Exit For
                End If

                ' (2) Standard viewer / HTTP_DICOM / JPEG file (trigger_<IP>.txt)
                myTriggerFile = activeTmpDir & "trigger_" & currentIp & ".txt"
                If objFSO.FileExists(myTriggerFile) Then
                    fileContent = ""
                    Set objFile = objFSO.OpenTextFile(myTriggerFile, 1, False)
                    If Not objFile.AtEndOfStream Then
                        fileContent = Trim(objFile.ReadAll)
                    End If
                    objFile.Close
                    Set objFile = Nothing

                    objFSO.DeleteFile myTriggerFile, True
                    triggered = True

                    Call DispatchTriggerLine(fileContent, activeRoot, "SMB", True)
                    Exit For
                End If
            Next
        End If

        If triggered Then Exit For
    Next

    lastSmbState = smbAnyAccessible

    ' (3) HTTP Polling for 192.168.1.16/rsbase when SMB is not mounted
    If (Not triggered) And (Not rsbaseSmbAccessible) Then
        If rsbaseHttpCooldown > 0 Then
            rsbaseHttpCooldown = rsbaseHttpCooldown - 1
        Else
            httpPollText = HttpGetPoll(prodHttpBase & "/client_logs.php?action=poll_trigger&t=" & CLng(Timer * 100), 1000, httpConnOk)
            If Not httpConnOk Then
                rsbaseHttpCooldown = 20
            ElseIf Len(httpPollText) > 0 And InStr(httpPollText, "<") = 0 Then
                Call DispatchTriggerLine(httpPollText, "", "HTTP-RSBase", smbAnyAccessible)
                triggered = True
            End If
        End If
    End If

    ' (4) HTTP Polling for 192.168.1.163/rsnext ALWAYS (Zero-SMB native polling)
    If Not triggered Then
        If rsnextHttpCooldown > 0 Then
            rsnextHttpCooldown = rsnextHttpCooldown - 1
        Else
            httpPollText = HttpGetPoll(rsnextHttpBase & "/client_logs.php?action=poll_trigger&t=" & CLng(Timer * 100), 1000, httpConnOk)
            If Not httpConnOk Then
                rsnextHttpCooldown = 20
            ElseIf Len(httpPollText) > 0 And InStr(httpPollText, "<") = 0 Then
                Call DispatchTriggerLine(httpPollText, "", "HTTP-RSNext", smbAnyAccessible)
                triggered = True
            End If
        End If
    End If

    On Error Goto 0

    WScript.Sleep 750
Loop
