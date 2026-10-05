# SFTPWrapper für PHP 5.6

SFTPWrapper ist eine Übergangslösung für ältere PHP-5.6-Anwendungen, die per SFTP auf moderne Server zugreifen müssen.

## Warum ist der Wrapper notwendig?

Ältere PHP-5.6-Installationen verwenden häufig veraltete OpenSSL-Versionen oder ältere Versionen von phpseclib beziehungsweise der ssh2-Erweiterung. Wenn der SFTP-Server aktualisierte SSH-Key-Exchange-Algorithmen (KEX) erzwingt, schlägt die Verbindung dann beispielsweise mit folgender Meldung fehl:

~~~text
No compatible key exchange algorithms found
~~~

Auf Betriebssystemebene ist der native OpenSSH-Client häufig bereits aktuell und kann die Verbindung weiterhin herstellen. Der Wrapper bildet deshalb eine kleine phpseclib-ähnliche API nach und verwendet im Hintergrund den nativen OpenSSH-Client sftp.

Der Wrapper ist als zeitlich begrenzte Brücke für Legacy-Code gedacht, bis die betroffenen Anwendungen auf eine aktuelle PHP- und SFTP-Lösung portiert sind.

## Voraussetzungen

Auf dem ausführenden Linux-Server müssen vorhanden und erlaubt sein:

- PHP 5.6
- proc_open() und die Standardfunktionen für Prozess- und Stream-Verarbeitung
- der native OpenSSH-Client sftp
- sshpass für Passwort-Authentifizierung
- ausgehende TCP-Verbindung zum SFTP-Server, üblicherweise Port 22
- ausreichende Rechte für den PHP-Prozess, zum Beispiel www-data

Es werden keine Composer-Pakete und keine zusätzliche PHP-Erweiterung benötigt.

## Installation

Die Klasse kann direkt eingebunden werden:

~~~php
require_once '/pfad/zum/SFTPWrapper.php';
~~~

SFTPWrapper.php benötigt keine Autoloading-Struktur.

## Einfaches Beispiel

~~~php
require_once '/pfad/zum/SFTPWrapper.php';

$connection = new SFTPWrapper('sftp.example.com', 22);

if ($connection->login('mein-benutzer', 'mein-passwort') === false) {
    throw new RuntimeException('SFTP-Login fehlgeschlagen.');
}

if ($connection->put(
    '/remote/verzeichnis/datei.txt',
    '/tmp/datei.txt'
) === false) {
    throw new RuntimeException('Upload fehlgeschlagen.');
}

$connection->disconnect();
~~~

Die Verbindung bleibt nach login() bestehen. Dadurch können mehrere Operationen ohne erneuten SSH-Handshake ausgeführt werden. disconnect() ist optional, wird aber für einen expliziten Verbindungsabschluss empfohlen. Zusätzlich räumt der Destruktor die Verbindung auf.

## SSH-Key-Authentifizierung

Ein unverschlüsselter SSH-Key kann als zweiter Parameter übergeben werden:

~~~php
$connection = new SFTPWrapper('sftp.example.com', 22);

if ($connection->login(
    'mein-benutzer',
    '/home/www-data/.ssh/id_rsa'
) === false) {
    throw new RuntimeException('SFTP-Login fehlgeschlagen.');
}
~~~

Ein Passwort-geschützter Key wird von dieser Übergangslösung nicht interaktiv abgefragt. Für diesen Fall muss die Klasse gegebenenfalls erweitert werden.

## Passwort-Authentifizierung

Für Passwort-Authentifizierung verwendet die Klasse sshpass:

~~~php
$connection->login('mein-benutzer', 'mein-passwort');
~~~

Das Passwort wird dabei als Argument an sshpass übergeben. Dadurch kann es unter Umständen in der Prozessliste sichtbar sein. Diese Variante sollte deshalb nur als Übergangslösung verwendet werden. SSH-Keys sind vorzuziehen.

## Unterstützte Methoden

