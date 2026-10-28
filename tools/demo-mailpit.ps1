$ErrorActionPreference = 'Stop'
# Standalone process only. Never alters Laragon configuration or installs a service.
if (Get-ChildItem Env:MP_*) { throw 'Refusing inherited Mailpit configuration.' }
$exe = Join-Path (Split-Path $PSScriptRoot -Parent) 'storage/demo/tools/mailpit/v1.32.0/mailpit.exe'
if (-not (Test-Path -LiteralPath $exe -PathType Leaf)) { throw 'Install the verified Mailpit binary first; see docs/DEMO_MAILPIT_SETUP.md.' }
if ((Get-FileHash -LiteralPath $exe -Algorithm SHA256).Hash -ne '9276BBCD182565F2ACD594CA1178DA667C6B5B716A75E8DA979C103C38FC1280') { throw 'Mailpit executable integrity mismatch.' }
if (Get-NetTCPConnection -State Listen | Where-Object { $_.LocalPort -in @(1025,8025) }) { throw 'Ports occupied. Inspect the existing process; do not kill automatically.' }
$process = Start-Process -FilePath $exe -WindowStyle Hidden -PassThru -ArgumentList @(
    '--listen', '127.0.0.1:8025', '--smtp', '127.0.0.1:1025',
    '--allowed-hosts', '127.0.0.1:8025', '--smtp-allowed-recipients', '@example\.test$',
    '--smtp-disable-rdns', '--disable-version-check', '--block-remote-css-and-fonts', '--quiet'
)
Write-Output ('Mailpit started, PID ' + $process.Id + '. Verify loopback listeners before use.')
