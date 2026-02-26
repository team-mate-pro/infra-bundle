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
}
