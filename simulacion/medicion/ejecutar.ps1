# Runs the full load test against the simulated stack. ASCII only: Windows PowerShell 5.1 reads this file as ANSI.
# Resets only simulated data (empresa dbo.Partidas and industria_atunera_sim). The real database is only read.
# Usage: powershell -File simulacion/medicion/ejecutar.ps1
$ErrorActionPreference = 'Continue'
$raiz = (Resolve-Path "$PSScriptRoot\..\..").Path
Set-Location $raiz
$compose = '.devcontainer/compose.yaml'
$salida = "$PSScriptRoot\resultados.jsonl"
$envs = @{}
Get-Content .devcontainer\.env | Where-Object { $_ -match '^\s*([A-Z_]+)=(.*)$' } | ForEach-Object { $envs[$Matches[1]] = $Matches[2].Trim() }

function Registrar($texto) { "[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $texto }
# Plain function on purpose: with [Parameter()] PowerShell takes -d as its own -Debug and "up" stays attached forever.
function Dc { & docker compose -f $compose @args 2>&1 | ForEach-Object { "$_" } }
function Root($q) { & docker exec -e MYSQL_PWD=root123 devcontainer-db-1 mysql -uroot -N --default-character-set=utf8mb4 -e $q 2>$null }
function Sa($q) { & docker exec -e "SQLCMDPASSWORD=$($envs.EMPRESA_SA_PASSWORD)" devcontainer-empresa-1 /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -d planta_empresa -N -C -W -h-1 -s "|" -Q "SET NOCOUNT ON; $q" 2>$null }

function Reiniciar-Copia { Root "TRUNCATE TABLE industria_atunera_sim.predicciones; DELETE FROM industria_atunera_sim.sync_estado;" | Out-Null }

function Sincronizar-Medido {
  Sa "DBCC FREEPROCCACHE WITH NO_INFOMSGS;" | Out-Null
  Dc run --rm sincronizador ahora | Out-Null
  $f = (Root "SELECT estado, lotes, leidas, insertadas, actualizadas, sin_cambios, segundos FROM industria_atunera_sim.sync_log ORDER BY id DESC LIMIT 1") -split "`t"
  $d = ((Sa "SELECT SUM(qs.execution_count), SUM(qs.total_worker_time)/1000, SUM(qs.total_elapsed_time)/1000, SUM(qs.total_logical_reads), MAX(qs.max_elapsed_time)/1000 FROM sys.dm_exec_query_stats qs CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) st WHERE st.text LIKE '%integracion.vw_%' AND st.text NOT LIKE '%dm_exec_query_stats%';") -join '') -split '\|'
  [ordered]@{ estado=$f[0]; lotes=[int]$f[1]; leidas=[int]$f[2]; nuevas=[int]$f[3]; actualizadas=[int]$f[4]; iguales=[int]$f[5]; segundos=[double]$f[6];
              empresa_consultas=[int]$d[0]; empresa_cpu_ms=[int]$d[1]; empresa_ms=[int]$d[2]; empresa_lecturas=[long]$d[3]; empresa_consulta_max_ms=[int]$d[4] }
}

function Medir-App($url, $compra, $reps, $bandera) {
  Push-Location $PSScriptRoot
  $a = @('medir-app.mjs', $url, $compra, "$reps")
  if ($bandera) { $a += $bandera }
  $texto = (& node @a 2>$null | Select-Object -Last 1)
  Pop-Location
  if ($texto) { $texto | ConvertFrom-Json } else { [ordered]@{ error = 'medir-app sin salida' } }
}

function Guardar($obj) { ($obj | ConvertTo-Json -Depth 8 -Compress) | Add-Content -Encoding utf8 $salida }

$escenarios = @(
  @{ nombre='1 ano';            args=@('--desde=2025-01-01','--hasta=2025-12-31'); reps=3 },
  @{ nombre='5 anos esperado';  args=@();                                          reps=3 },
  @{ nombre='5 anos al maximo'; args=@('--min=20','--max=20');                     reps=3 },
  @{ nombre='50 mil';           args=@('--total=50000');                           reps=3 },
  @{ nombre='100 mil';          args=@('--total=100000');                          reps=1 },
  @{ nombre='500 mil';          args=@('--total=500000');                          reps=1 }
)

if (Test-Path $salida) { Remove-Item $salida }
Registrar 'Preparando'
Dc stop sincronizador | Out-Null
Dc --profile herramientas up -d --build app-sim app-sim-1g | Out-Null
Start-Sleep -Seconds 5

Registrar 'Linea base: app real, solo lectura'
$compraReal = Root "SELECT compra FROM industria_atunera.predicciones ORDER BY id DESC LIMIT 1"
$checksumAntes = (Root "CHECKSUM TABLE industria_atunera.predicciones") -join ' '
Guardar ([ordered]@{ escenario='Linea base (app real)'; filas=[int](Root "SELECT COUNT(*) FROM industria_atunera.predicciones"); app=(Medir-App 'http://localhost:8080' $compraReal 3 $null) })

foreach ($e in $escenarios) {
  Registrar "=== $($e.nombre): generando"
  $t = Measure-Command { Dc run --rm simulador historico --vaciar @($e.args) | Out-Null }
  $filas = [int]((Sa "SELECT COUNT(*) FROM dbo.Partidas;") -join '')
  $tamEmpresa = [int]((Sa "SELECT SUM(used_page_count)*8/1024 FROM sys.dm_db_partition_stats WHERE object_id = OBJECT_ID('dbo.Partidas');") -join '')
  Registrar "$filas filas generadas en $([int]$t.TotalSeconds) s. Sincronizando"

  Reiniciar-Copia
  $inicial = Sincronizar-Medido
  Registrar "Carga inicial: $($inicial.segundos) s, $($inicial.nuevas) nuevas"

  $ciclos = @()
  foreach ($i in 1..3) {
    Dc run --rm simulador nuevos --cantidad=10 --correcciones=2 | Out-Null
    $ciclos += Sincronizar-Medido
  }
  # Stats expiry 0: information_schema caches table sizes for 24 h by default.
  $tamLocal = [int](Root "SET SESSION information_schema_stats_expiry=0; SELECT ROUND(SUM(data_length+index_length)/1048576) FROM information_schema.TABLES WHERE TABLE_SCHEMA='industria_atunera_sim' AND TABLE_NAME='predicciones'")
  $compra = Root "SELECT compra FROM industria_atunera_sim.predicciones ORDER BY id DESC LIMIT 1"

  Registrar 'Midiendo app-sim (128 MB)'
  $app = Medir-App 'http://localhost:8081' $compra $e.reps $null
  $app1g = $null
  if (-not $app.api.leer_valido) {
    Registrar 'leer.php fallo: midiendo app-sim-1g (1 GB)'
    Dc --profile herramientas restart app-sim-1g | Out-Null
    Start-Sleep -Seconds 3
    $app1g = Medir-App 'http://localhost:8082' $compra $e.reps '--sin-concurrencia'
  }
  Guardar ([ordered]@{ escenario=$e.nombre; filas=$filas; generar_s=[int]$t.TotalSeconds; empresa_mb=$tamEmpresa; local_mb=$tamLocal;
                       carga_inicial=$inicial; ciclos=$ciclos; app=$app; app_1g=$app1g })
  Registrar "$($e.nombre): listo"
}

Registrar 'Restaurando: historico 2021-2025'
Dc run --rm simulador historico --vaciar | Out-Null
Reiniciar-Copia
$final = Sincronizar-Medido
Dc --profile herramientas stop app-sim-1g | Out-Null
Dc up -d sincronizador | Out-Null

$checksumDespues = (Root "CHECKSUM TABLE industria_atunera.predicciones") -join ' '
Guardar ([ordered]@{ escenario='Cierre'; restauradas=$final.nuevas; base_real_intacta=($checksumAntes -eq $checksumDespues); checksum=$checksumDespues })
Registrar "Terminado. Base real intacta: $($checksumAntes -eq $checksumDespues)"
