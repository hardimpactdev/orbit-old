<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * Service for provisioning remote Linux servers via SSH.
 *
 * Relies on user's SSH config (~/.ssh/config) for authentication.
 */
final class RemoteProvisioner
{
    /**
     * Run a command on a remote server via SSH.
     */
    private function ssh(string $user, string $ip, string $remoteCommand, int $timeout = 120): \Illuminate\Contracts\Process\ProcessResult
    {
        return Process::timeout($timeout)->run(
            sprintf('ssh %s@%s %s', escapeshellarg($user), escapeshellarg($ip), escapeshellarg($remoteCommand))
        );
    }

    /**
     * Run a command on a remote server via SSH with output streaming.
     */
    private function sshWithOutput(string $user, string $ip, string $remoteCommand, int $timeout = 120): \Illuminate\Contracts\Process\ProcessResult
    {
        return Process::timeout($timeout)->run(
            sprintf('ssh %s@%s %s', escapeshellarg($user), escapeshellarg($ip), escapeshellarg($remoteCommand)),
            function (string $type, string $output) {
                echo $output;
            }
        );
    }

    /**
     * Check if the remote system is supported (Ubuntu 24.04+).
     *
     * @return array{supported: bool, os?: string, version?: string, error?: string}
     */
    public function checkSystemCompatibility(string $ip, string $user): array
    {
        $unameResult = $this->ssh($user, $ip, 'uname -s');
        if (! $unameResult->successful()) {
            return ['supported' => false, 'error' => 'Failed to detect operating system.'];
        }

        $os = strtolower(trim($unameResult->output()));
        if ($os !== 'linux') {
            return ['supported' => false, 'os' => $os, 'error' => "Unsupported operating system: {$os}. Only Linux is supported."];
        }

        $lsbResult = $this->ssh($user, $ip, 'lsb_release -is 2>/dev/null || grep ^ID= /etc/os-release | cut -d= -f2');
        $versionResult = $this->ssh($user, $ip, 'lsb_release -rs 2>/dev/null || grep ^VERSION_ID= /etc/os-release | cut -d= -f2 | tr -d "\""');

        if (! $lsbResult->successful()) {
            return ['supported' => false, 'os' => 'linux', 'error' => 'Failed to detect Linux distribution. Ubuntu 24.04+ required.'];
        }

        $distro = strtolower(trim($lsbResult->output()));
        $version = $versionResult->successful() ? trim($versionResult->output(), "\n\r\t \"'") : 'unknown';
        $majorVersion = (int) explode('.', $version)[0];

        if ($distro !== 'ubuntu') {
            return ['supported' => false, 'os' => $distro, 'version' => $version, 'error' => "Unsupported distribution: {$distro} {$version}. Only Ubuntu 24.04+ is supported."];
        }

        if ($majorVersion < 24) {
            return ['supported' => false, 'os' => $distro, 'version' => $version, 'error' => "Ubuntu {$version} is not supported. Ubuntu 24.04+ is required."];
        }

        return ['supported' => true, 'os' => $distro, 'version' => $version];
    }

    /**
     * Clear cached SSH host key for an IP (use during initial provisioning only).
     */
    public function clearHostKey(string $ip): void
    {
        Process::run(sprintf('ssh-keygen -R %s 2>/dev/null', escapeshellarg($ip)));
    }

    /**
     * Test SSH connection to target server.
     *
     * @return array{success: bool, error?: string}
     */
    public function testConnection(string $ip, string $user): array
    {
        $escapedIp = escapeshellarg($ip);
        $escapedUser = escapeshellarg($user);

        $result = Process::timeout(15)->run("ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new -o BatchMode=yes {$escapedUser}@{$escapedIp} 'echo connected'");

        if ($result->successful() && str_contains($result->output(), 'connected')) {
            return ['success' => true];
        }

        // Analyze the error to provide a helpful message
        $errorOutput = $result->errorOutput();

        if (str_contains($errorOutput, 'Connection refused')) {
            return ['success' => false, 'error' => 'Connection refused. SSH service may not be running on the server.'];
        }

        if (str_contains($errorOutput, 'Connection timed out')) {
            return ['success' => false, 'error' => 'Connection timed out. The server may be unreachable or blocking SSH.'];
        }

        if (str_contains($errorOutput, 'Permission denied')) {
            return ['success' => false, 'error' => 'Permission denied. Check your SSH key is added to the server (ssh-copy-id).'];
        }

        if (str_contains($errorOutput, 'Host key verification failed') || str_contains($errorOutput, 'WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED')) {
            return ['success' => false, 'error' => 'Host key mismatch. The server may have been reset. The old key was cleared - please try again.'];
        }

        if (str_contains($errorOutput, 'Could not resolve hostname') || str_contains($errorOutput, 'Name or service not known')) {
            return ['success' => false, 'error' => 'Could not resolve hostname. Check the IP address is correct.'];
        }

        if (str_contains($errorOutput, 'No route to host')) {
            return ['success' => false, 'error' => 'No route to host. The server may be down or network unreachable.'];
        }

        return ['success' => false, 'error' => 'SSH connection failed: '.trim($errorOutput ?: 'Unknown error')];
    }

