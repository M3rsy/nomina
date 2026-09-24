[CmdletBinding()]
param(
    [string]$Distro = 'Ubuntu',
    [string]$RepoUrl = 'https://github.com/M3rsy/nomina.git',
    [string]$LinuxProjectPath = '~/proyectos/nomina',
    [ValidateSet('Clean', 'Demo')]
    [string]$InstallMode = 'Clean',
    [switch]$Worker,
    [switch]$Update,
    [switch]$ResetDatabase,
    [string]$AdminEmail = 'admin@example.com',
    [string]$AdminName = 'Super Admin',
    [Security.SecureString]$AdminPassword
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function ConvertTo-BashLiteral {
    param([AllowEmptyString()][string]$Value)

    return "'" + $Value.Replace("'", "'\"'\"'") + "'"
}

function Invoke-WslCommand {
    param(
        [Parameter(Mandatory = $true)][string]$Command,
        [Parameter(Mandatory = $true)][string]$FailureMessage,
        [AllowEmptyString()][string]$StandardInput,
        [switch]$WithStandardInput
    )

    if ($WithStandardInput) {
        $StandardInput | & wsl.exe -d $Distro -- bash -lc $Command
    }
    else {
        & wsl.exe -d $Distro -- bash -lc $Command
    }

    if ($LASTEXITCODE -ne 0) {
        throw "$FailureMessage (código de salida: $LASTEXITCODE)."
    }
}

function Get-WslCommandOutput {
    param(
        [Parameter(Mandatory = $true)][string]$Command,
        [Parameter(Mandatory = $true)][string]$FailureMessage
    )

    $output = & wsl.exe -d $Distro -- bash -lc $Command 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "$FailureMessage (código de salida: $LASTEXITCODE)."
    }

    return (($output | ForEach-Object { $_.ToString() }) -join "`n").Trim()
}

if ($env:OS -ne 'Windows_NT') {
    throw 'Este script debe ejecutarse en Windows PowerShell o PowerShell 7 sobre Windows.'
}

if (-not (Get-Command wsl.exe -ErrorAction SilentlyContinue)) {
    throw 'No se encontró wsl.exe. Instalá WSL2 con "wsl --install -d Ubuntu", reiniciá Windows y volvé a intentar.'
}

Write-Host "Validando la distribución WSL '$Distro'..." -ForegroundColor Cyan
$installedDistros = @(& wsl.exe --list --quiet 2>$null) | ForEach-Object {
    $_.ToString().Replace([char]0, '').Trim()
} | Where-Object { $_ }
if ($LASTEXITCODE -ne 0) {
    throw 'WSL no respondió correctamente. Ejecutá "wsl --status" y completá la instalación de WSL2.'
}
if ($installedDistros -notcontains $Distro) {
    throw "La distribución '$Distro' no está instalada. Instalála con " +
        "'wsl --install -d $Distro' o elegí una existente con -Distro."
}

$distroDetails = @(& wsl.exe --list --verbose 2>$null) | ForEach-Object {
    $_.ToString().Replace([char]0, '').TrimEnd()
}
if ($LASTEXITCODE -ne 0) {
    throw 'No se pudo consultar la versión de WSL. Ejecutá "wsl --update" y volvé a intentar.'
}
$distroPattern = '^\s*\*?\s*' + [regex]::Escape($Distro) + '\s+.+\s+2\s*$'
if (-not ($distroDetails | Where-Object { $_ -match $distroPattern })) {
    throw "La distribución '$Distro' no usa WSL2. Convertíla con: wsl --set-version $Distro 2"
}