| Methode | Beschreibung |
|---|---|
| login($user, $passwordOrKeyPath, $keyPath = null) | Anmeldung per Passwort, Key oder Passwort plus Key |
| disconnect() | Persistente SFTP-Verbindung explizit schließen |
| pwd() | Aktuelles entferntes Arbeitsverzeichnis zurückgeben |
| chdir($path) | Entferntes Arbeitsverzeichnis wechseln |
| nlist($path) | Datei- und Verzeichnisnamen eines Verzeichnisses liefern |
| is_dir($path) | Prüfen, ob ein Pfad ein Verzeichnis ist |
| is_file($path) | Prüfen, ob ein Pfad eine Datei ist |
| file_exists($path) | Prüfen, ob ein Pfad vorhanden ist |
| filemtime($path) | Änderungszeitpunkt als Unix-Timestamp liefern |
| filesize($path) | Dateigröße in Bytes liefern |
| put($remoteFile, $localFile) | Lokale Datei hochladen |
| get($remoteFile, $localFile) | Entfernte Datei herunterladen |
| delete($path) | Entfernte Datei löschen |
| mkdir($path, $mode = -1, $recursive = false) | Entferntes Verzeichnis anlegen |
| rmdir($path) | Leeres entferntes Verzeichnis löschen |
| rename($oldPath, $newPath) | Entfernten Datei- oder Verzeichnispfad umbenennen |

Die Methodennamen und die grundlegenden Parameter orientieren sich an PHPSecLib, damit bestehender Legacy-Code möglichst wenig angepasst werden muss.

## Arbeitsverzeichnisse und relative Pfade

nlist() ändert das Arbeitsverzeichnis nicht. Dafür ist chdir() vorgesehen:

~~~php
$connection->chdir('/remote/daten');

$files = $connection->nlist('.');
$size = $connection->filesize('bericht.csv');

$connection->chdir('..');
~~~

Absolute Pfade können jederzeit weiterhin verwendet werden.

## Konfiguration

Optionale Einstellungen können beim Erzeugen der Klasse übergeben werden:

~~~php
$connection = new SFTPWrapper(
    'sftp.example.com',
    22,
    array(
        'sftpBinary' => '/usr/bin/sftp',
        'sshpassBinary' => '/usr/bin/sshpass',
        'strictHostKeyChecking' => 'no',
        'userKnownHostsFile' => '/dev/null',
        'throwExceptions' => true
    )
);
~~~

Standardmäßig werden Host-Keys nicht dauerhaft geprüft:

~~~text
StrictHostKeyChecking=no
UserKnownHostsFile=/dev/null
~~~

Das verhindert interaktive Rückfragen beim ersten Verbindungsaufbau, ist aber sicherheitstechnisch weniger streng. Für produktive Umgebungen sollte eine echte Known-Hosts-Prüfung geprüft und gegebenenfalls konfiguriert werden.

## Testscript

Das Repository enthält test_sftp_wrapper.php. Vor der Ausführung müssen die Konfigurationswerte am Anfang der Datei eingetragen werden:

~~~php
$sftpHost = 'SFTP_HOST_EINTRAGEN';
$sftpPort = 22;
$sftpUser = 'SFTP_BENUTZER_EINTRAGEN';
$sftpPassword = 'SFTP_PASSWORT_EINTRAGEN';
$remoteBasePath = '/';
~~~

Beispielaufruf:

~~~bash
sudo -u www-data php -f test_sftp_wrapper.php
~~~

Das Script prüft Anmeldung, Verzeichnislisten, Änderungszeiten, Verzeichniserstellung, Upload, Dateioperationen und das anschließende Aufräumen.

## Einschränkungen

- Die Klasse ist für PHP 5.6 und Legacy-Code ausgelegt.
- Es wird ausschließlich SFTP verwendet, nicht SCP.
- Die Verbindung ist nur innerhalb desselben PHP-Prozesses persistent.
- Der Wrapper ist kein vollständiger Ersatz für PHPSecLib.
- Die Ausgabe des nativen sftp-Clients muss teilweise geparst werden. Je nach OpenSSH-Version und Serverausgabe können zusätzliche Anpassungen nötig sein.
- Passwort-Authentifizierung mit sshpass ist aus Sicherheitsgründen nur als Übergangslösung geeignet.
- Host-Key-Prüfung ist standardmäßig deaktiviert und sollte für streng abgesicherte Umgebungen angepasst werden.

## Lizenz

Es ist derzeit keine Lizenz festgelegt. Vor der Veröffentlichung sollte eine passende Open-Source-Lizenz ergänzt werden.