    /**
     * Detect the current state of the remote server for resuming setup.
     *
     * @return array{canConnectAsInitial: bool, canConnectAsRemote: bool, userExists: bool, sshHardened: bool, orbitInstalled: bool}
     */
    public function detectState(string $ip, string $initialUser, string $remoteUser): array
    {
        $state = [
            'canConnectAsInitial' => false,
            'canConnectAsRemote' => false,
            'userExists' => false,
            'sshHardened' => false,
            'orbitInstalled' => false,
        ];

        // Try connecting as initial user first
        $initialConnection = $this->testConnection($ip, $initialUser);
        $state['canConnectAsInitial'] = $initialConnection['success'];

        if ($state['canConnectAsInitial']) {
            $escapedRemoteUser = escapeshellarg($remoteUser);
            $userCheck = $this->ssh($initialUser, $ip, "id -u {$escapedRemoteUser} 2>/dev/null");
            $state['userExists'] = $userCheck->successful();

            $sshCheck = $this->ssh($initialUser, $ip, 'test -f /etc/ssh/sshd_config.d/99-orbit.conf');
            $state['sshHardened'] = $sshCheck->successful();

            if ($state['userExists']) {
                $remoteConnection = $this->testConnection($ip, $remoteUser);
                $state['canConnectAsRemote'] = $remoteConnection['success'];
            }
        } else {
            $remoteConnection = $this->testConnection($ip, $remoteUser);
            $state['canConnectAsRemote'] = $remoteConnection['success'];

            if ($state['canConnectAsRemote']) {
                $state['userExists'] = true;
            }
        }

        if ($state['canConnectAsRemote']) {
            $orbitCheck = $this->ssh($remoteUser, $ip, 'test -x ~/.local/bin/orbit');
            $state['orbitInstalled'] = $orbitCheck->successful();
        }

        return $state;
    }

    /**
     * Install PHP on the remote server (required for running phar files).
     */
    private function installPhpOnRemote(string $ip, string $user): array
    {
        $checkPhp = $this->ssh($user, $ip, 'command -v php');
        if ($checkPhp->successful()) {
            return ['success' => true, 'message' => 'PHP already installed'];
        }

        $installScript = <<<'SCRIPT'
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update -qq
sudo apt-get install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update -qq
sudo apt-get install -y php8.4-cli php8.4-common php8.4-curl php8.4-zip php8.4-mbstring php8.4-xml php8.4-bcmath php8.4-sqlite3
SCRIPT;

        $result = $this->ssh($user, $ip, $installScript, 180);

        if (! $result->successful()) {
            return ['success' => false, 'error' => 'Failed to install PHP: '.$result->errorOutput()];
        }

        return ['success' => true, 'message' => 'PHP installed successfully'];
    }

    /**
     * Create a new user with passwordless sudo.
     */
    public function createUser(string $ip, string $currentUser, string $newUser): bool
    {
        $escapedNewUser = escapeshellarg($newUser);

        $checkResult = $this->ssh($currentUser, $ip, "id -u {$escapedNewUser} 2>/dev/null");
        if ($checkResult->successful()) {
            return $this->ensureSudoAccess($ip, $currentUser, $newUser);
        }

        $createResult = $this->ssh($currentUser, $ip, "useradd -m -s /bin/bash {$escapedNewUser}", 60);
        if (! $createResult->successful()) {
            return false;
        }

        $this->ssh($currentUser, $ip, "usermod -aG sudo {$escapedNewUser}");

        $sudoersContent = "{$newUser} ALL=(ALL:ALL) NOPASSWD:ALL";
        $escapedSudoers = escapeshellarg($sudoersContent);
        $this->ssh($currentUser, $ip, "echo {$escapedSudoers} > /etc/sudoers.d/99-orbit && chmod 440 /etc/sudoers.d/99-orbit");

        return $this->ensureSudoAccess($ip, $currentUser, $newUser);
    }

