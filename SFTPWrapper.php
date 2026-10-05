<?php

/**
 * Kleiner SFTP-Shim fuer alten prozeduralen PHP-Code.
 *
 * Die Klasse startet eine persistente native OpenSSH-sftp-Sitzung.
 * Sie ist deshalb ein schlanker Uebergangs-Shim fuer eine echte
 * phpseclib-Verbindung, bildet aber die hier benoetigten Methoden nach.
 */
class SFTPWrapper
{
    protected $host;
    protected $port;
    protected $username;
    protected $credential;
    protected $credentialType;
    protected $keyPath;
    protected $sftpBinary;
    protected $sshpassBinary;
    protected $strictHostKeyChecking;
    protected $userKnownHostsFile;
    protected $throwExceptions;
    protected $currentDirectory = ".";
    protected $process = null;
    protected $stdin = null;
    protected $stdout = null;
    protected $stderr = null;
    protected $processId = null;
    protected $connected = false;

    /**
     * @param string $host
     * @param int    $port
     * @param array  $options Optionale Konfiguration fuer die Uebergangsloesung
     */
    public function __construct($host, $port = 22, $options = array())
    {
        $this->host = (string) $host;
        $this->port = (int) $port;
        $this->sftpBinary = isset($options['sftpBinary'])
            ? (string) $options['sftpBinary']
            : 'sftp';
        $this->sshpassBinary = isset($options['sshpassBinary'])
            ? (string) $options['sshpassBinary']
            : 'sshpass';
        $this->strictHostKeyChecking = isset($options['strictHostKeyChecking'])
            ? (string) $options['strictHostKeyChecking']
            : 'no';
        $this->userKnownHostsFile = isset($options['userKnownHostsFile'])
            ? (string) $options['userKnownHostsFile']
            : '/dev/null';

        /* false bewahrt die fuer Legacy-Code erwarteten false-Rueckgaben. */
        $this->throwExceptions = !empty($options['throwExceptions']);
    }

    /**
     * Merkt sich die Zugangsdaten und prueft die Verbindung sofort.
     * Ein vorhandener, lesbarer Dateipfad wird als SSH-Key interpretiert;
     * alle anderen Werte werden als Passwort an sshpass uebergeben.
     *
     * @return bool
     */
    public function login($username, $passwordOrKeyPath, $keyPath = null)
    {
        $this->username = (string) $username;

        /*
         * Drei kompatible Varianten:
         *   login($user, $passwort)
         *   login($user, $keyPath)
         *   login($user, $passwort, $keyPath)
         */
        if ($keyPath !== null && (string) $keyPath !== '') {
            $this->credential = (string) $passwordOrKeyPath;
            $this->keyPath = (string) $keyPath;

            if (!is_file($this->keyPath) || !is_readable($this->keyPath)) {
                return $this->handleError(new RuntimeException(
                    'Der angegebene SSH-Key ist nicht vorhanden oder nicht lesbar: '
                        . $this->keyPath
                ));
            }

            $this->credentialType = 'password+key';
        } elseif (is_file((string) $passwordOrKeyPath)
            && is_readable((string) $passwordOrKeyPath)) {
            $this->credential = (string) $passwordOrKeyPath;
            $this->keyPath = null;
            $this->credentialType = 'key';
        } else {
            $this->credential = (string) $passwordOrKeyPath;
            $this->keyPath = null;
            $this->credentialType = 'password';
        }

        try {
            $output = $this->runBatch(array('pwd'));
            $currentDirectory = $this->extractRemotePwd($output);
            if ($currentDirectory !== false) {
                $this->currentDirectory = $currentDirectory;
            }
            return true;
        } catch (RuntimeException $exception) {
            return $this->handleError($exception);
        }
    }

