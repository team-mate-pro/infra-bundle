# TeamMatePro InfraBundle

**Version:** 1.0.0
**Type:** symfony-bundle

Infrastructure verification bundle for Symfony applications. Provides a base command for verifying server configuration before deployment.

## Installation

```bash
composer require team-mate-pro/infra-bundle:^1.0
```

## Configuration

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    TeamMatePro\InfraBundle\TeamMateProInfraBundle::class => ['all' => true],
];
```

## Usage

Create a verification command by extending `AbstractInfraVerifyCommand`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use TeamMatePro\InfraBundle\Command\AbstractInfraVerifyCommand;

#[AsCommand(
    name: 'app:infra:verify',
    description: 'Verify infrastructure configuration',
)]
final class InfraVerifyCommand extends AbstractInfraVerifyCommand
{
    protected function verify(): void
    {
        // Add your verification logic here
    }
}
```

Run the command:

```bash
php bin/console app:infra:verify
```

## Available Verifiers

### `section(string $title)`

Creates a visual section in the output.

```php
$this->section('PHP Extensions');
```

---

### `verifyPhpExtension(string $name, ?string $description = null)`

Checks if a PHP extension is loaded.

```php
$this->verifyPhpExtension(name: 'pdo_mysql');
$this->verifyPhpExtension(name: 'gd', description: 'image processing');
```

---

### `verifyEnvVariable(string $name, ?string $description = null, ?string $expectedValue = null)`

Checks if an environment variable is set. Optionally validates expected value.

```php
// Check if set
$this->verifyEnvVariable(name: 'APP_SECRET');

// Check with description
$this->verifyEnvVariable(name: 'DATABASE_URL', description: 'database connection');

// Check exact value
$this->verifyEnvVariable(
    name: 'APP_ENV',
    expectedValue: 'prod',
);

// Full example
$this->verifyEnvVariable(
    name: 'SSO_AUTH_URL',
    description: 'frontend redirects here for login',
    expectedValue: 'https://login.example.com/',
);
```

---

### `verifyBinary(array $command, ?string $description = null)`

Checks if a system binary is available and executable.

```php
$this->verifyBinary(
    command: ['/usr/bin/wkhtmltopdf', '--version'],
    description: 'PDF generation',
);

$this->verifyBinary(
    command: ['node', '--version'],
    description: 'Node.js runtime',
);
```

---

### `verifyDatabaseConnection(int $timeoutSeconds = 5)`

Verifies MySQL database connection using `DATABASE_URL` environment variable.

> **Note:** If `ext-pdo` is not loaded, shows `[WARN]` and skips the check instead of failing.

```php
$this->verifyDatabaseConnection();
$this->verifyDatabaseConnection(timeoutSeconds: 10);
```

---

### `verifyHttpConnection(string $url, ?string $description = null, int $expectedStatusCode = 200, int $timeoutSeconds = 5)`

Checks HTTP endpoint and validates response status code.

```php
$this->verifyHttpConnection(
    url: 'https://api.example.com/health',
    description: 'API health check',
);

$this->verifyHttpConnection(
    url: 'https://api.example.com/status',
    expectedStatusCode: 204,
    timeoutSeconds: 10,
);
```

---

### `verifyHttpConnectionHandshake(string $url, ?string $description = null, int $timeoutSeconds = 5)`

Checks if HTTP endpoint is reachable (any response is OK). Use this when you only need to verify network connectivity.

```php
$this->verifyHttpConnectionHandshake(
    url: 'https://login.example.com/oauth2/jwks',
    description: 'SSO JWKS endpoint',
);

$this->verifyHttpConnectionHandshake(
    url: 'https://external-api.example.com/',
    description: 'External API',
    timeoutSeconds: 10,
);
```

## Example Command

```php
<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use TeamMatePro\InfraBundle\Command\AbstractInfraVerifyCommand;

#[AsCommand(
    name: 'app:infra:verify',
    description: 'Verify infrastructure configuration',
)]
final class InfraVerifyCommand extends AbstractInfraVerifyCommand
{
    protected function verify(): void
    {
        $this->section('PHP Extensions');
        $this->verifyPhpExtension(name: 'pdo_mysql', description: 'database');
        $this->verifyPhpExtension(name: 'intl', description: 'internationalization');

        $this->section('Environment Variables');
        $this->verifyEnvVariable(name: 'APP_ENV', expectedValue: 'prod');
        $this->verifyEnvVariable(name: 'APP_SECRET');
        $this->verifyEnvVariable(name: 'DATABASE_URL');

        $this->section('System Binaries');
        $this->verifyBinary(
            command: ['/usr/bin/wkhtmltopdf', '--version'],
            description: 'PDF generation',
        );

        $this->section('Services');
        $this->verifyDatabaseConnection();

        $this->section('External Connections');
        $this->verifyHttpConnectionHandshake(
            url: 'https://api.example.com/',
            description: 'External API',
        );
    }
}
```

## Output Example

```
Infrastructure Verification
===========================

PHP Extensions
--------------
[OK] ext-pdo_mysql (database)
[OK] ext-intl (internationalization)

Environment Variables
---------------------
[OK] APP_ENV
[OK] APP_SECRET
[OK] DATABASE_URL

System Binaries
---------------
[OK] /usr/bin/wkhtmltopdf (PDF generation)

Services
--------
[OK] MySQL connection

External Connections
--------------------
[OK] https://api.example.com/ (External API)

 [OK] All infrastructure checks passed
```

## Requirements

- PHP 8.3+
- Symfony 7.0+
