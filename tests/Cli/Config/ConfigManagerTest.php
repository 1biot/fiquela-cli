<?php

namespace Cli\Config;

use FQL\Cli\Config\ConfigManager;
use FQL\Cli\Config\ServerConfig;
use PHPUnit\Framework\TestCase;

class ConfigManagerTest extends TestCase
{
    private string $tempDir;
    private ConfigManager $manager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/fql-test-' . uniqid();
        mkdir($this->tempDir, 0700, true);
        $this->manager = new ConfigManager($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Cleanup
        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testGetConfigDir(): void
    {
        $this->assertEquals($this->tempDir, $this->manager->getConfigDir());
    }

    public function testGetAuthFile(): void
    {
        $this->assertEquals($this->tempDir . '/auth.json', $this->manager->getAuthFile());
    }

    public function testHasAuthFileWhenNotExists(): void
    {
        $this->assertFalse($this->manager->hasAuthFile());
    }

    public function testHasAuthFileWhenExists(): void
    {
        file_put_contents($this->tempDir . '/auth.json', '[]');
        $this->assertTrue($this->manager->hasAuthFile());
    }

    public function testValidateAuthFilePermissions(): void
    {
        $authFile = $this->tempDir . '/auth.json';
        file_put_contents($authFile, '[]');

        // Set correct permissions
        $this->assertTrue(chmod($authFile, 0600));
        clearstatcache(true, $authFile);
        $this->assertTrue($this->manager->validateAuthFilePermissions());

        // Set incorrect permissions
        $this->assertTrue(chmod($authFile, 0644));
        clearstatcache(true, $authFile);

        $perms = fileperms($authFile);
        if ($perms === false) {
            $this->markTestSkipped('Unable to read file permissions in this environment.');
        }

        $actualPerms = $perms & 0777;
        if ($actualPerms === 0600) {
            $this->markTestSkipped('Filesystem does not apply chmod(0644) reliably in this environment.');
        }

        $this->assertFalse($this->manager->validateAuthFilePermissions());
    }

    public function testLoadServersEmpty(): void
    {
        $this->assertEquals([], $this->manager->loadServers());
    }

    public function testLoadServersFromFile(): void
    {
        $servers = [
            ['name' => 'prod', 'url' => 'https://api.example.com', 'user' => 'admin', 'secret' => 'pass1'],
            ['name' => 'local', 'url' => 'http://localhost:6917', 'user' => 'dev', 'secret' => 'pass2'],
        ];
        file_put_contents($this->tempDir . '/auth.json', json_encode($servers));

        $loaded = $this->manager->loadServers();
        $this->assertCount(2, $loaded);
        $this->assertEquals('prod', $loaded[0]->name);
        $this->assertEquals('local', $loaded[1]->name);
    }

    public function testLoadServersSkipsInvalid(): void
    {
        $servers = [
            ['name' => 'prod', 'url' => 'https://api.example.com', 'user' => 'admin', 'secret' => 'pass1'],
            ['name' => '', 'url' => '', 'user' => '', 'secret' => ''], // invalid
        ];
        file_put_contents($this->tempDir . '/auth.json', json_encode($servers));

        $loaded = $this->manager->loadServers();
        $this->assertCount(1, $loaded);
    }

    public function testAddServer(): void
    {
        $server = new ServerConfig('prod', 'https://api.example.com', 'admin', 'pass');
        $this->manager->addServer($server);

        $loaded = $this->manager->loadServers();
        $this->assertCount(1, $loaded);
        $this->assertEquals('prod', $loaded[0]->name);

        // Check file permissions
        $perms = fileperms($this->tempDir . '/auth.json') & 0777;
        $this->assertEquals(0600, $perms);
    }

    public function testAddServerReplacesExisting(): void
    {
        $server1 = new ServerConfig('prod', 'https://api.example.com', 'admin', 'pass1');
        $this->manager->addServer($server1);

        $server2 = new ServerConfig('prod', 'https://new-api.example.com', 'admin', 'pass2');
        $this->manager->addServer($server2);

        $loaded = $this->manager->loadServers();
        $this->assertCount(1, $loaded);
        $this->assertEquals('https://new-api.example.com', $loaded[0]->url);
        $this->assertEquals('pass2', $loaded[0]->secret);
    }

    public function testFindServer(): void
    {
        $server = new ServerConfig('prod', 'https://api.example.com', 'admin', 'pass');
        $this->manager->addServer($server);

        $found = $this->manager->findServer('prod');
        $this->assertNotNull($found);
        $this->assertEquals('prod', $found->name);

        $notFound = $this->manager->findServer('staging');
        $this->assertNull($notFound);
    }

    public function testRemoveServer(): void
    {
        $this->manager->addServer(new ServerConfig('prod', 'https://api.example.com', 'admin', 'pass1'));
        $this->manager->addServer(new ServerConfig('local', 'http://localhost:6917', 'dev', 'pass2'));

        $result = $this->manager->removeServer('prod');
        $this->assertTrue($result);

        $loaded = $this->manager->loadServers();
        $this->assertCount(1, $loaded);
        $this->assertEquals('local', $loaded[0]->name);
    }

    public function testRemoveServerNotFound(): void
    {
        $this->manager->addServer(new ServerConfig('prod', 'https://api.example.com', 'admin', 'pass'));

        $result = $this->manager->removeServer('nonexistent');
        $this->assertFalse($result);

        $loaded = $this->manager->loadServers();
        $this->assertCount(1, $loaded);
    }

    public function testGetRequiredPermissionsString(): void
    {
        $this->assertEquals('0600', ConfigManager::getRequiredPermissionsString());
    }

    public function testEnsureConfigDirCreatesDirectory(): void
    {
        $newDir = $this->tempDir . '/subdir/nested';
        $manager = new ConfigManager($newDir);

        $manager->ensureConfigDir();

        $this->assertDirectoryExists($newDir);

        // Cleanup nested dirs
        rmdir($newDir);
        rmdir($this->tempDir . '/subdir');
    }

    public function testEnsureConfigDirSkipsExisting(): void
    {
        // Already exists from setUp
        $this->manager->ensureConfigDir();
        $this->assertDirectoryExists($this->tempDir);
    }

    public function testValidateAuthFilePermissionsReturnsFalseWhenNoFile(): void
    {
        // No auth.json exists
        $this->assertFalse($this->manager->validateAuthFilePermissions());
    }

    public function testLoadServersWithInvalidJson(): void
    {
        file_put_contents($this->tempDir . '/auth.json', 'not json');
        $this->assertEquals([], $this->manager->loadServers());
    }

    public function testLoadServersWithNonArrayItems(): void
    {
        $data = ['string-not-array', 42, null];
        file_put_contents($this->tempDir . '/auth.json', json_encode($data));
        $this->assertEquals([], $this->manager->loadServers());
    }

    public function testFindServerWhenNoAuthFile(): void
    {
        $this->assertNull($this->manager->findServer('prod'));
    }

    public function testDefaultConfigDirUsesHome(): void
    {
        $originalHome = getenv('HOME');
        try {
            putenv('HOME=/tmp/test-home');
            $manager = new ConfigManager();
            $this->assertEquals('/tmp/test-home/.fql', $manager->getConfigDir());
        } finally {
            if ($originalHome !== false) {
                putenv('HOME=' . $originalHome);
            } else {
                putenv('HOME');
            }
        }
    }

    public function testDefaultConfigDirThrowsWhenNoHome(): void
    {
        $originalHome = getenv('HOME');
        $originalUserProfile = getenv('USERPROFILE');
        try {
            putenv('HOME=');
            putenv('USERPROFILE=');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to determine home directory');
            new ConfigManager();
        } finally {
            if ($originalHome !== false) {
                putenv('HOME=' . $originalHome);
            }
            if ($originalUserProfile !== false) {
                putenv('USERPROFILE=' . $originalUserProfile);
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function testLoadServersHandlesFileReadFailure(): void
    {
        // Create the auth file but make it unreadable
        $authFile = $this->tempDir . '/auth.json';
        file_put_contents($authFile, '[]');

        // On some systems, root can read anything, so check
        chmod($authFile, 0000);
        clearstatcache(true, $authFile);

        if (is_readable($authFile)) {
            // Running as root or on a FS that ignores perms
            chmod($authFile, 0600);
            $this->markTestSkipped('Cannot make file unreadable in this environment.');
        }

        @$result = $this->manager->loadServers();
        $this->assertEquals([], $result);

        // Restore permissions for tearDown cleanup
        chmod($authFile, 0600);
    }

    public function testAddServerToExistingList(): void
    {
        $this->manager->addServer(new ServerConfig('prod', 'https://api1.example.com', 'u1', 'p1'));
        $this->manager->addServer(new ServerConfig('staging', 'https://api2.example.com', 'u2', 'p2'));

        $loaded = $this->manager->loadServers();
        $this->assertCount(2, $loaded);
        $this->assertEquals('prod', $loaded[0]->name);
        $this->assertEquals('staging', $loaded[1]->name);
    }
}
