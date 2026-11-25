param(
    [switch]$Migrate
)

$ErrorActionPreference = 'Stop'

if ($Migrate) {
    php scripts/migrate.php migrate
}

php scripts/migrate.php status
php scripts/test.php
php scripts/test-smtp.php
