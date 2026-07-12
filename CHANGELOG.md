# Changelog

## 1.2.1 (2026-07-12)

- RPC-Aktivierung ins Vorlagen-Popup integriert: Die RPC-Vorlage aktiviert die Schnittstelle und blendet das Einstellungs-Panel "Meteocontrol RPC / Direktvermarktung" ein - solange RPC nicht aktiviert ist, wird das Panel gar nicht angezeigt
- Kopfzeile wieder einzeilig, nutzt jetzt die volle Formularbreite mit größeren Abständen zwischen den Feldern

## 1.2.0 (2026-07-12)

- Neu: SunSpec-Vorlage (Common Model 1 + Wechselrichter Model 113, float32, Basis 40000)
- Vorlagen jetzt über Popup-Button "Vorlage laden..." wählbar (setzt auch die passende Word-Order)
- Dynamisches Formular: RPC-Einstellungen werden nur bei aktivierter RPC-Schnittstelle angezeigt; Hinweis ergänzt, dass die Steuer-Register 5000/5006/5008 intern verwaltet werden und nicht in der Tabelle erscheinen
- Formular aufgelockert (zwei Zeilen statt einer) und Option "Antwort beim Lesen unbelegter Register" verständlicher beschriftet und in der Doku erklärt

## 1.1.0 (2026-07-12)

- Neu: Formular-Aktion "Weitere Schnittstellen anlegen" - erzeugt pro angegebenem Port (z. B. "501-505") eine Kopie der Instanz samt Server Socket; bereits belegte Ports werden übersprungen. Damit lassen sich Mehrfach-Anbindungen (z. B. Solarpark mit fünf Gegenstellen auf Ports 501-505) aus einer fertig konfigurierten Instanz heraus aufbauen

## 1.0.2 (2026-07-12)

- Port des Server Sockets nicht mehr durch das Modul erzwungen (GetConfigurationForParent entfernt) - der Port ist jetzt am Socket frei einstellbar, z. B. für mehrere Slave-Instanzen auf unterschiedlichen Ports

## 1.0.1 (2026-07-12)

- Texte und Standardwerte neutralisiert: das Modul präsentiert sich als generischer Modbus-TCP-Slave, die blue'Log-RPC-Emulation ist eine optionale Vorlage (Defaults jetzt Unit-ID 1 und Word-Order ABCD; die RPC-Vorlage setzt weiterhin Unit-ID 10 und CDAB)

## 1.0.0 (2026-07-12)

- Erstversion: generisches Modbus-TCP-Slave-Modul (FC 03/04/06/16, uint16/int16/uint32/int32/float32/float64, umschaltbare Word-Reihenfolge, frei konfigurierbare Registertabelle mit Variablen-Mapping, Faktor und Festwerten)
- Meteocontrol blue'Log XM/XC RPC-Emulation für die Direktvermarktung: Register 5000/5002–5005/5006/5008 mit Gültigkeits- und Watchdog-Logik, Rückfall-Sollwert, Registervorlage für die Istwert-Register per Formular-Button
- IPS-freier, CLI-testbarer Protokollkern (tests/codec_test.php)