    /**
     * Ensure user has sudo access.
     */
    private function ensureSudoAccess(string $ip, string $currentUser, string $targetUser): bool
    {
        $escapedTarget = escapeshellarg($targetUser);
        $result = $this->ssh($currentUser, $ip, "su - {$escapedTarget} -c \"sudo -n echo sudo-ok\"");

        return $result->successful() && str_contains($result->output(), 'sudo-ok');
    }

    /**
     * Copy SSH authorized_keys from current user to new user.
     */
    public function copySshKeys(string $ip, string $fromUser, string $toUser): bool
    {
        $sourceKeys = $this->ssh($fromUser, $ip, 'cat ~/.ssh/authorized_keys 2>/dev/null || cat /root/.ssh/authorized_keys 2>/dev/null');

        if (! $sourceKeys->successful() || trim($sourceKeys->output()) === '') {
            $sourceKeys = $this->ssh($fromUser, $ip, 'cat ~/.ssh/authorized_keys');
            if (! $sourceKeys->successful()) {
                return false;
            }
        }

        $keys = trim($sourceKeys->output());
        if ($keys === '') {
            return false;
        }

        $escapedToUser = escapeshellarg($toUser);

        $mkdirResult = $this->ssh($fromUser, $ip, "mkdir -p /home/{$escapedToUser}/.ssh && chmod 700 /home/{$escapedToUser}/.ssh");
        if (! $mkdirResult->successful()) {
            return false;
        }

        $escapedKeys = escapeshellarg($keys);
        $result = $this->ssh($fromUser, $ip, "echo {$escapedKeys} > /home/{$escapedToUser}/.ssh/authorized_keys && chmod 600 /home/{$escapedToUser}/.ssh/authorized_keys && chown -R {$escapedToUser}:{$escapedToUser} /home/{$escapedToUser}/.ssh");

        return $result->successful();
    }

    /**
     * Harden SSH configuration (disable password auth, etc.).
     */
    public function hardenSsh(string $ip, string $user): bool
    {
        $this->ssh($user, $ip, 'cp /etc/ssh/sshd_config /etc/ssh/sshd_config.backup.$(date +%s)');

        $settings = [
            'PasswordAuthentication no',
            'PermitRootLogin prohibit-password',
            'PubkeyAuthentication yes',
            'ChallengeResponseAuthentication no',
            'UsePAM yes',
            'X11Forwarding no',
            'PrintMotd no',
            'AcceptEnv LANG LC_*',
            'Subsystem sftp /usr/lib/openssh/sftp-server',
        ];

        $configContent = implode("\n", $settings);
        $b64Content = base64_encode($configContent);

        $result = $this->ssh($user, $ip, "echo {$b64Content} | base64 -d > /etc/ssh/sshd_config.d/99-orbit.conf && chmod 600 /etc/ssh/sshd_config.d/99-orbit.conf");

        if (! $result->successful()) {
            foreach ($settings as $setting) {
                $key = explode(' ', $setting)[0];
                $escapedKey = escapeshellarg($key);
                $escapedSetting = escapeshellarg($setting);
                $this->ssh($user, $ip, "sed -i \"/^{$escapedKey}/d\" /etc/ssh/sshd_config");
                $this->ssh($user, $ip, "echo {$escapedSetting} >> /etc/ssh/sshd_config");
            }
        }

        $testResult = $this->ssh($user, $ip, 'sshd -t');
        if (! $testResult->successful()) {
            $this->ssh($user, $ip, 'mv /etc/ssh/sshd_config.backup.* /etc/ssh/sshd_config');

            return false;
        }

        $this->ssh($user, $ip, 'systemctl restart sshd || service ssh restart || /etc/init.d/ssh restart', 30);

        sleep(2);

        return true;
    }

