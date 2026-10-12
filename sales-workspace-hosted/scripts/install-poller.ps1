param(
    [switch]$Accept,
    [string]$WorkspaceRoot=(Get-Location).Path,
    [string]$Origin='https://reach.vumbualabs.com'
)
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Security
if(-not $Accept){throw 'Review the Fieldwork automation permissions, then run setup with -Accept.'}
if($env:OS -ne 'Windows_NT'){throw 'This installer supports Windows only.'}
if($Origin -ne 'https://reach.vumbualabs.com'){throw 'Unexpected Reach origin.'}
$codex=(Get-Command codex -ErrorAction Stop).Source
$root=(Resolve-Path -LiteralPath $WorkspaceRoot).Path
$pluginList=(& $codex plugin list | Out-String)
if($pluginList -notmatch 'sales-workspace-hosted@[a-z0-9-]+\s+installed, enabled'){throw 'Install and enable the Fieldwork Reach plugin before setting up the poller.'}
$dataDirectory=Join-Path $root '.fieldwork-reach'
$existing=Get-ScheduledTask -TaskName 'Fieldwork Reach Poller' -ErrorAction SilentlyContinue
if($existing){
    if(-not (Test-Path -LiteralPath (Join-Path $dataDirectory 'credential.txt'))){throw 'The scheduled task exists but its credential is missing. Remove the old task before reinstalling.'}
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'poller.ps1') -Destination (Join-Path $dataDirectory 'poller.ps1') -Force
    Write-Host 'Fieldwork Reach poller is already installed. Its local script was updated.'
    return
}
New-Item -ItemType Directory -Path $dataDirectory -Force | Out-Null
'*' | Set-Content -LiteralPath (Join-Path $dataDirectory '.gitignore') -Encoding Ascii
$rng=New-Object Security.Cryptography.RNGCryptoServiceProvider
$raw=New-Object byte[] 32;$rng.GetBytes($raw)
$verifier=([BitConverter]::ToString($raw)).Replace('-','').ToLowerInvariant()
$sha=[Security.Cryptography.SHA256]::Create()
$challenge=([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($verifier)))).Replace('-','').ToLowerInvariant()
$pair=Invoke-RestMethod -Method Post -Uri ($Origin+'/api/automation/pair/start') -ContentType 'application/json' -Body (@{challenge=$challenge;device_name=$env:COMPUTERNAME}|ConvertTo-Json) -TimeoutSec 15
Write-Host 'Fieldwork will open a page that explains the poller permission. Sign in and select Allow local poller.'
Start-Process $pair.approval_url
$token=$null
for($attempt=0;$attempt -lt 150;$attempt++){
    Start-Sleep -Seconds 4
    $finish=Invoke-RestMethod -Method Post -Uri ($Origin+'/api/automation/pair/finish') -ContentType 'application/json' -Body (@{id=$pair.id;verifier=$verifier}|ConvertTo-Json) -TimeoutSec 15
    if($finish.status -eq 'connected'){$token=$finish.token;break}
}
if(-not $token){throw 'Pairing was not approved within ten minutes. No scheduled task was installed.'}
try {
$cipher=[System.Security.Cryptography.ProtectedData]::Protect([Text.Encoding]::UTF8.GetBytes($token),$null,[System.Security.Cryptography.DataProtectionScope]::CurrentUser)
[Convert]::ToBase64String($cipher) | Set-Content -LiteralPath (Join-Path $dataDirectory 'credential.txt') -Encoding Ascii
@{origin=$Origin;workspace=$root;codex=$codex} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $dataDirectory 'config.json') -Encoding UTF8
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'poller.ps1') -Destination (Join-Path $dataDirectory 'poller.ps1') -Force
$check=Invoke-RestMethod -Method Get -Uri ($Origin+'/api/automation/ready') -Headers @{Authorization="Bearer $token"} -TimeoutSec 15
if($null -eq $check.ready){throw 'The readiness check did not return the expected result.'}
    $script=Join-Path $dataDirectory 'poller.ps1'
    $argument='-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "'+$script+'" -DataDirectory "'+$dataDirectory+'"'
    $action=New-ScheduledTaskAction -Execute (Join-Path $PSHOME 'powershell.exe') -Argument $argument
    $logon=New-ScheduledTaskTrigger -AtLogOn -User ([Security.Principal.WindowsIdentity]::GetCurrent().Name)
    $repeat=New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 3650)
    $settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1)
    $principal=New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
    Register-ScheduledTask -TaskName 'Fieldwork Reach Poller' -Action $action -Trigger @($logon,$repeat) -Settings $settings -Principal $principal -Force | Out-Null
} catch {
    Unregister-ScheduledTask -TaskName 'Fieldwork Reach Poller' -Confirm:$false -ErrorAction SilentlyContinue
    try{Invoke-RestMethod -Method Post -Uri ($Origin+'/api/automation/revoke-self') -Headers @{Authorization="Bearer $token"} -ContentType 'application/json' -Body '{}' -TimeoutSec 15 | Out-Null}catch{}
    foreach($name in @('credential.txt','config.json','poller.ps1')){Remove-Item -LiteralPath (Join-Path $dataDirectory $name) -Force -ErrorAction SilentlyContinue}
    throw 'Poller setup failed after approval. No poller was installed. '+$_.Exception.Message
}
Write-Host 'Fieldwork Reach poller installed for this Windows account. It checks at sign-in and every five minutes while this account is signed in.'