    /**
     * Wechselt das entfernte Arbeitsverzeichnis.
     * Relative Pfade werden gegen das aktuell gemerkte Verzeichnis aufgeloest.
     *
     * @param string $remoteDirectory
     * @return bool
     */
    public function chdir($remoteDirectory)
    {
        try {
            $this->requireLogin();
            $target = $this->resolveRemotePath($remoteDirectory);
            $output = $this->runBatch(array(
                'cd ' . escapeshellarg($target),
                'pwd'
            ));
            $actualDirectory = $this->extractRemotePwd($output);

            if ($actualDirectory === false
                || $this->normalizeRemotePath($actualDirectory) !== $target) {
                throw new RuntimeException(
                    'Das entfernte Arbeitsverzeichnis konnte nicht gewechselt werden: '
                        . $remoteDirectory
                );
            }

            $this->currentDirectory = $target;
            return true;
        } catch (RuntimeException $exception) {
            return $this->handleError($exception);
        }
    }
    /**
     * Liefert das aktuelle entfernte Arbeitsverzeichnis.
     *
     * @return string|false
     */
    public function pwd()
    {
        try {
            $this->requireLogin();

            $commands = array('pwd');
            if ($this->currentDirectory !== ''
                && $this->currentDirectory !== '.') {
                $commands = array(
                    'cd ' . escapeshellarg($this->currentDirectory),
                    'pwd'
                );
            }

            $output = $this->runBatch($commands);
            $currentDirectory = $this->extractRemotePwd($output);

            if ($currentDirectory === false) {
                throw new RuntimeException(
                    'Das entfernte Arbeitsverzeichnis konnte nicht gelesen werden.'
                );
            }

            $this->currentDirectory = $currentDirectory;
            return $currentDirectory;
        } catch (RuntimeException $exception) {
            return $this->handleError($exception);
        }
    }
    /**
     * Beendet die persistente SFTP-Sitzung.
     *
     * @return bool
     */
    public function disconnect()
    {
        $this->closeProcess(true);
        return true;
    }
    /** @return bool */
    public function put($remoteFile, $localFile)
    {
        try {
            $this->requireLogin();
            $remoteFile = $this->resolveRemotePath($remoteFile);
            $this->runBatch(array(
                'put ' . escapeshellarg((string) $localFile) . ' '
                    . escapeshellarg((string) $remoteFile)
            ));

            if (!$this->file_exists($remoteFile)) {
                throw new RuntimeException(
                    'Die entfernte Datei ist nach dem Upload nicht vorhanden: '
                        . $remoteFile
                );
            }

            return true;
        } catch (RuntimeException $exception) {
            return $this->handleError($exception);
        }
    }

    /** @return bool */
    public function get($remoteFile, $localFile)
    {
        try {
            $this->requireLogin();
            $remoteFile = $this->resolveRemotePath($remoteFile);
            $this->runBatch(array(
                'get ' . escapeshellarg((string) $remoteFile) . ' '
                    . escapeshellarg((string) $localFile)
            ));
            return true;
        } catch (RuntimeException $exception) {
            return $this->handleError($exception);
        }
    }

    /** @return array|false */
    public function nlist($remoteDirectory)
    {
        try {
            $this->requireLogin();
            $remoteDirectory = $this->resolveRemotePath($remoteDirectory);
            $output = $this->runBatch(array(
                'ls -1 ' . escapeshellarg((string) $remoteDirectory)
            ));

            $lines = $this->outputLines($output);
            $directory = rtrim((string) $remoteDirectory, '/');

            /*
             * OpenSSH-sftp liefert bei ls mitunter den kompletten Pfad,
             * phpseclib::nlist() dagegen nur die Dateinamen.
             */
            if ($directory !== '' && $directory !== '/') {
                $prefix = $directory . '/';

                foreach ($lines as $index => $line) {
                    if (strpos($line, $prefix) === 0) {
                        $lines[$index] = substr($line, strlen($prefix));
                    }
                }
            }

            return $lines;
        } catch (RuntimeException $exception) {
            return $this->handleError($exception);
        }
    }

    /** @return bool */
    public function is_dir($remotePath)
    {
        try {
            $this->requireLogin();
            $line = $this->findListingLineForPath($remotePath);

            return $line !== false && isset($line[0]) && $line[0] === 'd';
        } catch (RuntimeException $exception) {
            return $this->handleError($exception);
        }
    }