    /**
     * Install Orbit CLI on remote server.
     *
     * @param  string  $ip  Target server IP
     * @param  string  $user  SSH user
     * @param  string|null  $localBinary  Path to local phar binary (for dev use)
     * @return array{success: bool, error?: string}
     */
    public function installOrbit(string $ip, string $user, ?string $localBinary = null): array
    {
        if ($localBinary !== null && file_exists($localBinary)) {
            $this->installPhpOnRemote($ip, $user);

            $remotePath = '/home/'.escapeshellarg($user).'/.local/bin/orbit';
            $homeDir = '$HOME/.local/bin';

            $mkdirResult = $this->ssh($user, $ip, "mkdir -p {$homeDir}");
            if (! $mkdirResult->successful()) {
                return ['success' => false, 'error' => 'Failed to create .local/bin directory: '.$mkdirResult->errorOutput()];
            }

            $escapedLocal = escapeshellarg($localBinary);
            $escapedTarget = escapeshellarg("{$user}@{$ip}:~/.local/bin/orbit");
            $copyResult = Process::timeout(60)->run("scp -o StrictHostKeyChecking=accept-new {$escapedLocal} {$escapedTarget}");

            if (! $copyResult->successful()) {
                return ['success' => false, 'error' => 'Failed to copy binary via SCP: '.$copyResult->errorOutput()];
            }

            $chmodResult = $this->ssh($user, $ip, 'chmod +x ~/.local/bin/orbit');
            if (! $chmodResult->successful()) {
                return ['success' => false, 'error' => 'Failed to make binary executable: '.$chmodResult->errorOutput()];
            }

            $checkResult = $this->ssh($user, $ip, '~/.local/bin/orbit --version');
            if (! $checkResult->successful()) {
                return ['success' => false, 'error' => 'Binary copied but execution failed: '.$checkResult->errorOutput()];
            }

            $checkPathResult = $this->ssh($user, $ip, 'grep -q ".local/bin" ~/.bashrc 2>/dev/null && echo present || echo missing');
            if (trim($checkPathResult->output()) === 'missing') {
                $this->ssh($user, $ip, 'echo \'export PATH="$HOME/.local/bin:$PATH"\' >> ~/.bashrc');
            }

            return ['success' => true];
        }

        $installScript = 'curl -fsSL https://raw.githubusercontent.com/hardimpactdev/orbit-cli/main/install.sh | bash';

        $result = $this->ssh($user, $ip, $installScript, 120);

        if (! $result->successful()) {
            return ['success' => false, 'error' => 'Install script failed: '.$result->errorOutput()];
        }

        $checkResult = $this->ssh($user, $ip, 'which orbit');
        if (! $checkResult->successful()) {
            return ['success' => false, 'error' => 'Install completed but orbit command not found in PATH.'];
        }

        return ['success' => true];
    }

    /**
     * Set up gateway stack on remote server.
     */
    public function setupGateway(string $ip, string $user): bool
    {
        $dockerResult = $this->installDocker($ip, $user);
        if (! $dockerResult['success']) {
            return false;
        }

        $migrateResult = $this->sshWithOutput($user, $ip, '~/.local/bin/orbit migrate --force', 60);
        if (! $migrateResult->successful()) {
            return false;
        }

        $escapedUser = escapeshellarg($user);
        $escapedIp = escapeshellarg($ip);
        $result = Process::timeout(600)->run(
            "ssh -t {$escapedUser}@{$escapedIp} '~/.local/bin/orbit install --template=gateway --yes'",
            function (string $type, string $output) {
                echo $output;
            }
        );

        return $result->successful();
    }

    /**
     * Install Docker on the remote server (required for gateway/WG Easy).
     *
     * @return array{success: bool, error?: string}
     */
    private function installDocker(string $ip, string $user): array
    {
        $checkDocker = $this->ssh($user, $ip, 'command -v docker');
        if ($checkDocker->successful()) {
            return ['success' => true, 'message' => 'Docker already installed'];
        }

        $result = $this->sshWithOutput($user, $ip, 'curl -fsSL https://get.docker.com | sh', 300);

        if (! $result->successful()) {
            return ['success' => false, 'error' => 'Failed to install Docker: '.$result->errorOutput()];
        }

        $escapedUser = escapeshellarg($user);
        $this->ssh($user, $ip, "sudo usermod -aG docker {$escapedUser}");
        $this->ssh($user, $ip, 'sudo systemctl start docker && sudo systemctl enable docker');

        return ['success' => true, 'message' => 'Docker installed successfully'];
    }
}
