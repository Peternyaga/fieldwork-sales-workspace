Option Explicit

Dim shell, files, directory, powershell, command, result
Set shell = CreateObject("WScript.Shell")
Set files = CreateObject("Scripting.FileSystemObject")
directory = files.GetParentFolderName(WScript.ScriptFullName)
powershell = shell.ExpandEnvironmentStrings("%SystemRoot%") & "\System32\WindowsPowerShell\v1.0\powershell.exe"
command = Chr(34) & powershell & Chr(34) & " -NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File " & Chr(34) & directory & "\poller.ps1" & Chr(34) & " -DataDirectory " & Chr(34) & directory & Chr(34)
result = shell.Run(command, 0, True)
WScript.Quit result
