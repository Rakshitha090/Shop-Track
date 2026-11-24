Set WshShell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
currentDir = fso.GetParentFolderName(WScript.ScriptFullName)

' Start PHP server completely hidden (0 = hide window, False = don't wait)
WshShell.Run "cmd /c ""cd /d """ & currentDir & """ && ""C:\Users\raksh\php83\php.exe"" -S localhost:8000""", 0, False

' Give PHP 2 seconds to bind to port 8000
WScript.Sleep 2000

' Open the application in Google Chrome
chromePath = "C:\Program Files\Google\Chrome\Application\chrome.exe"
If fso.FileExists(chromePath) Then
    WshShell.Run """" & chromePath & """ http://localhost:8000"
Else
    WshShell.Run "http://localhost:8000"
End If