    /**
     * Liefert die mtime aus der menschenlesbaren OpenSSH-sftp-Ausgabe.
     * OpenSSH-sftp bietet im Batch-Interface keine portable stat-Funktion.
     *
     * @return int|false
     */
    public function filemtime($remotePath)
    {
        try {
            $this->requireLogin();
            $line = $this->findListingLineForPath($remotePath);

            if ($line === false) {
                throw new RuntimeException('Keine Dateiliste fuer filemtime erhalten.');
            }

            $timestamp = $this->parseListingTime($line);
            if ($timestamp === false) {
                throw new RuntimeException('Das mtime-Format der sftp-Ausgabe konnte nicht gelesen werden.');
            }

            return $timestamp;
        } catch (RuntimeException $exception) {
            return $this->handleError($exception);
        }
    }

    /**
     * Prueft, ob ein entfernter Pfad vorhanden ist.
     *
     * @return bool
     */
    public function file_exists($remotePath)
    {
        try {
            $this->requireLogin();

            return $this->findListingLineForPath($remotePath) !== false;
        } catch (RuntimeException $exception) {
            return false;
        }
    }

    /**
     * Prueft, ob ein entfernter Pfad eine regulaere Datei ist.
     *
     * @return bool
     */
    public function is_file($remotePath)
    {
        try {
            $this->requireLogin();
            $line = $this->findListingLineForPath($remotePath);

            return $line !== false
                && isset($line[0])
                && ($line[0] === '-' || $line[0] === 'l');
        } catch (RuntimeException $exception) {
            return $this->handleError($exception);
        }
    }
    /**
     * Liefert die Groesse einer entfernten Datei in Bytes.
     *
     * @return int|false
     */
    public function filesize($remotePath)
    {
        try {
            $this->requireLogin();
            $line = $this->findListingLineForPath($remotePath);

            if ($line === false) {
                throw new RuntimeException(
                    'Keine Dateiliste fuer filesize erhalten.'
                );
            }

            $size = $this->parseListingSize($line);
            if ($size === false) {
                throw new RuntimeException(
                    'Die Dateigroesse aus der sftp-Ausgabe konnte nicht gelesen werden.'
                );
            }

            return $size;
        } catch (RuntimeException $exception) {
            return $this->handleError($exception);
        }
    }
    /** @return bool */
    public function delete($remotePath)
    {
        try {
            $this->requireLogin();
            $remotePath = $this->resolveRemotePath($remotePath);
            $this->runBatch(array(
                'rm ' . escapeshellarg((string) $remotePath)
            ));

            if ($this->file_exists($remotePath)) {
                throw new RuntimeException(
                    'Die entfernte Datei konnte nicht geloescht werden: '
                        . $remotePath
                );
            }

            return true;
        } catch (RuntimeException $exception) {
            return $this->handleError($exception);
        }
    }

    /**
     * Erstellt ein entferntes Verzeichnis.
     *
     * @param string $remoteDirectory
     * @param int    $mode Wird aus Kompatibilitaetsgruenden akzeptiert.
     * @param bool   $recursive
     * @return bool
     */
    /**
     * Benennt eine entfernte Datei oder ein entferntes Verzeichnis um.
     *
     * @return bool
     */
    public function rename($oldRemotePath, $newRemotePath)
    {
        try {
            $this->requireLogin();
            $oldRemotePath = $this->resolveRemotePath($oldRemotePath);
            $newRemotePath = $this->resolveRemotePath($newRemotePath);

            $this->runBatch(array(
                'rename ' . escapeshellarg($oldRemotePath) . ' '
                    . escapeshellarg($newRemotePath)
            ));

            if ($this->file_exists($oldRemotePath)
                || !$this->file_exists($newRemotePath)) {
                throw new RuntimeException(
                    'Der entfernte Pfad konnte nicht umbenannt werden: '
                        . $oldRemotePath . ' -> ' . $newRemotePath
                );
            }

            return true;
        } catch (RuntimeException $exception) {
            return $this->handleError($exception);
        }
    }
    public function mkdir($remoteDirectory, $mode = -1, $recursive = false)
    {
        try {
            $this->requireLogin();
            $remoteDirectory = $this->resolveRemotePath($remoteDirectory);

            $command = 'mkdir ';
            if ($recursive) {
                $command .= '-p ';
            }
            $command .= escapeshellarg((string) $remoteDirectory);

            $this->runBatch(array($command));

            if (!$this->is_dir($remoteDirectory)) {
                throw new RuntimeException(
                    'Das entfernte Verzeichnis wurde nicht angelegt: '
                        . $remoteDirectory
                );
            }

            return true;
        } catch (RuntimeException $exception) {
            return $this->handleError($exception);
        }
    }

