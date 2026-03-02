<?php

declare(strict_types=1);

namespace TeamMatePro\InfraBundle\Command;

use PDO;
use PDOException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

abstract class AbstractInfraVerifyCommand extends Command
{
    protected SymfonyStyle $io;
    private int $errorCount = 0;
    private ?KernelInterface $kernel = null;

    #[Required]
    public function setKernel(KernelInterface $kernel): void
    {
        $this->kernel = $kernel;
    }

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->errorCount = 0;

        $this->io->title('Infrastructure Verification');

        $this->verify();

        $this->io->newLine();

        if ($this->errorCount > 0) {
            $this->io->error(sprintf('Verification failed with %d error(s)', $this->errorCount));
            return Command::FAILURE;
        }

        $this->io->success('All infrastructure checks passed');
        return Command::SUCCESS;
    }

    abstract protected function verify(): void;

    protected function section(string $title): void
    {
        $this->io->section($title);
    }

    protected function verifyEnvVariable(string $name, ?string $description = null, ?string $expectedValue = null): void
    {
        $label = $description !== null ? sprintf('%s (%s)', $name, $description) : $name;

        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if ($value === false || $value === '') {
            $this->io->error(sprintf('[FAIL] %s - not set or empty', $label));
            $this->errorCount++;
            return;
        }

        if ($expectedValue !== null && $value !== $expectedValue) {
            $this->io->error(sprintf('[FAIL] %s - expected "%s", got "%s"', $label, $expectedValue, $value));
            $this->errorCount++;
            return;
        }

        $this->io->writeln(sprintf('<info>[OK]</info> %s', $label));
    }

    protected function verifyPhpExtension(string $name, ?string $description = null): void
    {
        $label = $description !== null ? sprintf('ext-%s (%s)', $name, $description) : sprintf('ext-%s', $name);

        if (!extension_loaded($name)) {
            $this->io->error(sprintf('[FAIL] %s - not loaded', $label));
            $this->errorCount++;
            return;
        }

        $this->io->writeln(sprintf('<info>[OK]</info> %s', $label));
    }

    /**
     * @param list<string> $command
     */
    protected function verifyBinary(array $command, ?string $description = null): void
    {
        $label = $description !== null ? sprintf('%s (%s)', $command[0], $description) : $command[0];

        $process = new Process($command);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->io->error(sprintf('[FAIL] %s - not available', $label));
            $this->errorCount++;
            return;
        }

        $this->io->writeln(sprintf('<info>[OK]</info> %s', $label));
    }

    protected function verifyDatabaseConnection(int $timeoutSeconds = 5): void
    {
        $label = 'MySQL connection';

        if (!extension_loaded('pdo')) {
            $this->io->writeln(sprintf('<comment>[WARN]</comment> %s - ext-pdo not loaded, skipping check', $label));
            return;
        }

        $databaseUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');
        if ($databaseUrl === false || $databaseUrl === '') {
            $this->io->error(sprintf('[FAIL] %s - DATABASE_URL not set', $label));
            $this->errorCount++;
            return;
        }

        try {
            $params = parse_url($databaseUrl);
            if ($params === false || !isset($params['host'], $params['path'])) {
                throw new PDOException('Invalid DATABASE_URL format');
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s',
                $params['host'],
                $params['port'] ?? 3306,
                ltrim($params['path'], '/'),
            );

            $pdo = new PDO(
                $dsn,
                $params['user'] ?? '',
                $params['pass'] ?? '',
                [
                    PDO::ATTR_TIMEOUT => $timeoutSeconds,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ],
            );

            $pdo->query('SELECT 1');
            $this->io->writeln(sprintf('<info>[OK]</info> %s', $label));
        } catch (PDOException $e) {
            $this->io->error(sprintf('[FAIL] %s - %s', $label, $e->getMessage()));
            $this->errorCount++;
        }
    }

    protected function verifyHttpConnection(
        string $url,
        ?string $description = null,
        int $expectedStatusCode = 200,
        int $timeoutSeconds = 5,
    ): void {
        $label = $description !== null ? sprintf('%s (%s)', $url, $description) : $url;

        try {
            $client = HttpClient::create([
                'timeout' => $timeoutSeconds,
                'verify_peer' => false,
                'verify_host' => false,
            ]);

            $response = $client->request('GET', $url);
            $statusCode = $response->getStatusCode();

            if ($statusCode !== $expectedStatusCode) {
                $this->io->error(sprintf(
                    '[FAIL] %s - expected status %d, got %d',
                    $label,
                    $expectedStatusCode,
                    $statusCode,
                ));
                $this->errorCount++;
                return;
            }

            $this->io->writeln(sprintf('<info>[OK]</info> %s', $label));
        } catch (Throwable $e) {
            $this->io->error(sprintf('[FAIL] %s - %s', $label, $e->getMessage()));
            $this->errorCount++;
        }
    }

    protected function verifyHttpConnectionHandshake(
        string $url,
        ?string $description = null,
        int $timeoutSeconds = 5,
    ): void {
        $label = $description !== null ? sprintf('%s (%s)', $url, $description) : $url;

        try {
            $client = HttpClient::create([
                'timeout' => $timeoutSeconds,
                'verify_peer' => false,
                'verify_host' => false,
            ]);

            $response = $client->request('GET', $url);
            $response->getStatusCode();

            $this->io->writeln(sprintf('<info>[OK]</info> %s', $label));
        } catch (Throwable $e) {
            $this->io->error(sprintf('[FAIL] %s - %s', $label, $e->getMessage()));
            $this->errorCount++;
        }
    }

    protected function verifyMailerConnection(string $recipientEmail): void
    {
        $label = 'SMTP connection (mailer:test)';

        if ($this->kernel === null) {
            $this->io->writeln(sprintf('<comment>[SKIP]</comment> %s - Kernel not available', $label));
            return;
        }

        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput([
            'command' => 'mailer:test',
            'to' => $recipientEmail,
        ]);

        $output = new BufferedOutput();
        $exitCode = $application->run($input, $output);

        if ($exitCode !== 0) {
            $error = $output->fetch() ?: 'Connection failed';
            $this->io->error(sprintf('[FAIL] %s - %s', $label, $error));
            $this->errorCount++;
            return;
        }

        $this->io->writeln(sprintf('<info>[OK]</info> %s', $label));
    }

    /**
     * @param class-string $expectedClass
     */
    protected function verifyServiceIsInstanceOf(string $serviceId, string $expectedClass, ?string $description = null): void
    {
        $label = $description !== null ? sprintf('%s (%s)', $serviceId, $description) : $serviceId;

        $service = $this->getServiceFromContainer($serviceId, $label);
        if ($service === false) {
            return;
        }

        if (!$service instanceof $expectedClass) {
            $this->io->error(sprintf(
                '[FAIL] %s - expected instance of %s, got %s',
                $label,
                $expectedClass,
                get_debug_type($service),
            ));
            $this->errorCount++;
            return;
        }

        $this->io->writeln(sprintf('<info>[OK]</info> %s', $label));
    }

    protected function verifyServiceHasValue(string $serviceId, mixed $expectedValue, ?string $description = null): void
    {
        $label = $description !== null ? sprintf('%s (%s)', $serviceId, $description) : $serviceId;

        $service = $this->getServiceFromContainer($serviceId, $label);
        if ($service === false) {
            return;
        }

        if ($service !== $expectedValue) {
            $this->io->error(sprintf(
                '[FAIL] %s - expected %s, got %s',
                $label,
                $this->formatValue($expectedValue),
                $this->formatValue($service),
            ));
            $this->errorCount++;
            return;
        }

        $this->io->writeln(sprintf('<info>[OK]</info> %s', $label));
    }

    /**
     * @return mixed The service instance, or false if retrieval failed (error already logged)
     */
    private function getServiceFromContainer(string $serviceId, string $label): mixed
    {
        if ($this->kernel === null) {
            $this->io->writeln(sprintf('<comment>[SKIP]</comment> %s - Kernel not available', $label));
            return false;
        }

        $container = $this->kernel->getContainer();

        if (!$container->has($serviceId)) {
            $this->io->error(sprintf('[FAIL] %s - service not found in container', $label));
            $this->errorCount++;
            return false;
        }

        try {
            return $container->get($serviceId);
        } catch (Throwable $e) {
            $this->io->error(sprintf('[FAIL] %s - %s', $label, $e->getMessage()));
            $this->errorCount++;
            return false;
        }
    }

    private function formatValue(mixed $value): string
    {
        if (is_object($value)) {
            return sprintf('object(%s)', get_class($value));
        }

        if (is_array($value)) {
            return 'array';
        }

        if (is_string($value)) {
            return sprintf('"%s"', $value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return (string) $value;
    }

    /**
     * Verifies JWT configuration for Lexik JWT Authentication Bundle.
     *
     * Checks:
     * - JWT_SECRET_KEY, JWT_PUBLIC_KEY, JWT_PASSPHRASE environment variables
     * - Key files exist and are readable
     * - Key files have valid PEM format
     * - OpenSSL can load the keys with provided passphrase
     *
     * @param string $projectDir The project directory to resolve %kernel.project_dir% placeholder
     */
    protected function verifyJwtLexikBundleKeys(string $projectDir): void
    {
        $this->section('JWT Configuration (Lexik Bundle)');

        // Get key paths from environment
        $secretKeyPath = $_ENV['JWT_SECRET_KEY'] ?? $_SERVER['JWT_SECRET_KEY'] ?? getenv('JWT_SECRET_KEY') ?: null;
        $publicKeyPath = $_ENV['JWT_PUBLIC_KEY'] ?? $_SERVER['JWT_PUBLIC_KEY'] ?? getenv('JWT_PUBLIC_KEY') ?: null;
        $passphrase = $_ENV['JWT_PASSPHRASE'] ?? $_SERVER['JWT_PASSPHRASE'] ?? getenv('JWT_PASSPHRASE') ?: null;

        // Resolve Symfony parameter placeholder
        if ($secretKeyPath) {
            $secretKeyPath = str_replace('%kernel.project_dir%', $projectDir, $secretKeyPath);
        }
        if ($publicKeyPath) {
            $publicKeyPath = str_replace('%kernel.project_dir%', $projectDir, $publicKeyPath);
        }

        // Verify private key file
        if (!$secretKeyPath) {
            $this->io->error('[FAIL] JWT_SECRET_KEY environment variable is not set');
            $this->errorCount++;
        } elseif (!file_exists($secretKeyPath)) {
            $this->io->error(sprintf('[FAIL] JWT private key file does not exist: %s', $secretKeyPath));
            $this->errorCount++;
        } elseif (!is_readable($secretKeyPath)) {
            $this->io->error(sprintf('[FAIL] JWT private key file is not readable: %s', $secretKeyPath));
            $this->errorCount++;
        } else {
            $content = file_get_contents($secretKeyPath);
            if ($content === false || !str_contains($content, '-----BEGIN')) {
                $this->io->error(sprintf('[FAIL] JWT private key file has invalid format: %s', $secretKeyPath));
                $this->errorCount++;
            } else {
                $this->io->writeln(sprintf('<info>[OK]</info> JWT private key file: %s', $secretKeyPath));
            }
        }

        // Verify public key file
        if (!$publicKeyPath) {
            $this->io->error('[FAIL] JWT_PUBLIC_KEY environment variable is not set');
            $this->errorCount++;
        } elseif (!file_exists($publicKeyPath)) {
            $this->io->error(sprintf('[FAIL] JWT public key file does not exist: %s', $publicKeyPath));
            $this->errorCount++;
        } elseif (!is_readable($publicKeyPath)) {
            $this->io->error(sprintf('[FAIL] JWT public key file is not readable: %s', $publicKeyPath));
            $this->errorCount++;
        } else {
            $content = file_get_contents($publicKeyPath);
            if ($content === false || !str_contains($content, '-----BEGIN')) {
                $this->io->error(sprintf('[FAIL] JWT public key file has invalid format: %s', $publicKeyPath));
                $this->errorCount++;
            } else {
                $this->io->writeln(sprintf('<info>[OK]</info> JWT public key file: %s', $publicKeyPath));
            }
        }

        // Verify passphrase is set
        if (!$passphrase) {
            $this->io->error('[FAIL] JWT_PASSPHRASE environment variable is not set');
            $this->errorCount++;
        } else {
            $this->io->writeln('<info>[OK]</info> JWT_PASSPHRASE is configured');
        }

        // Verify OpenSSL can load the private key with passphrase
        if ($secretKeyPath && file_exists($secretKeyPath) && is_readable($secretKeyPath) && $passphrase) {
            $privateKeyContent = file_get_contents($secretKeyPath);
            if ($privateKeyContent !== false) {
                $privateKey = openssl_pkey_get_private($privateKeyContent, $passphrase);
                if ($privateKey === false) {
                    $this->io->error('[FAIL] Cannot load JWT private key with provided passphrase (OpenSSL error: ' . openssl_error_string() . ')');
                    $this->errorCount++;
                } else {
                    $this->io->writeln('<info>[OK]</info> JWT private key is valid and passphrase is correct');
                }
            }
        }

        // Verify OpenSSL can load the public key
        if ($publicKeyPath && file_exists($publicKeyPath) && is_readable($publicKeyPath)) {
            $publicKeyContent = file_get_contents($publicKeyPath);
            if ($publicKeyContent !== false) {
                $publicKey = openssl_pkey_get_public($publicKeyContent);
                if ($publicKey === false) {
                    $this->io->error('[FAIL] Cannot load JWT public key (OpenSSL error: ' . openssl_error_string() . ')');
                    $this->errorCount++;
                } else {
                    $this->io->writeln('<info>[OK]</info> JWT public key is valid');
                }
            }
        }
    }
}
