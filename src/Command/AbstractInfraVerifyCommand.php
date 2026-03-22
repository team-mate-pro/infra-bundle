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

    /**
     * Verifies that the current git branch matches one of the expected branches.
     *
     * @param string|string[] $expectedBranches Single branch name or array of allowed branch names
     * @param string|null $projectDir Project directory (defaults to current working directory)
     */
    protected function verifyBranch(string|array $expectedBranches, ?string $projectDir = null): void
    {
        $expectedBranches = is_array($expectedBranches) ? $expectedBranches : [$expectedBranches];
        $label = count($expectedBranches) === 1
            ? sprintf('Git branch (expected: %s)', $expectedBranches[0])
            : sprintf('Git branch (expected: %s)', implode(' or ', $expectedBranches));

        $currentBranch = $this->getCurrentGitBranch($projectDir);

        if ($currentBranch === null) {
            $this->io->error(sprintf('[FAIL] %s - could not determine current branch', $label));
            $this->errorCount++;
            return;
        }

        if (!in_array($currentBranch, $expectedBranches, true)) {
            $this->io->error(sprintf(
                '[FAIL] %s - current branch is "%s"',
                $label,
                $currentBranch
            ));
            $this->errorCount++;
            return;
        }

        $this->io->writeln(sprintf('<info>[OK]</info> Git branch: %s', $currentBranch));
    }

    /**
     * Gets the current git branch name.
     *
     * @param string|null $projectDir Project directory (defaults to current working directory)
     * @return string|null Branch name or null if not a git repository or git not available
     */
    protected function getCurrentGitBranch(?string $projectDir = null): ?string
    {
        $this->ensureGitSafeDirectory($projectDir);

        $command = ['git', 'rev-parse', '--abbrev-ref', 'HEAD'];

        $process = new Process($command, $projectDir);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $branch = trim($process->getOutput());

        // Handle detached HEAD state
        if ($branch === 'HEAD') {
            // Try to get branch from git describe
            $describeProcess = new Process(['git', 'describe', '--all', '--exact-match', 'HEAD'], $projectDir);
            $describeProcess->run();

            if ($describeProcess->isSuccessful()) {
                $describe = trim($describeProcess->getOutput());
                // Remove 'heads/' or 'remotes/origin/' prefix
                $branch = preg_replace('#^(heads/|remotes/[^/]+/)#', '', $describe) ?? $branch;
            }
        }

        return $branch !== '' ? $branch : null;
    }

    /**
     * Ensures the directory is added to git safe.directory config.
     *
     * This is needed when running git commands in directories owned by different users
     * (common in Docker containers where files are mounted from host).
     *
     * @param string|null $projectDir Project directory to add as safe
     */
    protected function ensureGitSafeDirectory(?string $projectDir = null): void
    {
        $directory = $projectDir ?? getcwd();
        if ($directory === false) {
            return;
        }

        // Resolve to absolute path
        $realPath = realpath($directory);
        if ($realPath === false) {
            return;
        }

        $process = new Process([
            'git', 'config', '--global', '--add', 'safe.directory', $realPath,
        ]);
        $process->run();
    }

    /**
     * Gets the latest git commit hash (short version).
     *
     * @param string|null $projectDir Project directory (defaults to current working directory)
     * @return string|null Commit hash or null if not available
     */
    protected function getCurrentGitCommit(?string $projectDir = null): ?string
    {
        $this->ensureGitSafeDirectory($projectDir);

        $process = new Process(['git', 'rev-parse', '--short', 'HEAD'], $projectDir);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $commit = trim($process->getOutput());

        return $commit !== '' ? $commit : null;
    }

    /**
     * Verifies git repository status - checks for uncommitted changes.
     *
     * @param string|null $projectDir Project directory (defaults to current working directory)
     * @param bool $allowUncommitted If false, fails when there are uncommitted changes
     */
    protected function verifyGitStatus(?string $projectDir = null, bool $allowUncommitted = false): void
    {
        $this->ensureGitSafeDirectory($projectDir);

        $process = new Process(['git', 'status', '--porcelain'], $projectDir);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->io->writeln('<comment>[SKIP]</comment> Git status - not a git repository or git not available');
            return;
        }

        $output = trim($process->getOutput());
        $hasChanges = $output !== '';

        if ($hasChanges && !$allowUncommitted) {
            $this->io->warning('[WARN] Git status - uncommitted changes detected');
            return;
        }

        $commit = $this->getCurrentGitCommit($projectDir);
        $commitInfo = $commit !== null ? sprintf(' (commit: %s)', $commit) : '';

        $this->io->writeln(sprintf(
            '<info>[OK]</info> Git status: %s%s',
            $hasChanges ? 'has uncommitted changes' : 'clean',
            $commitInfo
        ));
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

    /**
     * Verifies SMTP mailer connection by sending a test email.
     *
     * @param string $recipientEmail Email address to send test email to
     * @param string|null $from Optional sender email address (--from option for mailer:test)
     */
    protected function verifyMailerConnection(string $recipientEmail, ?string $from = null): void
    {
        $label = 'SMTP connection (mailer:test)';

        if ($this->kernel === null) {
            $this->io->writeln(sprintf('<comment>[SKIP]</comment> %s - Kernel not available', $label));
            return;
        }

        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $inputArgs = [
            'command' => 'mailer:test',
            'to' => $recipientEmail,
        ];

        if ($from !== null) {
            $inputArgs['--from'] = $from;
        }

        $input = new ArrayInput($inputArgs);

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
     * Verifies file or directory permissions.
     *
     * Checks:
     * - File/directory exists and is readable
     * - Permissions are not 777 (world-writable)
     * - For secret files: warns if world-readable (permissions ending with 4+)
     *
     * @param string $path Full path to file or directory
     * @param string|null $displayName Display name for output (defaults to basename)
     * @param bool $isSecretFile If true, warns about world-readable permissions
     * @param string|null $expectedOwner Expected owner name (optional)
     * @param string|null $expectedGroup Expected group name (optional)
     * @param string|null $expectedPermissions Expected permissions in octal (e.g., '640', '755')
     */
    protected function verifyFilePermission(
        string $path,
        ?string $displayName = null,
        bool $isSecretFile = false,
        ?string $expectedOwner = null,
        ?string $expectedGroup = null,
        ?string $expectedPermissions = null,
    ): void {
        $displayName ??= basename($path);

        if (!file_exists($path)) {
            $this->io->writeln(sprintf('<comment>[SKIP]</comment> %s does not exist', $displayName));
            return;
        }

        $owner = $this->getFileOwner($path);
        $group = $this->getFileGroup($path);

        if (!is_readable($path)) {
            $this->io->error(sprintf('[FAIL] %s is not readable', $displayName));
            $this->printPermissionSuggestion($path, $isSecretFile);
            $this->errorCount++;
            return;
        }

        $perms = fileperms($path);
        if ($perms === false) {
            $this->io->error(sprintf('[FAIL] %s - cannot read permissions', $displayName));
            $this->errorCount++;
            return;
        }

        $permsOctal = substr(sprintf('%o', $perms), -3);

        // Check expected permissions if specified
        if ($expectedPermissions !== null && $permsOctal !== $expectedPermissions) {
            $this->io->error(sprintf(
                '[FAIL] %s has permissions %s, expected %s [owner: %s:%s]',
                $displayName,
                $permsOctal,
                $expectedPermissions,
                $owner,
                $group
            ));
            $this->printPermissionSuggestion($path, $isSecretFile, $expectedPermissions);
            $this->errorCount++;
            return;
        }

        // Check expected owner if specified
        if ($expectedOwner !== null && $owner !== $expectedOwner) {
            $this->io->error(sprintf(
                '[FAIL] %s has owner %s, expected %s',
                $displayName,
                $owner,
                $expectedOwner
            ));
            $this->errorCount++;
            return;
        }

        // Check expected group if specified
        if ($expectedGroup !== null && $group !== $expectedGroup) {
            $this->io->error(sprintf(
                '[FAIL] %s has group %s, expected %s',
                $displayName,
                $group,
                $expectedGroup
            ));
            $this->errorCount++;
            return;
        }

        // Check for 777 (world-writable) - always risky
        if ($permsOctal === '777') {
            $this->io->warning(sprintf(
                '[WARN] %s has risky permissions %s (world-writable) [owner: %s:%s]',
                $displayName,
                $permsOctal,
                $owner,
                $group
            ));
            $this->printPermissionSuggestion($path, $isSecretFile);
            return;
        }

        // For secret files, warn if world-readable (last digit >= 4)
        if ($isSecretFile) {
            $worldPerms = (int) $permsOctal[2];
            if ($worldPerms >= 4) {
                $this->io->warning(sprintf(
                    '[WARN] %s has permissions %s (world-readable, contains secrets) [owner: %s:%s]',
                    $displayName,
                    $permsOctal,
                    $owner,
                    $group
                ));
                $this->printPermissionSuggestion($path, $isSecretFile);
                return;
            }
        }

        $this->io->writeln(sprintf(
            '<info>[OK]</info> %s permissions: %s [owner: %s:%s]',
            $displayName,
            $permsOctal,
            $owner,
            $group
        ));
    }

    /**
     * Verifies directory and optionally its subdirectories permissions.
     *
     * @param string $path Full path to directory
     * @param string|null $displayName Display name for output
     * @param string[] $subdirectories Subdirectory names to also verify
     * @param bool $isSecretDir If true, warns about world-readable permissions
     */
    protected function verifyDirectoryPermissions(
        string $path,
        ?string $displayName = null,
        array $subdirectories = [],
        bool $isSecretDir = false,
    ): void {
        $displayName ??= basename($path) . '/';

        if (!is_dir($path)) {
            $this->io->writeln(sprintf('<comment>[SKIP]</comment> %s does not exist', $displayName));
            return;
        }

        $this->verifyFilePermission($path, $displayName, $isSecretDir);

        foreach ($subdirectories as $subdir) {
            $subdirPath = $path . '/' . $subdir;
            if (is_dir($subdirPath)) {
                $this->verifyFilePermission(
                    $subdirPath,
                    $displayName . $subdir . '/',
                    $isSecretDir
                );
            }
        }
    }

    /**
     * Verifies permissions of files matching a glob pattern.
     *
     * @param string $pattern Glob pattern (e.g., '/path/to/.env*')
     * @param bool $isSecretFile If true, warns about world-readable permissions
     */
    protected function verifyFilesPermissionsByPattern(string $pattern, bool $isSecretFile = false): void
    {
        $files = glob($pattern) ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                $this->verifyFilePermission($file, basename($file), $isSecretFile);
            }
        }
    }

    private function printPermissionSuggestion(
        string $path,
        bool $isSecretFile,
        ?string $suggestedPermissions = null,
    ): void {
        $webUser = $this->getCurrentProcessUser();

        if ($isSecretFile) {
            $perms = $suggestedPermissions ?? '640';
            $this->io->writeln(sprintf(
                '         <comment>Fix: sudo chown %s:%s %s && sudo chmod %s %s</comment>',
                $webUser,
                $webUser,
                $path,
                $perms,
                $path
            ));
        } else {
            $perms = $suggestedPermissions ?? '755';
            $this->io->writeln(sprintf(
                '         <comment>Fix: sudo chown -R %s:%s %s && sudo chmod -R %s %s</comment>',
                $webUser,
                $webUser,
                $path,
                $perms,
                $path
            ));
        }
    }

    /**
     * Gets the file owner name or numeric ID if name cannot be resolved.
     */
    protected function getFileOwner(string $path): string
    {
        $ownerId = fileowner($path);
        if ($ownerId === false) {
            return '?';
        }

        if (function_exists('posix_getpwuid')) {
            $ownerInfo = posix_getpwuid($ownerId);
            if ($ownerInfo !== false) {
                return $ownerInfo['name'];
            }
        }

        // Fallback: use shell command
        $output = [];
        exec(sprintf('stat -c "%%U" %s 2>/dev/null', escapeshellarg($path)), $output);
        $statResult = $output[0] ?? null;

        // If stat returns UNKNOWN, use numeric ID
        if ($statResult !== null && $statResult !== 'UNKNOWN') {
            return $statResult;
        }

        return (string) $ownerId;
    }

    /**
     * Gets the file group name or numeric ID if name cannot be resolved.
     */
    protected function getFileGroup(string $path): string
    {
        $groupId = filegroup($path);
        if ($groupId === false) {
            return '?';
        }

        if (function_exists('posix_getgrgid')) {
            $groupInfo = posix_getgrgid($groupId);
            if ($groupInfo !== false) {
                return $groupInfo['name'];
            }
        }

        // Fallback: use shell command
        $output = [];
        exec(sprintf('stat -c "%%G" %s 2>/dev/null', escapeshellarg($path)), $output);
        $statResult = $output[0] ?? null;

        // If stat returns UNKNOWN, use numeric ID
        if ($statResult !== null && $statResult !== 'UNKNOWN') {
            return $statResult;
        }

        return (string) $groupId;
    }

    /**
     * Gets the current process user name.
     */
    protected function getCurrentProcessUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $processUser = posix_getpwuid(posix_geteuid());
            if ($processUser !== false) {
                return $processUser['name'];
            }
        }

        // Fallback: use whoami
        $output = [];
        exec('whoami 2>/dev/null', $output);

        return $output[0] ?? 'www-data';
    }

    /**
     * Verifies that a port is listening locally (incoming).
     *
     * @param int $port Port number to check
     * @param string $host Host to check (default: 127.0.0.1)
     * @param string|null $description Optional label for output
     */
    protected function verifyPortIn(int $port, string $host = '127.0.0.1', ?string $description = null): void
    {
        $label = $description ?? sprintf('Port %d incoming (%s:%d)', $port, $host, $port);
        $this->checkPort($host, $port, $label);
    }

    /**
     * Verifies that this server can reach an external host:port (outgoing).
     *
     * @param int $port Port number to check
     * @param string $host External host to reach (default: google.com)
     * @param string|null $description Optional label for output
     */
    protected function verifyPortOut(int $port, string $host = 'google.com', ?string $description = null): void
    {
        $label = $description ?? sprintf('Port %d outgoing (%s:%d)', $port, $host, $port);
        $this->checkPort($host, $port, $label);
    }

    private function checkPort(string $host, int $port, string $label): void
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 2);

        if ($socket !== false) {
            fclose($socket);
            $this->io->writeln(sprintf('<info>[OK]</info> %s — open', $label));
            return;
        }

        $this->io->error(sprintf('[FAIL] %s — closed (%s)', $label, $errstr ?: 'connection refused/timed out'));
        $this->errorCount++;
    }

    /**
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