    /**
     * Loescht ein leeres entferntes Verzeichnis.
     *
     * @return bool
     */
    public function rmdir($remoteDirectory)
    {
        try {
            $this->requireLogin();
            $remoteDirectory = $this->resolveRemotePath($remoteDirectory);
            $this->runBatch(array(
                'rmdir ' . escapeshellarg((string) $remoteDirectory)
            ));

            if ($this->is_dir($remoteDirectory)) {
                throw new RuntimeException(
                    'Das entfernte Verzeichnis konnte nicht geloescht werden: '
                        . $remoteDirectory
                );
            }

            return true;
        } catch (RuntimeException $exception) {
            return $this->handleError($exception);
        }
    }
    protected function extractRemotePwd($output)
    {
        $lines = preg_split('/' . chr(13) . chr(10) . '|' . chr(13) . '|' . chr(10) . '/', (string) $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^Remote working directory:[[:space:]]*(.+)$/', $line, $matches)) {
                return $this->normalizeRemotePath($matches[1]);
            }
        }

        return false;
    }

    protected function resolveRemotePath($remotePath)
    {
        $remotePath = (string) $remotePath;
        if ($remotePath === '') {
            $remotePath = '.';
        }

        if ($remotePath[0] !== '/') {
            $base = $this->currentDirectory;
            if ($base !== '' && $base !== '.') {
                $remotePath = rtrim($base, '/') . '/' . $remotePath;
            }
        }

        return $this->normalizeRemotePath($remotePath);
    }

    protected function normalizeRemotePath($remotePath)
    {
        $remotePath = (string) $remotePath;
        $absolute = $remotePath !== '' && $remotePath[0] === '/';
        $parts = explode('/', $remotePath);
        $normalized = array();

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if (count($normalized) > 0
                    && end($normalized) !== '..') {
                    array_pop($normalized);
                } elseif (!$absolute) {
                    $normalized[] = '..';
                }
                continue;
            }

            $normalized[] = $part;
        }

        $result = implode('/', $normalized);
        if ($absolute) {
            return $result === '' ? '/' : '/' . $result;
        }

        return $result === '' ? '.' : $result;
    }
    protected function requireLogin()
    {
        if ($this->username === null || $this->credential === null) {
            throw new RuntimeException('SFTPWrapper::login() wurde noch nicht erfolgreich aufgerufen.');
        }
    }

    /**
     * Startet sftp ohne den internen Batch-Schalter und streamt die
     * Befehle direkt ueber stdin. Das ist erforderlich, damit sshpass
     * die Passwortabfrage bedienen kann.
     * Das Passwort wird bewusst direkt als sshpass-Kommandozeilenargument
     * uebergeben, damit es auch aus PHP-/Webserver-Prozessen funktioniert.
     *
     * @param array $commands
     * @return string
     */
    protected function runBatch($commands)
    {
        $this->requireLogin();
        $this->ensureProcess();

        $output = '';
        foreach ($commands as $command) {
            $marker = '__SFTP_WRAPPER_DONE_' . uniqid('', true) . '__';
            $payload = (string) $command . PHP_EOL
                . '!printf ' . escapeshellarg($marker) . PHP_EOL;

            if (!is_resource($this->stdin)
                || fwrite($this->stdin, $payload) === false) {
                $this->closeProcess(false);
                throw new RuntimeException(
                    'Der SFTP-Befehl konnte nicht an den Prozess gesendet werden.'
                );
            }

            $output .= $this->readUntilMarker($marker);
        }

        return $output;
    }
    protected function ensureProcess()
    {
        if ($this->connected
            && is_resource($this->process)
            && proc_get_status($this->process)['running']) {
            return;
        }

        if ($this->connected || is_resource($this->process)) {
            $this->closeProcess(false);
        }

        /* Der interne Abschlussmarker wird ueber sftp's !-Kommando ausgefuehrt. */
        /* www-data kann als Login-Shell /usr/sbin/nologin haben. */
        $command = 'SHELL=/bin/sh ';
        if ($this->credentialType === 'password'
            || $this->credentialType === 'password+key') {
            $command .= escapeshellarg($this->sshpassBinary)
                . ' -p ' . escapeshellarg($this->credential) . ' ';
        }

        $command .= escapeshellarg($this->sftpBinary)
            . ' -P ' . escapeshellarg((string) $this->port)
            . ' -o ' . escapeshellarg('StrictHostKeyChecking=' . $this->strictHostKeyChecking)
            . ' -o ' . escapeshellarg('UserKnownHostsFile=' . $this->userKnownHostsFile)
            . ' -o ' . escapeshellarg('BatchMode=no');

        if ($this->credentialType === 'password') {
            $command .= ' -o ' . escapeshellarg('PreferredAuthentications=password')
                . ' -o ' . escapeshellarg('PubkeyAuthentication=no');
        }

        if ($this->credentialType === 'key') {
            $command .= ' -i ' . escapeshellarg($this->credential);
        } elseif ($this->credentialType === 'password+key') {
            $command .= ' -i ' . escapeshellarg($this->keyPath);
        }

        $command .= ' ' . escapeshellarg($this->username)
            . '@' . escapeshellarg($this->host);

        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w')
        );
        $pipes = array();
        $this->process = proc_open($command, $descriptors, $pipes, null, null);

        if (!is_resource($this->process)) {
            $this->process = null;
            throw new RuntimeException(
                'Der native sftp-Prozess konnte nicht gestartet werden.'
            );
        }

        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];
        $status = proc_get_status($this->process);
        $this->processId = isset($status['pid']) ? $status['pid'] : null;
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);

        $this->connected = true;
    }

    protected function readUntilMarker($marker)
    {
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 30;

        while (true) {
            $read = array();
            if (is_resource($this->stdout)) {
                $read[] = $this->stdout;
            }
            if (is_resource($this->stderr)) {
                $read[] = $this->stderr;
            }

            if (count($read) === 0) {
                break;
            }

            $write = array();
            $except = array();
            $changed = stream_select($read, $write, $except, 1);

            if ($changed === false) {
                $this->closeProcess(false);
                throw new RuntimeException(
                    'Die Ausgabe des SFTP-Prozesses konnte nicht gelesen werden.'
                );
            }

            if ($changed > 0) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }

                    if ($stream === $this->stderr) {
                        $stderr .= $chunk;
                    } else {
                        $stdout .= $chunk;
                    }
                }
            }

            if (strpos($stdout, $marker) !== false
                || strpos($stderr, $marker) !== false) {
                return str_replace(
                    $marker,
                    '',
                    $stdout . PHP_EOL . $stderr
                );
            }

            $status = is_resource($this->process)
                ? proc_get_status($this->process)
                : array('running' => false);

            if (!$status['running']) {
                break;
            }

            if (microtime(true) > $deadline) {
                $this->closeProcess(false);
                throw new RuntimeException(
                    'Timeout beim Warten auf die SFTP-Antwort.'
                );
            }
        }

        $this->closeProcess(false);
        $message = trim($stderr);
        if ($message === '') {
            $message = trim($stdout);
        }
        if ($message === '') {
            $message = 'Der SFTP-Prozess wurde unerwartet beendet.';
        }

        throw new RuntimeException($message);
    }
    protected function closeProcess($sendBye)
    {
        if (!is_resource($this->process)) {
            $this->connected = false;
            return;
        }

        if ($sendBye && is_resource($this->stdin)) {
            @fwrite($this->stdin, 'bye' . PHP_EOL);
        }

        if (is_resource($this->stdin)) {
            @fclose($this->stdin);
        }
        if (is_resource($this->stdout)) {
            @fclose($this->stdout);
        }
        if (is_resource($this->stderr)) {
            @fclose($this->stderr);
        }

        @proc_close($this->process);
        $this->process = null;
        $this->stdin = null;
        $this->stdout = null;
        $this->stderr = null;
        $this->processId = null;
        $this->connected = false;
    }
    protected function processEnvironment()
    {
        $environment = array();
        $path = getenv('PATH');

        if ($path !== false) {
            $environment['PATH'] = $path;
        }

        return $environment;
    }

    protected function outputLines($output)
    {
        $result = array();
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $output));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, 'sftp>') === 0) {
                continue;
            }
            $result[] = $line;
        }

        return $result;
    }

    /**
     * Liefert den parent-Pfad und den Namen eines entfernten Pfads.
     *
     * @return array
     */
    protected function splitRemotePath($remotePath)
    {
        $path = rtrim((string) $remotePath, '/');

        if ($path === '') {
            return array('/', '');
        }

        $separator = strrpos($path, '/');
        if ($separator === false) {
            return array('.', $path);
        }

        $parent = substr($path, 0, $separator);
        if ($parent === '') {
            $parent = '/';
        }

        return array($parent, substr($path, $separator + 1));
    }

    /**
     * Liest den passenden ls-Eintrag fuer einen einzelnen entfernten Pfad.
     * Der verwendete SFTP-Client kennt ls -d nicht, daher wird das
     * uebergeordnete Verzeichnis gelistet.
     *
     * @return string|false
     */
    protected function findListingLineForPath($remotePath)
    {
        $remotePath = $this->resolveRemotePath($remotePath);
        $parts = $this->splitRemotePath($remotePath);
        $parent = $parts[0];
        $name = $parts[1];

        if ($name === '') {
            return false;
        }

        $output = $this->runBatch(array(
            'ls -l ' . escapeshellarg($parent)
        ));

        return $this->findListingLineByName($output, $name);
    }

    protected function findListingLineByName($output, $name)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $output);

        foreach ($lines as $line) {
            $line = trim($line);

            if (strpos($line, 'sftp>') === 0) {
                continue;
            }

            if (!preg_match('/^[-bcdlps?][-rwxstT?]{9}\s+/', $line)) {
                continue;
            }

            /* Bei symbolischen Links steht hinter dem Dateinamen noch "->". */
            $lineWithoutLinkTarget = preg_replace(
                '/\s+->\s+.*$/',
                '',
                $line
            );
            $position = strrpos($lineWithoutLinkTarget, (string) $name);

            if ($position === false) {
                continue;
            }

            $endPosition = $position + strlen((string) $name);
            if ($endPosition !== strlen($lineWithoutLinkTarget)) {
                continue;
            }

            if ($position === 0
                || $lineWithoutLinkTarget[$position - 1] === '/'
                || ctype_space($lineWithoutLinkTarget[$position - 1])) {
                return $line;
            }
        }

        return false;
    }
    protected function findListingLine($output)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, 'sftp>') === 0) {
                continue;
            }

            if (preg_match('/^[-bcdlps?][-rwxstT?]{9}\s+/', $line)) {
                return $line;
            }
        }

        return false;
    }

    protected function parseListingSize($line)
    {
        if (preg_match(
            '/^[-bcdlps?][-rwxstT?]{9}[[:space:]]+[0-9]+[[:space:]]+[[:graph:]]+[[:space:]]+[[:graph:]]+[[:space:]]+([0-9]+)[[:space:]]+/',
            trim((string) $line),
            $matches
        )) {
            return (int) $matches[1];
        }

        return false;
    }
    protected function parseListingTime($output)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $output);
        $months = 'Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec';

        foreach ($lines as $line) {
            $line = trim($line);

            if (!preg_match(
                '/\b(' . $months . ')\s+(\d{1,2})\s+((?:\d{1,2}:\d{2})|(?:\d{4}))\b/',
                $line,
                $matches
            )) {
                continue;
            }

            $month = $matches[1];
            $day = $matches[2];
            $yearOrTime = $matches[3];

            if (strpos($yearOrTime, ':') !== false) {
                $year = date('Y');
                $timestamp = strtotime(
                    $month . ' ' . $day . ' ' . $yearOrTime . ' ' . $year
                );

                if ($timestamp !== false && $timestamp > time() + 86400) {
                    $timestamp = strtotime(
                        $month . ' ' . $day . ' ' . $yearOrTime . ' ' . ($year - 1)
                    );
                }
            } else {
                $timestamp = strtotime($month . ' ' . $day . ' ' . $yearOrTime);
            }

            if ($timestamp !== false) {
                return (int) $timestamp;
            }
        }

        return false;
    }

    protected function handleError($exception)
    {
        error_log($exception->getMessage());

        if ($this->throwExceptions) {
            throw $exception;
        }

        return false;
    }
}






























