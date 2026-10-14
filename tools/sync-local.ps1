<#
  Copie locale du travail pousse depuis la session cloud.

  Recupere origin et avance la branche locale en fast-forward uniquement :
  aucune modification locale n'est ecrasee, aucun merge n'est cree.

  Usage (depuis la racine du depot) :
    powershell -ExecutionPolicy Bypass -File tools\sync-local.ps1          # boucle toutes les 2 minutes
    powershell -ExecutionPolicy Bypass -File tools\sync-local.ps1 -Once    # une seule synchronisation
#>
param(
    [string]$Branch = 'claude/developpement-l0-3pkhne',
    [int]$IntervalSeconds = 120,
    [switch]$Once
)

Set-Location (Split-Path -Parent $PSScriptRoot)

function Sync-Once {
    git fetch origin --prune --quiet
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "git fetch a echoue (reseau ou authentification) ; nouvel essai au prochain cycle."
        return
    }

    git rev-parse --verify --quiet "origin/$Branch" | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "La branche distante origin/$Branch n'existe pas. Branches distantes :"
        git branch -r
        return
    }

    $current = git rev-parse --abbrev-ref HEAD
    if ($current -ne $Branch) {
        Write-Warning "Branche locale '$current' au lieu de '$Branch' : executer 'git switch $Branch'."
        return
    }

    $dirty = git status --porcelain --untracked-files=no
    if ($dirty) {
        Write-Warning "Modifications locales non validees : synchronisation suspendue pour ne rien ecraser."
        return
    }

    $local = git rev-parse HEAD
    $remote = git rev-parse "origin/$Branch"
    if ($local -eq $remote) { return }

    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Write-Host "[$stamp] Nouveaux commits recus :"
    git log --oneline "$local..$remote"
    git merge --ff-only --quiet "origin/$Branch"
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "Fast-forward impossible (historique local divergent) : verifier manuellement."
    }
}

do {
    Sync-Once
    if (-not $Once) { Start-Sleep -Seconds $IntervalSeconds }
} while (-not $Once)
