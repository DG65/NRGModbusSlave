# NRGModbusSlave — Hinweise für die Arbeit an diesem Repository

## Rolle im NRG-Stack

**Export-Endpunkt**: macht IPS zum Modbus-TCP-Slave (Server), damit externe
Master (EMS-/SCADA-/Leitsysteme, Direktvermarkter) IPS-Variablen lesen und
schreiben können. Kein `*_GetFunctions`-Vertrag — die Kompatibilitätsgröße
ist die Registertabelle. Die blue'Log-RPC-Emulation ist der
**Direktvermarktungs-Andockpunkt** des Verbunds; künftig Quelle für
`EMS_GetSpecialEvents` (`source: 'marketer'`).

## Technische Eckpunkte

- Hängt unter einer Server-Socket-Instanz; mehrere gleichzeitige Clients pro
  Port, eigener Empfangspuffer je Verbindung (TCP-Fragmentierung behandelt).
- FC 03/04/06/16; uint16/int16/uint32/int32/float32/float64; Word-Order
  ABCD/CDAB umschaltbar. Coils/Discrete Inputs (FC 01/02/05/15) bewusst nicht.
- Vorlagen: Meteocontrol blue'Log RPC (Steuer-Register 5000/5002–5006/5008
  verwaltet das Modul INTERN, nicht in der Registertabelle), SunSpec WR
  dreiphasig (Model 1+113), SunSpec Zähler dreiphasig (Model 1+213).
- Schreiben: `RequestAction` wenn die Zielvariable eine Aktion hat, sonst
  `SetValue`.

## Tests

Der Protokollkern `libs/ModbusServer.php` ist IPS-frei und CLI-testbar:
`php tests/codec_test.php`. Bei jeder Änderung am Codec laufen lassen.
Manueller Gegentest: `modpoll` (Beispiele im README).

## Branch-Modell

Arbeitsbranch `ems-integration` (verbundweit identisch), Merge nach
`beta`/`main` erst nach Bewährung. Nutzersichtbares deutsch.

## Verbund-Manifest SUITE.md — Bezugsquelle (geändert 31.08.2026)

SUITE.md liegt seit 31.08.2026 NICHT mehr in einem GitHub-Repo (die
Modul-Repos sind öffentlich, SUITE.md enthält das komplette Architektur-/
Debugging-Know-how des Verbunds — Dietmars Entscheidung). Primärquelle ist
ausschließlich die lokale Datei `/Users/dietmar/Nextcloud/Claude/SUITE.md`
auf Dietmars Maschine, versioniert in einem eigenen lokalen Git-Repo ohne
Remote. Frühere Kopien dieses Dokuments wurden zusätzlich aus der Historie
aller Modul-Repos entfernt (`git filter-repo` + Force-Push). Kein
Fallback-Link mehr — ohne lokalen Zugriff auf Dietmars Maschine ist SUITE.md
nicht einsehbar.
