<?php

/**
 * Testscript fuer SFTPWrapper unter PHP 5.6.
 *
 * Vor dem Start bitte die Zugangsdaten und den Zielpfad ausfuellen.
 * Das Script legt nur dann /test an, wenn dieses Verzeichnis noch nicht
 * existiert. So werden keine vorhandenen Daten versehentlich geloescht.
 */

require_once dirname(__FILE__) . '/SFTPWrapper.php';

/* =========================================================================
 * 1. Konfiguration
 * ========================================================================= */

$sftpHost = 'SFTP_HOST_EINTRAGEN';
$sftpPort = 22;
$sftpUser = 'SFTP_BENUTZER_EINTRAGEN';
$sftpPassword = 'SFTP_PASSWORT_EINTRAGEN';

/*
 * Verzeichnis, dessen Inhalt zu Beginn und nach dem Upload aufgelistet wird.
 * Beispiel: '/' oder '/FAHRZEUGDATEN'
 */
$remoteBasePath = '/';

/* Name und Inhalt der lokalen Testdatei. */
$localTestFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'sftp_wrapper_test.txt';
$localTestContent = 'SFTPWrapper-Test vom ' . date('Y-m-d H:i:s') . PHP_EOL;

/* Das Testverzeichnis wird unterhalb des konfigurierten Basispfads angelegt. */
$remoteTestDirectory = rtrim($remoteBasePath, '/') . '/test';
$remoteTestFile = $remoteTestDirectory . '/sftp_wrapper_test.txt';

/* =========================================================================
 * Hilfsfunktionen
 * ========================================================================= */

function testLog($message)
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function testFail($message)
{
    throw new RuntimeException($message);
}

function remoteJoin($directory, $name)
{
    return rtrim($directory, '/') . '/' . ltrim($name, '/');
}

function formatRemoteTime($timestamp)
{
    if ($timestamp === false) {
        return 'nicht verfuegbar';
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function outputRemoteListing($connection, $directory)
{
    $entries = $connection->nlist($directory);

    if ($entries === false) {
        testFail('Das Verzeichnis konnte nicht ausgelesen werden: ' . $directory);
    }

    if (count($entries) === 0) {
        testLog('  (keine Eintraege)');
        return;
    }

    foreach ($entries as $entry) {
        $path = remoteJoin($directory, $entry);
        $isDirectory = $connection->is_dir($path);
        $timestamp = $connection->filemtime($path);
        $type = $isDirectory ? 'Verzeichnis' : 'Datei';

        testLog('  ' . $type . ': ' . $entry
            . ' | File-Time: ' . formatRemoteTime($timestamp));
    }
}

/* =========================================================================
 * 2. Testablauf
 * ========================================================================= */

$connection = null;
$testDirectoryCreated = false;
$testFileUploaded = false;

try {
    testLog('SFTPWrapper-Test gestartet.');

    testLog('Schritt 1: Wrapper instanziieren.');
    $connection = new SFTPWrapper($sftpHost, $sftpPort);

    testLog('Schritt 2: Am SFTP-Server anmelden.');
    if ($connection->login($sftpUser, $sftpPassword) === false) {
        testFail('SFTP-Login fehlgeschlagen.');
    }
    testLog('  Login erfolgreich.');

    testLog('Schritt 3: Ausgangsverzeichnis auslesen: ' . $remoteBasePath);
    outputRemoteListing($connection, $remoteBasePath);

    testLog('Schritt 4: Pruefen, ob der Testname bereits existiert.');
    $baseEntries = $connection->nlist($remoteBasePath);

    if ($baseEntries !== false && in_array('test', $baseEntries, true)) {
        testFail(
            'Der Eintrag "test" existiert bereits unter '
                . $remoteBasePath
                . '. Bitte pruefen oder entfernen: '
                . $remoteTestDirectory
        );
    }

    testLog('Schritt 5: Testverzeichnis anlegen: ' . $remoteTestDirectory);
    if ($connection->mkdir($remoteTestDirectory) === false) {
        testFail('Das Testverzeichnis konnte nicht angelegt werden.');
    }
    $testDirectoryCreated = true;

    testLog('Schritt 6: Lokale Testdatei erstellen: ' . $localTestFile);
    if (file_put_contents($localTestFile, $localTestContent) === false) {
        testFail('Die lokale Testdatei konnte nicht erstellt werden.');
    }

    testLog('Schritt 7: Testdatei hochladen: ' . $remoteTestFile);
    if ($connection->put($remoteTestFile, $localTestFile) === false) {
        testFail('Die Testdatei konnte nicht hochgeladen werden.');
    }
    $testFileUploaded = true;

    testLog('Schritt 8: Testverzeichnis nach dem Upload auslesen.');
    outputRemoteListing($connection, $remoteTestDirectory);

    testLog('Schritt 9: File-Time der Testdatei ausgeben.');
    $uploadedFileTime = $connection->filemtime($remoteTestFile);
    if ($uploadedFileTime === false) {
        testFail('Die File-Time der hochgeladenen Datei konnte nicht gelesen werden.');
    }
    testLog('  ' . $remoteTestFile . ' | File-Time: '
        . formatRemoteTime($uploadedFileTime));

    testLog('Schritt 10: Testdatei loeschen.');
    if ($connection->delete($remoteTestFile) === false) {
        testFail('Die Testdatei konnte nicht geloescht werden.');
    }
    $testFileUploaded = false;

    testLog('Schritt 11: File-Time des Testverzeichnisses vor dem Loeschen ausgeben.');
    $testDirectoryTime = $connection->filemtime($remoteTestDirectory);
    testLog('  ' . $remoteTestDirectory . ' | File-Time: '
        . formatRemoteTime($testDirectoryTime));

    testLog('Schritt 12: Testverzeichnis loeschen.');
    if ($connection->rmdir($remoteTestDirectory) === false) {
        testFail('Das Testverzeichnis konnte nicht geloescht werden.');
    }
    $testDirectoryCreated = false;

    testLog('SFTPWrapper-Test erfolgreich abgeschlossen.');
} catch (Exception $exception) {
    testLog('FEHLER: ' . $exception->getMessage());

    /*
     * Aufraeumen, falls der Test nach dem Anlegen des Testobjekts
     * abgebrochen wurde.
     */
    if ($connection !== null) {
        if ($testFileUploaded) {
            $connection->delete($remoteTestFile);
        }
        if ($testDirectoryCreated) {
            $connection->rmdir($remoteTestDirectory);
        }
    }

    exit(1);
} finally {
    if (is_file($localTestFile)) {
        unlink($localTestFile);
    }
}


