param([string]$WorkspaceRoot=(Get-Location).Path)
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Security
$name='Fieldwork Reach Poller'
$task=Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
if($task){Unregister-ScheduledTask -TaskName $name -Confirm:$false}
$root=(Resolve-Path -LiteralPath $WorkspaceRoot).Path
$dataDirectory=Join-Path $root '.fieldwork-reach'
if(Test-Path -LiteralPath $dataDirectory){
    $credential=Join-Path $dataDirectory 'credential.txt'
    $configFile=Join-Path $dataDirectory 'config.json'
    if((Test-Path -LiteralPath $credential) -and (Test-Path -LiteralPath $configFile)){
        try {
            $config=Get-Content -LiteralPath $configFile -Raw | ConvertFrom-Json
            $cipher=[Convert]::FromBase64String((Get-Content -LiteralPath $credential -Raw).Trim())
            $token=[Text.Encoding]::UTF8.GetString([System.Security.Cryptography.ProtectedData]::Unprotect($cipher,$null,[System.Security.Cryptography.DataProtectionScope]::CurrentUser))
            Invoke-RestMethod -Method Post -Uri ($config.origin+'/api/automation/revoke-self') -Headers @{Authorization="Bearer $token"} -ContentType 'application/json' -Body '{}' -TimeoutSec 15 | Out-Null
        } catch {Write-Warning 'Could not revoke server polling access. Revoke the poller in Reach under Connected apps.'}
    }
    $resolved=(Resolve-Path -LiteralPath $dataDirectory).Path
    $expected=[IO.Path]::GetFullPath((Join-Path $root '.fieldwork-reach'))
    if($resolved -ne $expected){throw 'Unexpected poller directory; files were not removed.'}
    Remove-Item -LiteralPath $resolved -Recurse -Force
}
Write-Host 'Local Fieldwork Reach poller removed.'