$rawPath = ConvertTo-BashLiteral $LinuxProjectPath
$resolvePathCommand = @"
set -euo pipefail
raw=$rawPath
case "`$raw" in
    '~') target="`$HOME" ;;
    '~/'*) target="`$HOME/`${raw#\~/}" ;;
    /*) target="`$raw" ;;
    *) echo 'La ruta debe ser absoluta dentro de Linux o comenzar con ~/.' >&2; exit 2 ;;
esac
target="`$(readlink -m -- "`$target")"
case "`$target" in
    /mnt|/mnt/*) echo 'No se permiten rutas bajo /mnt/. Usá el sistema de archivos Linux de WSL, por ejemplo ~/proyectos/nomina.' >&2; exit 3 ;;
esac
printf '%s' "`$target"
"@
$projectPath = Get-WslCommandOutput $resolvePathCommand 'No se pudo validar la ruta Linux del proyecto'
$project = ConvertTo-BashLiteral $projectPath
$repo = ConvertTo-BashLiteral $RepoUrl

Write-Host 'Validando Git, Docker y Docker Compose dentro de WSL...' -ForegroundColor Cyan
$toolCheck = @'
set -euo pipefail
command -v git >/dev/null || { echo 'Falta git en Ubuntu. Instalalo con: sudo apt update && sudo apt install -y git' >&2; exit 10; }
command -v docker >/dev/null || { echo 'Falta docker dentro de WSL. Instalá Docker Desktop y habilitá Settings > Resources > WSL Integration para esta distribución.' >&2; exit 11; }
docker compose version >/dev/null 2>&1 || { echo 'No está disponible "docker compose". Actualizá Docker Desktop y habilitá su integración con WSL.' >&2; exit 12; }
docker info >/dev/null 2>&1 || { echo 'Docker no está accesible. Iniciá Docker Desktop y habilitá la integración WSL para esta distribución.' >&2; exit 13; }
'@
Invoke-WslCommand $toolCheck 'Falló la validación de herramientas dentro de WSL'

Write-Host "Preparando el repositorio en $projectPath..." -ForegroundColor Cyan
$prepareRepository = @"
set -euo pipefail
project=$project
repo=$repo
if [ ! -e "`$project" ]; then
    mkdir -p -- "`$(dirname -- "`$project")"
    git clone -- "`$repo" "`$project"
elif [ ! -d "`$project/.git" ]; then
    echo "El destino existe pero no es un repositorio Git: `$project" >&2
    exit 20
fi
cd -- "`$project"
if [ ! -f docker-compose.yml ]; then
    echo 'El repositorio no contiene docker-compose.yml.' >&2
    exit 21
fi
"@
Invoke-WslCommand $prepareRepository 'No se pudo preparar el repositorio'

if ($Update) {
    Write-Host 'Actualizando el repositorio con fast-forward únicamente...' -ForegroundColor Cyan
    $updateRepository = @"
set -euo pipefail
cd -- $project
git fetch --prune origin
git pull --ff-only
"@
    Invoke-WslCommand $updateRepository 'No se pudo actualizar el repositorio; revisá cambios locales, rama y upstream'
}

$prepareEnvironment = @"
set -euo pipefail
cd -- $project
if [ ! -f .env ]; then
    if [ ! -f .env.docker.example ]; then
        echo 'Falta .env.docker.example; no se puede crear la configuración Docker.' >&2
        exit 30
    fi
    cp -- .env.docker.example .env
    echo 'Se creó .env desde .env.docker.example.'
else
    echo 'Se conserva el archivo .env existente.'
fi
"@
Invoke-WslCommand $prepareEnvironment 'No se pudo preparar el archivo .env'

Write-Host 'Construyendo e iniciando PostgreSQL y la aplicación...' -ForegroundColor Cyan
Invoke-WslCommand "set -euo pipefail; cd -- $project; docker compose up -d --build db app" 'No se pudieron iniciar los servicios db y app'

$appKeyPresent = Get-WslCommandOutput "set -euo pipefail; cd -- $project; if grep -Eq '^APP_KEY=.+$' .env; then printf true; else printf false; fi" 'No se pudo revisar APP_KEY'
if ($appKeyPresent -ne 'true') {
    Write-Host 'Generando APP_KEY y recreando la aplicación para cargarla...' -ForegroundColor Cyan
    Invoke-WslCommand "set -euo pipefail; cd -- $project; docker compose exec -T app php artisan key:generate --force; docker compose up -d --force-recreate app" 'No se pudo generar APP_KEY o recrear app'
}
else {
    Write-Host 'APP_KEY ya existe; no se modifica.'
}

Write-Host 'Esperando que PostgreSQL acepte conexiones...' -ForegroundColor Cyan
$waitForDatabase = @"
set -euo pipefail
cd -- $project
for attempt in `$(seq 1 30); do
    if docker compose exec -T db pg_isready -U nomina -d nomina >/dev/null 2>&1; then
        echo 'PostgreSQL está listo.'
        exit 0
    fi
    sleep 2
done
echo 'PostgreSQL no quedó listo después de 60 segundos. Revisá: docker compose logs db' >&2
exit 40
"@
Invoke-WslCommand $waitForDatabase 'La base de datos no quedó disponible'

if ($ResetDatabase) {
    Write-Warning 'Esto eliminará todas las tablas y datos de la base de datos de Nómina.'
    $confirmation = Read-Host 'Escribí RESETEAR para confirmar migrate:fresh'
    if ($confirmation -cne 'RESETEAR') {
        throw 'Reset cancelado: la confirmación no coincide exactamente con RESETEAR.'
    }
    Write-Host 'Ejecutando reset destructivo confirmado...' -ForegroundColor Yellow
    Invoke-WslCommand "set -euo pipefail; cd -- $project; docker compose exec -T app php artisan migrate:fresh --force" 'Falló migrate:fresh'
}
else {
    Write-Host 'Ejecutando migraciones no destructivas...' -ForegroundColor Cyan
    Invoke-WslCommand "set -euo pipefail; cd -- $project; docker compose exec -T app php artisan migrate --force" 'Fallaron las migraciones'
}

if ($InstallMode -eq 'Demo') {
    Write-Host 'Cargando datos normales de demostración...' -ForegroundColor Cyan
    Invoke-WslCommand "set -euo pipefail; cd -- $project; docker compose exec -T app php artisan db:seed --force" 'Falló la carga de datos de demostración'
}
else {
    Write-Host 'Configurando permisos, roles y el super admin sin datos de demostración...' -ForegroundColor Cyan
    Invoke-WslCommand "set -euo pipefail; cd -- $project; docker compose exec -T app php artisan db:seed --class=PermissionRoleSeeder --force" 'Falló la carga de permisos y roles'

    $passwordWasProvided = $PSBoundParameters.ContainsKey('AdminPassword')
    if (-not $passwordWasProvided) {
        $AdminPassword = Read-Host 'Contraseña del super admin' -AsSecureString
    }

    $passwordPointer = [IntPtr]::Zero
    $plainPassword = $null
    $passwordBase64 = $null
    try {
        $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($AdminPassword)
        $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
        if ([string]::IsNullOrWhiteSpace($plainPassword)) {
            throw 'La contraseña del super admin no puede estar vacía.'
        }
        $passwordBase64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($plainPassword))

        $adminEmailLiteral = ConvertTo-BashLiteral $AdminEmail
        $adminNameLiteral = ConvertTo-BashLiteral $AdminName
        $createAdmin = @"
set -euo pipefail
cd -- $project
IFS= read -r NOMINA_ADMIN_PASSWORD_BASE64
if ! NOMINA_ADMIN_PASSWORD="`$(printf '%s' "`$NOMINA_ADMIN_PASSWORD_BASE64" | tr -d '\r' | base64 --decode)"; then
    unset NOMINA_ADMIN_PASSWORD_BASE64 NOMINA_ADMIN_PASSWORD
    echo 'No se pudo decodificar la contraseña del super admin recibida desde PowerShell.' >&2
    exit 50
fi
unset NOMINA_ADMIN_PASSWORD_BASE64
export NOMINA_ADMIN_PASSWORD
export NOMINA_ADMIN_EMAIL=$adminEmailLiteral
export NOMINA_ADMIN_NAME=$adminNameLiteral
docker compose exec -T -e NOMINA_ADMIN_PASSWORD -e NOMINA_ADMIN_EMAIL -e NOMINA_ADMIN_NAME app php artisan tinker --execute='`$user = App\Models\User::updateOrCreate(["email" => getenv("NOMINA_ADMIN_EMAIL")], ["name" => getenv("NOMINA_ADMIN_NAME"), "password" => Illuminate\Support\Facades\Hash::make(getenv("NOMINA_ADMIN_PASSWORD")), "company_id" => null, "is_active" => true]); `$user->syncRoles("super_admin");'
"@
        Invoke-WslCommand $createAdmin 'No se pudo crear o actualizar el super admin' -StandardInput $passwordBase64 -WithStandardInput
    }
    finally {
        $passwordBase64 = $null
        $plainPassword = $null
        if ($passwordPointer -ne [IntPtr]::Zero) {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
        }
    }
}

if ($Worker) {
    Write-Host 'Iniciando el worker de colas...' -ForegroundColor Cyan
    Invoke-WslCommand "set -euo pipefail; cd -- $project; docker compose --profile worker up -d worker" 'No se pudo iniciar el worker'
}

Write-Host 'Estado final de los servicios:' -ForegroundColor Green
$statusCommand = if ($Worker) {
    "set -euo pipefail; cd -- $project; docker compose --profile worker ps"
}
else {
    "set -euo pipefail; cd -- $project; docker compose ps"
}
Invoke-WslCommand $statusCommand 'No se pudo obtener el estado de los servicios'

Write-Host ''
Write-Host 'Instalación completada. Abrí http://localhost:8000/login' -ForegroundColor Green
