$ErrorActionPreference = 'Stop'
Set-PSDebug -Off
if ([Diagnostics.Debugger]::IsAttached) { throw 'Debugger attached: private input unavailable.' }
if ($Host.Name -ne 'ConsoleHost' -or [Console]::IsInputRedirected -or [Console]::IsOutputRedirected) {
    throw 'Use your interactive Windows PowerShell console, without redirection or transcription.'
}
if ($env:OS -ne 'Windows_NT') { throw 'Windows PowerShell required.' }
foreach ($hive in @('HKLM:', 'HKCU:')) {
    foreach ($policy in @('Transcription', 'ModuleLogging', 'ScriptBlockLogging')) {
        $key = Join-Path $hive ('Software\Policies\Microsoft\Windows\PowerShell\' + $policy)
        if (Test-Path $key) {
            $settings = Get-ItemProperty $key
            if ($settings.EnableTranscripting -eq 1 -or $settings.EnableModuleLogging -eq 1 -or $settings.EnableScriptBlockLogging -eq 1) {
                throw 'PowerShell capture policy detected. Use an approved private terminal without capture.'
            }
        }
    }
}
$root = Split-Path $PSScriptRoot -Parent
if ([IO.Path]::GetFullPath($root).TrimEnd('\') -ne 'C:\laragon\www\zemboa') { throw 'Unexpected project root.' }
# Fail closed on unknown PHP writers, including development servers, workers and schedulers.
if (Get-Process -ErrorAction Stop | Where-Object { $_.ProcessName -in @('php', 'php-cgi') }) { throw 'Stop PHP servers/workers first, including the DEMO server.' }
if (Get-NetTCPConnection -State Listen | Where-Object { $_.LocalPort -eq 8016 }) { throw 'DEMO port is still active.' }
if ((Read-Host 'Close SQLite editors/writers/schedulers and disable terminal recording/transcription. Type DEMO-STOPPED to confirm') -cne 'DEMO-STOPPED') { throw 'Private console and stopped writers not confirmed.' }
$php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) { throw 'Expected Laragon PHP unavailable.' }
$first = $null; $second = $null; $process = $null
$pointer1 = [IntPtr]::Zero; $pointer2 = [IntPtr]::Zero
try {
    $first = Read-Host 'New DEMO password (at least 12 characters)' -AsSecureString
    $second = Read-Host 'Confirm DEMO password' -AsSecureString
    if ($first.Length -lt 12 -or $first.Length -gt 1024 -or $second.Length -ne $first.Length) { throw 'Invalid password length or confirmation.' }
    $pointer1 = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($first)
    $pointer2 = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($second)
    $plain1 = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer1)
    $plain2 = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer2)
    if ($plain1 -cne $plain2) { throw 'Password confirmation does not match.' }
    $payload = [ordered]@{ password=$plain1; confirmation=$plain2; writers_stopped=$true } | ConvertTo-Json -Compress
    $info = New-Object Diagnostics.ProcessStartInfo
    $info.FileName = $php
    $info.Arguments = '-d zend.exception_ignore_args=1 -d display_errors=0 -d log_errors=0 tools/demo-super-admin.php'
    $info.WorkingDirectory = $root
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $info.RedirectStandardInput = $true
    $process = [Diagnostics.Process]::Start($info)
    $buffer = [Text.Encoding]::UTF8.GetBytes($payload)
    $process.StandardInput.BaseStream.Write($buffer, 0, $buffer.Length)
    $process.StandardInput.BaseStream.Flush()
    $process.StandardInput.Close()
    $payload = $null; $plain1 = $null; $plain2 = $null
    if ($buffer) { [Array]::Clear($buffer, 0, $buffer.Length) }
    $process.WaitForExit()
    if ($process.ExitCode -ne 0) { throw 'Provisioning stopped; do not retry automatically.' }
} finally {
    $payload = $null; $plain1 = $null; $plain2 = $null
    if ($buffer) { [Array]::Clear($buffer, 0, $buffer.Length) }
    if ($pointer1 -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer1) }
    if ($pointer2 -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer2) }
    if ($first) { $first.Dispose() }
    if ($second) { $second.Dispose() }
    if ($process) { $process.Dispose() }
}
