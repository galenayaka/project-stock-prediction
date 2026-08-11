' ============================================================================
'  StockPrediction — ML Service Silent Launcher
'  Runs the Python ML service in the background with no visible console window.
'
'  Double-click this file to start the service silently.
'  Place a shortcut to this file in shell:startup to auto-start on boot.
' ============================================================================

Set WshShell = CreateObject("WScript.Shell")

' Get the directory where this script lives
scriptDir = CreateObject("Scripting.FileSystemObject").GetParentFolderName(WScript.ScriptFullName)

' Run the batch file hidden
WshShell.Run """" & scriptDir & "\start_ml_service.bat" & """", 0, False
