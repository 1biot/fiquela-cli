<?php

namespace Cli\Command;

use FQL\Cli\Command\QueryCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class QueryCommandTest extends TestCase
{
    private string $tempFile;
    private string $tempHome;
    private string|false $originalHome;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/fql-command-' . uniqid() . '.csv';
        file_put_contents($this->tempFile, "id;name\n1;Alice\n2;Bob\n");

        $this->tempHome = sys_get_temp_dir() . '/fql-home-' . uniqid();
        mkdir($this->tempHome, 0700, true);
        mkdir($this->tempHome . '/.fql', 0700, true);

        $this->originalHome = getenv('HOME');
        putenv('HOME=' . $this->tempHome);
    }

    private function createCommandTester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new QueryCommand());
        $command = $application->find('fiquela-cli');

        return new CommandTester($command);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }

        $authFile = $this->tempHome . '/.fql/auth.json';
        $sessionFile = $this->tempHome . '/.fql/sessions.json';
        if (file_exists($authFile)) {
            unlink($authFile);
        }
        if (file_exists($sessionFile)) {
            unlink($sessionFile);
        }
        if (is_dir($this->tempHome . '/.fql')) {
            rmdir($this->tempHome . '/.fql');
        }
        if (is_dir($this->tempHome)) {
            rmdir($this->tempHome);
        }

        if ($this->originalHome !== false) {
            putenv('HOME=' . $this->originalHome);
        }
    }

    public function testRunNonInteractiveLocalMode(): void
    {
        $command = new QueryCommand();
        $tester = new CommandTester($command);

        $code = $tester->execute([
            'query' => 'SELECT id, name FROM *;',
            '--file' => $this->tempFile,
            '--file-type' => 'csv',
            '--file-delimiter' => ';',
        ]);

        $this->assertSame(0, $code);
        $output = trim($tester->getDisplay());
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
    }

    public function testRunNonInteractiveFailsOnMissingFile(): void
    {
        $command = new QueryCommand();
        $tester = new CommandTester($command);

        $code = $tester->execute([
            'query' => 'SELECT id FROM *;',
            '--file' => '/no/such/file.csv',
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('File not found', $tester->getDisplay());
    }

    public function testApiModeFailsWhenAuthPermissionsInvalidAndMissingCliCredentials(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, json_encode([
            ['name' => 'staging', 'url' => 'https://api.example.com', 'user' => 'u', 'secret' => 's'],
        ]));
        chmod($authFile, 0644);

        $command = new QueryCommand();
        $tester = new CommandTester($command);

        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
        ]);

        $this->assertSame(1, $code);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('incorrect permissions', $display);
        $this->assertStringContainsString('must provide --user', $display);
    }

    public function testNonInteractiveMultipleQueries(): void
    {
        $command = new QueryCommand();
        $tester = new CommandTester($command);

        $code = $tester->execute([
            'query' => 'SELECT id FROM *; SELECT name FROM *;',
            '--file' => $this->tempFile,
            '--file-type' => 'csv',
            '--file-delimiter' => ';',
        ]);

        $this->assertSame(0, $code);
        $output = $tester->getDisplay();
        // Should contain output from both queries
        $this->assertStringContainsString('id', $output);
        $this->assertStringContainsString('name', $output);
    }

    public function testNonInteractiveWithMemoryLimit(): void
    {
        $command = new QueryCommand();
        $tester = new CommandTester($command);

        $code = $tester->execute([
            'query' => 'SELECT id, name FROM *;',
            '--file' => $this->tempFile,
            '--file-type' => 'csv',
            '--file-delimiter' => ';',
            '--memory-limit' => '256M',
        ]);

        $this->assertSame(0, $code);
    }

    public function testNonInteractiveWithoutFileOption(): void
    {
        $command = new QueryCommand();
        $tester = new CommandTester($command);

        // Use the FQL provider syntax with inline file reference
        $code = $tester->execute([
            'query' => sprintf('SELECT id, name FROM [csv](%s, utf-8, ";").*', $this->tempFile),
        ]);

        $this->assertSame(0, $code);
        $output = trim($tester->getDisplay());
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
    }

    public function testLocalModeWithDefaultDelimiterAndEncoding(): void
    {
        // Create comma-delimited CSV
        $commaFile = sys_get_temp_dir() . '/fql-comma-' . uniqid() . '.csv';
        file_put_contents($commaFile, "id,name\n1,Alice\n");

        try {
            $command = new QueryCommand();
            $tester = new CommandTester($command);

            $code = $tester->execute([
                'query' => 'SELECT id, name FROM *;',
                '--file' => $commaFile,
                '--file-type' => 'csv',
            ]);

            $this->assertSame(0, $code);
        } finally {
            unlink($commaFile);
        }
    }

    public function testApiModeWithSingleServerAutoSelects(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, json_encode([
            ['name' => 'prod', 'url' => 'http://127.0.0.1:1', 'user' => 'admin', 'secret' => 'pass'],
        ]));
        chmod($authFile, 0600);

        $command = new QueryCommand();
        $tester = new CommandTester($command);

        // Will fail at connection/authentication, but exercises the single-server auto-select branch
        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
        ]);

        $this->assertSame(1, $code);
    }

    public function testApiModeWithServerNameOption(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, json_encode([
            ['name' => 'prod', 'url' => 'http://127.0.0.1:1', 'user' => 'admin', 'secret' => 'pass'],
            ['name' => 'staging', 'url' => 'http://127.0.0.1:2', 'user' => 'dev', 'secret' => 'pass2'],
        ]));
        chmod($authFile, 0600);

        $command = new QueryCommand();
        $tester = new CommandTester($command);

        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
            '--server' => 'staging',
        ]);

        $this->assertSame(1, $code);
    }

    public function testApiModeServerNotFound(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, json_encode([
            ['name' => 'prod', 'url' => 'http://127.0.0.1:1', 'user' => 'admin', 'secret' => 'pass'],
        ]));
        chmod($authFile, 0600);

        $command = new QueryCommand();
        $tester = new CommandTester($command);

        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
            '--server' => 'nonexistent',
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not found in auth.json', $tester->getDisplay());
    }

    public function testApiModeNoAuthFileWithCliCredentials(): void
    {
        // Remove the auth.json we created in setUp
        $authFile = $this->tempHome . '/.fql/auth.json';
        if (file_exists($authFile)) {
            unlink($authFile);
        }

        $command = new QueryCommand();
        $tester = new CommandTester($command);

        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
            '--server' => 'test',
            '--user' => 'admin',
            '--password' => 'pass',
        ]);

        // Will fail at login/connection but exercises the CLI credentials branch
        $this->assertSame(1, $code);
    }

    public function testApiModeNoAuthFileAndNoCredentialsFails(): void
    {
        // Remove auth.json
        $authFile = $this->tempHome . '/.fql/auth.json';
        if (file_exists($authFile)) {
            unlink($authFile);
        }

        $command = new QueryCommand();
        $tester = new CommandTester($command);

        // No --server, --user, --password — should trigger interactive add server
        // CommandTester is not interactive, so the helper ask will return null
        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
        ]);

        $this->assertSame(1, $code);
    }

    public function testApiModeEmptyAuthFileFails(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, '[]');
        chmod($authFile, 0600);

        $command = new QueryCommand();
        $tester = new CommandTester($command);

        // Empty servers list — triggers interactive add server
        // CommandTester is non-interactive, ask returns null
        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
        ]);

        $this->assertSame(1, $code);
    }

    public function testApiModeWithInvalidPermissionsButFullCliCredentials(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, json_encode([
            ['name' => 'prod', 'url' => 'http://127.0.0.1:1', 'user' => 'u', 'secret' => 's'],
        ]));
        chmod($authFile, 0644);

        $command = new QueryCommand();
        $tester = new CommandTester($command);

        // Provide all CLI credentials, so it bypasses the auth.json permissions issue
        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
            '--server' => 'manual',
            '--user' => 'admin',
            '--password' => 'pass',
        ]);

        // Will fail at login/connection but exercises the "bad perms + CLI creds" branch
        $this->assertSame(1, $code);
    }

    public function testNonInteractiveQueryError(): void
    {
        $command = new QueryCommand();
        $tester = new CommandTester($command);

        // Query with invalid syntax that will throw
        $code = $tester->execute([
            'query' => 'THIS IS NOT VALID SQL;',
            '--file' => $this->tempFile,
            '--file-type' => 'csv',
            '--file-delimiter' => ';',
        ]);

        $this->assertSame(1, $code);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('error', $output);
    }

    public function testLocalModeWithEmptyFileOption(): void
    {
        $command = new QueryCommand();
        $tester = new CommandTester($command);

        // Use FQL provider syntax
        $code = $tester->execute([
            'query' => sprintf('SELECT id, name FROM [csv](%s, utf-8, ";").*', $this->tempFile),
            '--file' => '',
        ]);

        $this->assertSame(0, $code);
    }

    public function testApiModeMultipleServersInteractiveSelect(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, json_encode([
            ['name' => 'prod', 'url' => 'http://127.0.0.1:1', 'user' => 'admin', 'secret' => 'pass'],
            ['name' => 'staging', 'url' => 'http://127.0.0.1:2', 'user' => 'dev', 'secret' => 'pass2'],
        ]));
        chmod($authFile, 0600);

        $tester = $this->createCommandTester();
        $tester->setInputs(['prod']); // Select "prod" from interactive list

        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
        ]);

        // Will fail at connection but exercises the interactive server selection branch
        $this->assertSame(1, $code);
    }

    public function testApiModeInteractiveAddServer(): void
    {
        // Empty auth.json
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, '[]');
        chmod($authFile, 0600);

        $tester = $this->createCommandTester();
        $tester->setInputs(['myserver', 'http://127.0.0.1:1', 'admin', 'secret123']);

        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
        ]);

        // Will fail at connection but exercises the interactive add server branch
        $this->assertSame(1, $code);

        // Verify the server was saved
        $saved = json_decode((string) file_get_contents($authFile), true);
        $this->assertIsArray($saved);
        $this->assertCount(1, $saved);
        $this->assertEquals('myserver', $saved[0]['name']);
    }

    public function testApiModeInteractiveAddServerEmptyName(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, '[]');
        chmod($authFile, 0600);

        $tester = $this->createCommandTester();
        $tester->setInputs(['']); // Empty server name

        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Server name is required', $tester->getDisplay());
    }

    public function testApiModeInteractiveAddServerEmptyUrl(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, '[]');
        chmod($authFile, 0600);

        $tester = $this->createCommandTester();
        $tester->setInputs(['myserver', '']); // Valid name, empty URL

        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Server URL is required', $tester->getDisplay());
    }

    public function testApiModeInteractiveAddServerEmptyUsername(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, '[]');
        chmod($authFile, 0600);

        $tester = $this->createCommandTester();
        $tester->setInputs(['myserver', 'http://127.0.0.1:1', '']); // Valid name + URL, empty user

        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Username is required', $tester->getDisplay());
    }

    public function testApiModeInteractiveAddServerEmptyPassword(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, '[]');
        chmod($authFile, 0600);

        $tester = $this->createCommandTester();
        $tester->setInputs(['myserver', 'http://127.0.0.1:1', 'admin', '']); // Empty password

        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Password is required', $tester->getDisplay());
    }

    public function testApiModeWithExistingValidSession(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, json_encode([
            ['name' => 'prod', 'url' => 'http://127.0.0.1:1', 'user' => 'admin', 'secret' => 'pass'],
        ]));
        chmod($authFile, 0600);

        // Create a valid (non-expired) session token
        $header = base64_encode('{"alg":"HS256"}');
        $payload = base64_encode(json_encode(['exp' => time() + 3600]));
        $signature = base64_encode('sig');
        $jwt = "$header.$payload.$signature";

        $sessionsFile = $this->tempHome . '/.fql/sessions.json';
        $sessions = [
            'http://127.0.0.1:1' => ['token' => $jwt, 'expires_at' => time() + 3600],
        ];
        file_put_contents($sessionsFile, json_encode($sessions, JSON_PRETTY_PRINT) . "\n");
        chmod($sessionsFile, 0600);

        $command = new QueryCommand();
        $tester = new CommandTester($command);

        // With a valid session token, it should skip login and go directly to query
        // Will fail at the query execution (can't connect) but exercises the session token branch
        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
        ]);

        // The error output should be about the query failing, not authentication
        $this->assertSame(1, $code);
    }

    public function testApiModeNoAuthFileInteractiveAddServer(): void
    {
        // Remove auth.json entirely
        $authFile = $this->tempHome . '/.fql/auth.json';
        if (file_exists($authFile)) {
            unlink($authFile);
        }

        $tester = $this->createCommandTester();
        $tester->setInputs(['myserver', 'http://127.0.0.1:1', 'admin', 'secret123']);

        $code = $tester->execute([
            'query' => 'SELECT 1;',
            '--connect' => true,
        ]);

        $this->assertSame(1, $code);
    }

    // -------------------------------------------------------
    // Mode switching via handleConnectSwitch / handleLocalSwitch
    // (exercised indirectly through Repl callbacks)
    // -------------------------------------------------------

    public function testConnectSwitchNoAuthFile(): void
    {
        // Remove auth.json — handleConnectSwitch should fail
        $authFile = $this->tempHome . '/.fql/auth.json';
        if (file_exists($authFile)) {
            unlink($authFile);
        }

        $result = $this->invokeConnectSwitch(null);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No valid auth.json', $result->error ?? '');
    }

    public function testConnectSwitchEmptyServers(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, '[]');
        chmod($authFile, 0600);

        $result = $this->invokeConnectSwitch(null);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No servers configured', $result->error ?? '');
    }

    public function testConnectSwitchServerNotFound(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, json_encode([
            ['name' => 'prod', 'url' => 'http://127.0.0.1:1', 'user' => 'u', 'secret' => 's'],
            ['name' => 'staging', 'url' => 'http://127.0.0.1:2', 'user' => 'u', 'secret' => 's'],
        ]));
        chmod($authFile, 0600);

        $result = $this->invokeConnectSwitch('nonexistent');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->error ?? '');
        $this->assertStringContainsString('prod', $result->error ?? '');
        $this->assertStringContainsString('staging', $result->error ?? '');
    }

    public function testConnectSwitchMultipleServersNoName(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, json_encode([
            ['name' => 'prod', 'url' => 'http://127.0.0.1:1', 'user' => 'u', 'secret' => 's'],
            ['name' => 'staging', 'url' => 'http://127.0.0.1:2', 'user' => 'u', 'secret' => 's'],
        ]));
        chmod($authFile, 0600);

        $result = $this->invokeConnectSwitch(null);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Multiple servers configured', $result->error ?? '');
        $this->assertStringContainsString('prod', $result->error ?? '');
    }

    public function testConnectSwitchSingleServerAuthFails(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, json_encode([
            ['name' => 'prod', 'url' => 'http://127.0.0.1:1', 'user' => 'u', 'secret' => 's'],
        ]));
        chmod($authFile, 0600);

        $result = $this->invokeConnectSwitch(null);

        $this->assertFalse($result->success);
        // Connection/auth failure message
        $this->assertNotEmpty($result->error);
    }

    public function testConnectSwitchSingleServerWithSession(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, json_encode([
            ['name' => 'prod', 'url' => 'http://127.0.0.1:1', 'user' => 'u', 'secret' => 's'],
        ]));
        chmod($authFile, 0600);

        // Create a valid session token
        $header = base64_encode('{"alg":"HS256"}');
        $payload = base64_encode(json_encode(['exp' => time() + 3600]));
        $signature = base64_encode('sig');
        $jwt = "$header.$payload.$signature";

        $sessionsFile = $this->tempHome . '/.fql/sessions.json';
        file_put_contents($sessionsFile, json_encode([
            'http://127.0.0.1:1' => ['token' => $jwt, 'expires_at' => time() + 3600],
        ], JSON_PRETTY_PRINT) . "\n");
        chmod($sessionsFile, 0600);

        $result = $this->invokeConnectSwitch(null);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->executor);
        $this->assertNotNull($result->historyManager);
    }

    public function testConnectSwitchNamedServerWithSession(): void
    {
        $authFile = $this->tempHome . '/.fql/auth.json';
        file_put_contents($authFile, json_encode([
            ['name' => 'prod', 'url' => 'http://127.0.0.1:1', 'user' => 'u', 'secret' => 's'],
            ['name' => 'staging', 'url' => 'http://127.0.0.1:2', 'user' => 'u2', 'secret' => 's2'],
        ]));
        chmod($authFile, 0600);

        $header = base64_encode('{"alg":"HS256"}');
        $payload = base64_encode(json_encode(['exp' => time() + 3600]));
        $signature = base64_encode('sig');
        $jwt = "$header.$payload.$signature";

        $sessionsFile = $this->tempHome . '/.fql/sessions.json';
        file_put_contents($sessionsFile, json_encode([
            'http://127.0.0.1:2' => ['token' => $jwt, 'expires_at' => time() + 3600],
        ], JSON_PRETTY_PRINT) . "\n");
        chmod($sessionsFile, 0600);

        $result = $this->invokeConnectSwitch('staging');

        $this->assertTrue($result->success);
    }

    public function testLocalSwitchSuccess(): void
    {
        $result = $this->invokeLocalSwitch();

        $this->assertTrue($result->success);
        $this->assertNotNull($result->executor);
        $this->assertNotNull($result->historyManager);
    }

    public function testLocalSwitchWithInvalidFile(): void
    {
        $result = $this->invokeLocalSwitch('/nonexistent/file.csv');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('File not found', $result->error ?? '');
    }

    /**
     * Helper: invoke handleConnectSwitch via reflection.
     */
    private function invokeConnectSwitch(?string $serverName): \FQL\Cli\Interactive\ModeSwitchResult
    {
        $command = new QueryCommand();
        // addCommand triggers configure() internally
        $application = new Application();
        $application->addCommand($command);

        $configManagerProp = new \ReflectionProperty($command, 'configManager');
        $configManagerProp->setValue($command, new \FQL\Cli\Config\ConfigManager($this->tempHome . '/.fql'));

        $sessionManagerProp = new \ReflectionProperty($command, 'sessionManager');
        $sessionManagerProp->setValue($command, new \FQL\Cli\Config\SessionManager($this->tempHome . '/.fql'));

        $input = new \Symfony\Component\Console\Input\ArrayInput([
            '--file' => $this->tempFile,
            '--file-type' => 'csv',
            '--file-delimiter' => ';',
        ], $command->getDefinition());

        $output = new \Symfony\Component\Console\Output\ConsoleOutput(
            \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_QUIET,
            false
        );

        $method = new \ReflectionMethod($command, 'handleConnectSwitch');
        return $method->invoke($command, $serverName, $input, $output);
    }

    /**
     * Helper: invoke handleLocalSwitch via reflection.
     */
    private function invokeLocalSwitch(?string $file = null): \FQL\Cli\Interactive\ModeSwitchResult
    {
        $command = new QueryCommand();
        $application = new Application();
        $application->addCommand($command);

        $configManagerProp = new \ReflectionProperty($command, 'configManager');
        $configManagerProp->setValue($command, new \FQL\Cli\Config\ConfigManager($this->tempHome . '/.fql'));

        $input = new \Symfony\Component\Console\Input\ArrayInput([
            '--file' => $file ?? $this->tempFile,
            '--file-type' => 'csv',
            '--file-delimiter' => ';',
        ], $command->getDefinition());

        $method = new \ReflectionMethod($command, 'handleLocalSwitch');
        return $method->invoke($command, $input);
    }
}
