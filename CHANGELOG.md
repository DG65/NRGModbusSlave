# Changelog

## 1.6.2 (2026-07-29)

- Verbund-Namenskonvention: sichtbarer Bibliotheksname jetzt "NRG-Stack ModbusSlave", Instanzsuche findet zusätzlich "NRG-Stack Modbus TCP Slave" (nur Anzeigenamen - GUID, PHP-Klassenname und Idents unverändert, bestehende Instanzen und Git-Updates bleiben unberührt)

## 1.6.1 (2026-07-27)

- Usability (Verbund-Audit): Immer sichtbare Verbindungszeile oben im Formular ("Erreichbar auf Port X" bzw. konkrete Anleitung, wenn der Server Socket nicht aktiv ist) - der Port lag bisher unauffindbar nur im eingeklappten Doku-Panel
- RPC-Panel stellt jetzt klar, dass KEIN echtes blue'Log-Gerät benötigt wird (IPS übernimmt dessen Rolle; funktioniert mit jedem Direktvermarkter, der die blue'Log-RPC-Schnittstelle unterstützt)
- Sichtbar dokumentiert, welche Register-Zuordnungen manuell erfolgen (Istwerte) und welche automatisch (Sollwert-Register über den Vorlage-Button) - stand bisher nur in einer flüchtigen Meldung nach dem Button-Klick

## 1.6.0 (2026-07-27)

- Neue Vorlage: SunSpec Zähler dreiphasig (Common Model 1 + Model 213, float32) - IPS kann damit gegenüber Wallbox-/EMS-Systemen (z. B. evcc, openWB) als SunSpec-Netzzähler auftreten. Registerlayout generiert aus der offiziellen SunSpec-Modelldefinition (github.com/sunspec/models)

## 1.5.0 (2026-07-27)

- Statusampel (Verbund-Zielbild "Zuverlässigkeit ohne KI-Krücke"): Instanzstatus spiegelt jetzt sichtbar den Betriebszustand - Fehlerstatus 201 bei inaktivem Server Socket, Fehlerstatus 202 wenn innerhalb der konfigurierbaren Kommunikationsüberwachung (Minuten, 0 = aus) keine Modbus-Anfrage eintraf ("Master pollt nicht mehr")
- Neue Variable "Letzte Modbus-Anfrage" (Zeitstempel, max. alle 5 s aktualisiert) als Lebenszeichen ohne Log-Zugriff

## 1.4.1 (2026-07-26)

- Härtung gemäß Verbund-Erkenntnis (SUITE.md-Stolperstein 3): RegisterVariableXXX wird nur noch bei echter Neuanlage aufgerufen (Guard über GetIDForIdent), sowohl in ApplyChanges (RPC-Variablen) als auch im Datenpunkte-Button

## 1.4.0 (2026-07-25)

- Verbund-Umbenennung mit NRG-Präfix: Bibliothek heißt jetzt "NRGModbusSlave", Repo-URL auf github.com/DG65/NRGModbusSlave aktualisiert. Der Modulname in module.json bleibt "ModbusTCPSlave" (= PHP-Klassenname, von IPS per Reflection gesucht - Umbenennung würde das Modul zerschossen); "NRGModbusTCPSlave" ist als Alias suchbar. GUID, Prefix (MBSLV) und Variablen-Idents unverändert

## 1.3.0 (2026-07-12)

- Neu: Button "Datenpunkte für unzugeordnete Register anlegen" - legt für alle Tabellenzeilen ohne zugeordnete Variable einen Datenpunkt unter der Instanz an (float für float32/64, sonst integer) und trägt ihn direkt in die Tabelle ein. Zeilen mit Festwert (Header/Konstanten, z. B. SunSpec-Modell-IDs) bleiben unangetastet; wiederholtes Ausführen verwendet vorhandene Datenpunkte wieder

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
